<?php

namespace App\Jobs;

use App\Mail\StoreEmailCampaignMail;
use App\Models\Store;
use App\Models\StoreEmailCampaign;
use App\Models\StoreEmailCampaignRecipient;
use App\Services\Marketing\EmailCampaignSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one batch of a campaign's recipients. Each recipient is claimed with an atomic
 * queued -> sending update before the mail is handed to the mailer, so a retried or duplicated job
 * skips everyone it (or another worker) already attempted: an address is e-mailed at most once.
 * Consent is re-checked right before sending, and the per-minute caps are enforced per message.
 */
class SendStoreEmailCampaignBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @param  list<int>  $recipientIds */
    public function __construct(public int $campaignId, public array $recipientIds)
    {
        $queue = config('sellchase.email_campaigns.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(EmailCampaignSender $sender): void
    {
        $campaign = StoreEmailCampaign::query()->withoutGlobalScopes()->find($this->campaignId);
        $store = $campaign ? Store::find($campaign->store_id) : null;
        if (! $campaign || ! $store) {
            return;
        }
        $recipients = fn () => StoreEmailCampaignRecipient::query()->withoutGlobalScopes()->where('campaign_id', $campaign->id);
        foreach ($this->recipientIds as $index => $id) {
            $status = StoreEmailCampaign::query()->withoutGlobalScopes()->whereKey($campaign->id)->value('status');
            if ($status !== 'sending' || ! EmailCampaignSender::takeSendSlot($store->id)) {
                // Cancelled, or over the per-minute cap: hand the rest back to the dispatcher.
                $recipients()->whereIn('id', array_slice($this->recipientIds, $index))->where('status', 'queued')->update(['dispatched_at' => null]);
                break;
            }
            $claimed = $recipients()->whereKey($id)->where('status', 'queued')->update(['status' => 'sending', 'sending_at' => now(), 'updated_at' => now()]);
            if ($claimed !== 1) {
                continue;
            }
            $recipient = $recipients()->whereKey($id)->firstOrFail();
            if (! self::subscribed($store->id, $recipient->email)) {
                $recipient->forceFill(['status' => 'skipped', 'error' => 'No longer subscribed.'])->save();

                continue;
            }
            try {
                Mail::to($recipient->email)->send(new StoreEmailCampaignMail($campaign, $recipient));
                $recipient->forceFill(['status' => 'sent', 'sent_at' => now(), 'error' => null])->save();
            } catch (\Throwable $e) {
                // A permanent SMTP rejection (5.5.x) is a bounce: the address is suppressed in later campaigns.
                // Provider messages can echo addresses, so only the class and code are kept.
                $code = (int) $e->getCode();
                $recipient->forceFill(['status' => $code >= 550 && $code <= 554 ? 'bounced' : 'failed',
                    'error' => mb_substr(class_basename($e).($code ? ' ('.$code.')' : ''), 0, 255)])->save();
            }
        }
        $sender->refreshCounts($campaign);
    }

    /** Still an active e-mail subscriber and not suppressed by an earlier unsubscribe or bounce. */
    public static function subscribed(int $storeId, string $email): bool
    {
        return DB::table('newsletter_subscribers')->where('store_id', $storeId)->where('email', $email)->where('is_active', true)->exists()
            && ! StoreEmailCampaignRecipient::query()->withoutGlobalScopes()->where('store_id', $storeId)->where('email', $email)
                ->whereIn('status', StoreEmailCampaignRecipient::SUPPRESSED)->exists();
    }
}
