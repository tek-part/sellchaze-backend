<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use App\Models\Store;
use App\Models\StoreCustomerProfile;
use App\Models\StoreEmailCampaign;
use App\Models\StoreEmailCampaignRecipient;
use App\Services\Marketing\EmailCampaignContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Public, login-free endpoints reached from campaign e-mails, identified only by the recipient's random
 * token: unsubscribe (page + RFC 8058 one-click POST), open pixel and click redirect. The click redirect
 * forwards only to a URL that was in the campaign's sent content, and only with a valid signature, so
 * it can never be used as an open redirect.
 */
class EmailCampaignTrackingController extends Controller
{
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /** GET email/unsubscribe/{token} — a confirmation page; a GET never unsubscribes (mail scanners prefetch links). */
    public function unsubscribePage(string $token): Response
    {
        if ($token === 'test') {
            return $this->page('test', null, null);
        }
        $recipient = $this->recipient($token);
        if (! $recipient) {
            return $this->page('invalid', null, null, 404);
        }
        [$campaign, $store] = $this->context($recipient);

        return $this->page($recipient->unsubscribed_at ? 'done' : 'confirm', $campaign, $store, 200, $token);
    }

    /**
     * POST email/unsubscribe/{token} — the page's button and the List-Unsubscribe-Post one-click request.
     * Deactivates the store e-mail subscription and records the unsubscribe on the recipient row.
     */
    public function unsubscribe(string $token): Response
    {
        if ($token === 'test') {
            return $this->page('test', null, null);
        }
        $recipient = DB::transaction(function () use ($token): ?StoreEmailCampaignRecipient {
            $recipient = StoreEmailCampaignRecipient::query()->withoutGlobalScopes()->where('token', $token)->lockForUpdate()->first();
            if (! $recipient || $recipient->unsubscribed_at) {
                return $recipient;
            }
            $recipient->forceFill(['status' => 'unsubscribed', 'unsubscribed_at' => now()])->save();
            NewsletterSubscriber::query()->withoutGlobalScopes()->where('store_id', $recipient->store_id)->where('email', $recipient->email)
                ->update(['is_active' => false, 'updated_at' => now()]);
            if ($recipient->customer_key) {
                StoreCustomerProfile::query()->withoutGlobalScopes()->where('store_id', $recipient->store_id)->where('customer_key', $recipient->customer_key)
                    ->update(['marketing_updated_at' => now(), 'marketing_source' => 'unsubscribe_link', 'marketing_updated_by' => null]);
            }

            return $recipient;
        });
        if (! $recipient) {
            return $this->page('invalid', null, null, 404);
        }
        [$campaign, $store] = $this->context($recipient);

        return $this->page('done', $campaign, $store);
    }

    /** GET email/open/{token} — 1x1 GIF; the first load marks the recipient opened. */
    public function open(string $token): Response
    {
        StoreEmailCampaignRecipient::query()->withoutGlobalScopes()->where('token', $token)->whereNull('opened_at')->whereNotNull('sent_at')
            ->update(['opened_at' => now()]);

        return response((string) base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /** GET email/click/{token}?u=…&s=… — records the click, then redirects to a URL of the sent content only. */
    public function click(Request $request, string $token): RedirectResponse|Response
    {
        $url = $request->query('u');
        $signature = $request->query('s');
        $recipient = is_string($url) && is_string($signature) && hash_equals(EmailCampaignContent::signature($token, $url), $signature)
            ? $this->recipient($token) : null;
        $campaign = $recipient ? StoreEmailCampaign::query()->withoutGlobalScopes()->find($recipient->campaign_id) : null;
        if (! $recipient || ! $campaign || ! in_array($url, $campaign->link_urls ?? [], true) || ! preg_match('#^https?://#i', (string) $url)) {
            return response('Link not found.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $row = fn () => StoreEmailCampaignRecipient::query()->withoutGlobalScopes()->whereKey($recipient->id);
        $row()->increment('clicks_count');
        $row()->whereNull('clicked_at')->update(['clicked_at' => now()]);
        $row()->whereNull('opened_at')->update(['opened_at' => now()]);

        return redirect()->away((string) $url, 302, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    private function recipient(string $token): ?StoreEmailCampaignRecipient
    {
        return StoreEmailCampaignRecipient::query()->withoutGlobalScopes()->where('token', $token)->first();
    }

    /** @return array{0: StoreEmailCampaign|null, 1: Store|null} */
    private function context(StoreEmailCampaignRecipient $recipient): array
    {
        return [StoreEmailCampaign::query()->withoutGlobalScopes()->find($recipient->campaign_id), Store::find($recipient->store_id)];
    }

    private function page(string $state, ?StoreEmailCampaign $campaign, ?Store $store, int $status = 200, ?string $token = null): Response
    {
        $ar = $campaign ? $campaign->locale === 'ar' : (($store?->default_locale ?? request()->getPreferredLanguage(['en', 'ar'])) === 'ar');

        return response()->view('email-campaigns.unsubscribe', [
            'state' => $state, 'ar' => $ar, 'store' => $store,
            'action' => $token ? route('email-campaigns.unsubscribe.confirm', ['token' => $token]) : null,
            'homeUrl' => $store ? app(EmailCampaignContent::class)->homeUrl($store) : null,
        ], $status, [
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src https: data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
        ]);
    }
}
