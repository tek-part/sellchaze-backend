<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\StoreEmailCampaignMail;
use App\Models\Coupon;
use App\Models\Store;
use App\Models\StoreEmailCampaign;
use App\Services\Commerce\StoreCustomerProfiles;
use App\Services\Marketing\EmailCampaignAudience;
use App\Services\Marketing\EmailCampaignContent;
use App\Services\Marketing\EmailCampaignSender;
use App\Services\Notifications\StoreNotificationSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Marketing > Email campaigns. Under `store.scope`/`store.own`; managers need store.orders.manage (the
 * grant that already covers customers and the newsletter list). Only a draft is editable; scheduling
 * (or "send now") hands it to {@see EmailCampaignSender}. Nothing here sends to subscribers directly:
 * the only immediate mail is a queued test to the address the merchant types.
 */
class StoreEmailCampaignsController extends Controller
{
    public function __construct(
        private readonly EmailCampaignContent $content,
        private readonly EmailCampaignAudience $audience,
        private readonly EmailCampaignSender $sender,
    ) {}

    /** GET email-campaigns */
    public function index(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(StoreEmailCampaign::STATUSES)],
            'search' => ['nullable', 'string', 'max:120'],
            'sort_by' => ['nullable', 'in:created_at,updated_at,name,scheduled_at,sent_count'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        $paginator = StoreEmailCampaign::forStore($store)
            ->when($data['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $match) => $match
                ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('subject', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->withCount(['recipients as opened_count' => fn ($q) => $q->whereNotNull('opened_at'), 'recipients as clicked_count' => fn ($q) => $q->whereNotNull('clicked_at')])
            ->orderBy($data['sort_by'] ?? 'created_at', $data['sort_direction'] ?? 'desc')->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 20));
        $counts = StoreEmailCampaign::forStore($store)->toBase()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'data' => collect($paginator->items())->map(fn (StoreEmailCampaign $campaign) => $this->serialize($campaign))->values(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(),
                'status_counts' => collect(StoreEmailCampaign::STATUSES)->mapWithKeys(fn ($status) => [$status => (int) ($counts[$status] ?? 0)])],
        ]);
    }

    /** GET email-campaigns/options — composer choices: discount codes, customer tags, sender defaults. */
    public function options(Store $store, StoreCustomerProfiles $profiles, StoreNotificationSettings $notifications): JsonResponse
    {
        $settings = $notifications->configured($store);
        $coupons = Coupon::forStore($store)->where('method', 'code')->where('is_active', true)->whereNotNull('code')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('id')->limit(200)->get(['id', 'code', 'title', 'type', 'value', 'expires_at']);

        return response()->json(['data' => [
            'coupons' => $coupons->map(fn (Coupon $coupon) => ['id' => $coupon->id, 'code' => $coupon->code, 'title' => $coupon->title,
                'type' => $coupon->type, 'value' => $coupon->value, 'expires_at' => $coupon->expires_at?->toIso8601String()])->values(),
            'tags' => array_slice($profiles->tags($store), 0, 200),
            'defaults' => ['from_name' => $settings['sender_name'] ?: $store->name, 'reply_to' => $settings['reply_to'] ?: $store->email,
                'locale' => $store->default_locale === 'ar' ? 'ar' : 'en', 'from_address' => config('mail.from.address')],
            'limits' => ['max_blocks' => EmailCampaignContent::MAX_BLOCKS, 'max_products' => EmailCampaignContent::MAX_PRODUCTS,
                'per_store_per_minute' => EmailCampaignSender::perStoreLimit()],
        ]]);
    }

    /** POST email-campaigns */
    public function store(Request $request, Store $store): JsonResponse
    {
        $data = $this->validated($request, $store, true);
        $campaign = new StoreEmailCampaign;
        $campaign->forceFill($data + ['store_id' => $store->id, 'status' => 'draft', 'created_by' => $request->user()?->id,
            'locale' => $data['locale'] ?? ($store->default_locale === 'ar' ? 'ar' : 'en'),
            'audience' => $data['audience'] ?? ['type' => 'all'], 'content' => $data['content'] ?? []])->save();

        return response()->json(['data' => $this->serialize($campaign->refresh(), true)], 201);
    }

    /** GET email-campaigns/{campaign} */
    public function show(Store $store, int $campaign): JsonResponse
    {
        return response()->json(['data' => $this->serialize($this->find($store, $campaign), true)]);
    }

    /** PUT email-campaigns/{campaign} — drafts only. */
    public function update(Request $request, Store $store, int $campaign): JsonResponse
    {
        $model = $this->find($store, $campaign);
        $this->ensureStatus($model, ['draft'], __('Only a draft campaign can be edited. Cancel it first to make changes.'));
        $model->forceFill($this->validated($request, $store, false))->save();

        return response()->json(['data' => $this->serialize($model->refresh(), true)]);
    }

    /** DELETE email-campaigns/{campaign} — never a campaign that sent (its recipients are the unsubscribe/bounce record). */
    public function destroy(Store $store, int $campaign): JsonResponse
    {
        $model = $this->find($store, $campaign);
        $this->ensureStatus($model, ['draft', 'cancelled', 'failed'], __('Only draft, cancelled or failed campaigns can be deleted.'));
        if ($model->recipients()->whereNotNull('sent_at')->exists()) {
            throw ValidationException::withMessages(['status' => __('This campaign already reached subscribers and is kept for its report.')]);
        }
        $model->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * POST email-campaigns/{campaign}/preview (or email-campaigns/preview for an unsaved campaign) —
     * rendered HTML; unsaved composer fields may be sent to preview them.
     */
    public function preview(Request $request, Store $store, ?int $campaign = null): JsonResponse
    {
        $draft = $campaign ? $this->find($store, $campaign)->replicate() : new StoreEmailCampaign(['store_id' => $store->id, 'locale' => $store->default_locale === 'ar' ? 'ar' : 'en']);
        $draft->forceFill(array_intersect_key($this->validated($request, $store, false), array_flip(['subject', 'preheader', 'content', 'locale', 'from_name'])));
        $html = view('emails.store-campaign', $this->content->render($draft, $store))->render();

        return response()->json(['data' => ['html' => $html, 'subject' => (string) $draft->subject, 'preheader' => (string) $draft->preheader]]);
    }

    /** POST email-campaigns/{campaign}/test {email} — ONE queued test message to the typed address. */
    public function test(Request $request, Store $store, int $campaign): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $model = $this->find($store, $campaign);
        $this->ensureSendable($model);
        $key = 'email-campaign-test:'.$store->id;
        if (RateLimiter::tooManyAttempts($key, 20)) {
            throw ValidationException::withMessages(['email' => __('Too many test emails. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)])]);
        }
        RateLimiter::hit($key, 3600);
        Mail::to(mb_strtolower(trim($data['email'])))->queue(new StoreEmailCampaignMail($model, null, true));

        return response()->json(['message' => 'Test email queued.', 'data' => ['email' => mb_strtolower(trim($data['email']))]], 202);
    }

    /**
     * POST email-campaigns/{campaign}/audience-count (or email-campaigns/audience-count) — eligible
     * recipients right now, optionally for an unsaved audience.
     */
    public function audienceCount(Request $request, Store $store, ?int $campaign = null): JsonResponse
    {
        $model = $campaign ? $this->find($store, $campaign) : null;
        $audience = $request->has('audience') || ! $model ? $this->audience->normalize($request->input('audience', ['type' => 'all'])) : ($model->audience ?? ['type' => 'all']);

        return response()->json(['data' => ['count' => $this->audience->count($store, $audience), 'audience' => $audience]]);
    }

    /** POST email-campaigns/{campaign}/schedule {scheduled_at|null} — null sends now (within a minute). */
    public function schedule(Request $request, Store $store, int $campaign): JsonResponse
    {
        $data = $request->validate(['scheduled_at' => ['present', 'nullable', 'date', 'after_or_equal:'.now()->subMinutes(5)->toIso8601String(), 'before:'.now()->addYear()->toIso8601String()]]);
        $model = DB::transaction(function () use ($store, $campaign, $data): StoreEmailCampaign {
            $model = StoreEmailCampaign::forStore($store)->whereKey($campaign)->lockForUpdate()->first();
            abort_if($model === null, 404, 'Campaign not found.');
            $this->ensureStatus($model, ['draft', 'scheduled'], __('Only a draft or scheduled campaign can be scheduled.'));
            $this->ensureSendable($model);
            if ($this->audience->count($store, $model->audience ?? ['type' => 'all']) === 0) {
                throw ValidationException::withMessages(['audience' => __('No subscribed customers match this audience.')]);
            }
            $at = $data['scheduled_at'] ? Carbon::parse($data['scheduled_at']) : now();
            $model->forceFill(['status' => 'scheduled', 'scheduled_at' => $at->lt(now()) ? now() : $at, 'cancelled_at' => null])->save();

            return $model;
        });

        return response()->json(['data' => $this->serialize($model->refresh(), true)]);
    }

    /** POST email-campaigns/{campaign}/cancel — stops a scheduled or sending campaign; addresses not yet sent are not e-mailed. */
    public function cancel(Store $store, int $campaign): JsonResponse
    {
        $model = DB::transaction(function () use ($store, $campaign): StoreEmailCampaign {
            $model = StoreEmailCampaign::forStore($store)->whereKey($campaign)->lockForUpdate()->first();
            abort_if($model === null, 404, 'Campaign not found.');
            $this->ensureStatus($model, ['scheduled', 'sending'], __('Only a scheduled or sending campaign can be cancelled.'));
            $model->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

            return $model;
        });
        $this->sender->refreshCounts($model);

        return response()->json(['data' => $this->serialize($model->refresh(), true)]);
    }

    /** GET email-campaigns/{campaign}/report */
    public function report(Store $store, int $campaign): JsonResponse
    {
        $model = $this->find($store, $campaign);

        return response()->json(['data' => ['campaign' => $this->serialize($model), 'metrics' => $this->sender->report($model)]]);
    }

    private function find(Store $store, int $id): StoreEmailCampaign
    {
        $campaign = StoreEmailCampaign::forStore($store)->find($id);
        abort_if($campaign === null, 404, 'Campaign not found.');

        return $campaign;
    }

    /** @param  list<string>  $statuses */
    private function ensureStatus(StoreEmailCampaign $campaign, array $statuses, string $message): void
    {
        if (! in_array($campaign->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function ensureSendable(StoreEmailCampaign $campaign): void
    {
        $errors = [];
        if (trim((string) $campaign->subject) === '') {
            $errors['subject'] = __('Add a subject line before sending.');
        }
        if (empty($campaign->content)) {
            $errors['content'] = __('Add at least one content block before sending.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Store $store, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:200'],
            'preheader' => ['sometimes', 'nullable', 'string', 'max:200'],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'reply_to' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'locale' => ['sometimes', 'in:ar,en'],
            'content' => ['sometimes', 'nullable', 'array'],
            'audience' => ['sometimes', 'nullable', 'array'],
        ]);
        foreach (['name', 'subject', 'preheader', 'from_name', 'reply_to'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string) $data[$field]));
                $data[$field] = $value === '' ? null : $value;
            }
        }
        if (array_key_exists('content', $data)) {
            $data['content'] = $this->content->normalize($store, (array) ($data['content'] ?? []));
        }
        if (array_key_exists('audience', $data)) {
            $data['audience'] = $data['audience'] === null ? ['type' => 'all'] : $this->audience->normalize($data['audience']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function serialize(StoreEmailCampaign $campaign, bool $full = false): array
    {
        $sent = (int) $campaign->sent_count;
        $row = [
            'id' => $campaign->id, 'name' => $campaign->name, 'subject' => $campaign->subject, 'preheader' => $campaign->preheader,
            'from_name' => $campaign->from_name, 'reply_to' => $campaign->reply_to, 'locale' => $campaign->locale, 'status' => $campaign->status,
            'audience' => $campaign->audience ?? ['type' => 'all'],
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(), 'started_at' => $campaign->started_at?->toIso8601String(),
            'completed_at' => $campaign->completed_at?->toIso8601String(), 'cancelled_at' => $campaign->cancelled_at?->toIso8601String(),
            'failure_reason' => $campaign->failure_reason,
            'recipients_count' => (int) $campaign->recipients_count, 'sent_count' => $sent, 'failed_count' => (int) $campaign->failed_count,
            'created_at' => $campaign->created_at?->toIso8601String(), 'updated_at' => $campaign->updated_at?->toIso8601String(),
        ];
        if (isset($campaign->opened_count)) {
            $row['opened_count'] = (int) $campaign->opened_count;
            $row['clicked_count'] = (int) $campaign->clicked_count;
            $row['open_rate'] = $sent > 0 ? round($row['opened_count'] / $sent * 100, 1) : null;
            $row['click_rate'] = $sent > 0 ? round($row['clicked_count'] / $sent * 100, 1) : null;
        }
        if ($full) {
            $row['content'] = $campaign->content ?? [];
        }

        return $row;
    }
}
