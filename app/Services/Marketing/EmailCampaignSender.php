<?php

namespace App\Services\Marketing;

use App\Jobs\SendStoreEmailCampaignBatch;
use App\Models\Store;
use App\Models\StoreEmailCampaign;
use App\Models\StoreEmailCampaignRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Drives campaign delivery; {@see run()} is called every minute by `email-campaigns:dispatch`.
 *
 *  1. Due scheduled campaigns are claimed (scheduled -> sending) and their recipients snapshotted.
 *     `link_urls` is written last, so a campaign without it has an unfinished snapshot that the next
 *     run completes (the snapshot is idempotent).
 *  2. Queued recipients are handed to {@see SendStoreEmailCampaignBatch} jobs, at most
 *     `per_store_per_minute` per store and `platform_per_minute` across the platform (a 60-second
 *     sliding window over `dispatched_at`). Jobs re-check the same caps right before each send, so a
 *     queue backlog cannot burst either.
 *  3. Stale claims are recovered: never-attempted recipients whose job vanished are dispatched again;
 *     a recipient left "sending" by a crashed worker is marked failed, never retried (no duplicates).
 *  4. A campaign with nothing left queued or sending is marked sent.
 */
class EmailCampaignSender
{
    public function __construct(
        private readonly EmailCampaignAudience $audience,
        private readonly EmailCampaignContent $content,
    ) {}

    /** @return array{started: int, dispatched: int, completed: int} */
    public function run(): array
    {
        $started = $this->startDue();
        $this->recoverStale();
        $dispatched = $this->dispatchBatches();
        $completed = $this->complete();

        return ['started' => $started, 'dispatched' => $dispatched, 'completed' => $completed];
    }

    public static function perStoreLimit(): int
    {
        return max(1, (int) config('sellchase.email_campaigns.per_store_per_minute', 60));
    }

    public static function platformLimit(): int
    {
        return max(1, (int) config('sellchase.email_campaigns.platform_per_minute', 300));
    }

    /** Take one send slot from both the store's and the platform's per-minute budget. */
    public static function takeSendSlot(int $storeId): bool
    {
        $store = 'email-campaigns:store:'.$storeId;
        $platform = 'email-campaigns:platform';
        if (RateLimiter::tooManyAttempts($store, self::perStoreLimit()) || RateLimiter::tooManyAttempts($platform, self::platformLimit())) {
            return false;
        }
        RateLimiter::hit($store, 60);
        RateLimiter::hit($platform, 60);

        return true;
    }

    public function startDue(): int
    {
        $started = 0;
        $ids = StoreEmailCampaign::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q->where('status', 'scheduled')->where('scheduled_at', '<=', now())
                ->orWhere(fn ($q) => $q->where('status', 'sending')->whereNull('link_urls')))
            ->orderBy('scheduled_at')->orderBy('id')->limit(50)->pluck('id');
        foreach ($ids as $id) {
            $campaign = DB::transaction(function () use ($id): ?StoreEmailCampaign {
                $campaign = StoreEmailCampaign::query()->withoutGlobalScopes()->whereKey($id)->lockForUpdate()->first();
                if (! $campaign || ! (($campaign->status === 'scheduled' && $campaign->scheduled_at?->lte(now())) || ($campaign->status === 'sending' && $campaign->link_urls === null))) {
                    return null;
                }
                if ($campaign->status === 'scheduled') {
                    $campaign->forceFill(['status' => 'sending', 'started_at' => now()])->save();
                }

                return $campaign;
            });
            $store = $campaign ? Store::find($campaign->store_id) : null;
            if (! $campaign || ! $store) {
                continue;
            }
            $count = $this->audience->snapshot($campaign, $store);
            $campaign->forceFill(['recipients_count' => $count, 'link_urls' => $this->content->links($campaign, $store)]);
            if ($count === 0) {
                $campaign->forceFill(['status' => 'failed', 'failure_reason' => 'no_recipients', 'completed_at' => now()]);
            }
            $campaign->save();
            $started++;
        }

        return $started;
    }

    public function recoverStale(): void
    {
        $recipients = StoreEmailCampaignRecipient::query()->withoutGlobalScopes();
        (clone $recipients)->where('status', 'queued')->whereNotNull('dispatched_at')->where('dispatched_at', '<', now()->subMinutes(15))
            ->update(['dispatched_at' => null]);
        (clone $recipients)->where('status', 'sending')->where('sending_at', '<', now()->subMinutes(30))
            ->update(['status' => 'failed', 'error' => 'Delivery outcome unknown; not retried to avoid a duplicate.', 'updated_at' => now()]);
    }

    public function dispatchBatches(): int
    {
        $window = now()->subMinute();
        $recipients = fn () => StoreEmailCampaignRecipient::query()->withoutGlobalScopes();
        $platformLeft = self::platformLimit() - $recipients()->where('dispatched_at', '>=', $window)->count();
        $batch = max(1, (int) config('sellchase.email_campaigns.batch_size', 50));
        $dispatched = 0;
        $campaigns = StoreEmailCampaign::query()->withoutGlobalScopes()->where('status', 'sending')->whereNotNull('link_urls')
            ->orderBy('started_at')->orderBy('id')->get(['id', 'store_id']);
        $storeUsed = [];
        foreach ($campaigns as $campaign) {
            if ($platformLeft <= 0) {
                break;
            }
            $storeUsed[$campaign->store_id] ??= $recipients()->where('store_id', $campaign->store_id)->where('dispatched_at', '>=', $window)->count();
            $budget = min($platformLeft, self::perStoreLimit() - $storeUsed[$campaign->store_id]);
            if ($budget <= 0) {
                continue;
            }
            $ids = $recipients()->where('campaign_id', $campaign->id)->where('status', 'queued')->whereNull('dispatched_at')
                ->orderBy('id')->limit($budget)->pluck('id');
            if ($ids->isEmpty()) {
                continue;
            }
            $recipients()->whereIn('id', $ids)->whereNull('dispatched_at')->update(['dispatched_at' => now()]);
            foreach ($ids->chunk($batch) as $chunk) {
                SendStoreEmailCampaignBatch::dispatch($campaign->id, $chunk->values()->all());
            }
            $platformLeft -= $ids->count();
            $storeUsed[$campaign->store_id] += $ids->count();
            $dispatched += $ids->count();
        }

        return $dispatched;
    }

    public function complete(): int
    {
        $done = 0;
        $campaigns = StoreEmailCampaign::query()->withoutGlobalScopes()->where('status', 'sending')->whereNotNull('link_urls')
            ->whereDoesntHave('recipients', fn ($q) => $q->whereIn('status', ['queued', 'sending']))->get();
        foreach ($campaigns as $campaign) {
            $this->refreshCounts($campaign);
            $campaign->forceFill(['status' => 'sent', 'completed_at' => now()])->save();
            $done++;
        }

        return $done;
    }

    public function refreshCounts(StoreEmailCampaign $campaign): void
    {
        $counts = StoreEmailCampaignRecipient::query()->withoutGlobalScopes()->where('campaign_id', $campaign->id)->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END) as sent')
            ->selectRaw("SUM(CASE WHEN status IN ('failed', 'bounced') THEN 1 ELSE 0 END) as failed")
            ->first();
        $campaign->forceFill(['recipients_count' => (int) $counts->total, 'sent_count' => (int) $counts->sent, 'failed_count' => (int) $counts->failed])->save();
    }

    /**
     * Delivery and engagement numbers for the report.
     *
     * @return array<string, int|float|null>
     */
    public function report(StoreEmailCampaign $campaign): array
    {
        $row = StoreEmailCampaignRecipient::query()->withoutGlobalScopes()->where('campaign_id', $campaign->id)->toBase()
            ->selectRaw('COUNT(*) as recipients')
            ->selectRaw("SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued")
            ->selectRaw('SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END) as sent')
            ->selectRaw('SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened')
            ->selectRaw('SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked')
            ->selectRaw('COALESCE(SUM(clicks_count), 0) as clicks')
            ->selectRaw('SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) as unsubscribed')
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->selectRaw("SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) as bounced")
            ->selectRaw("SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped")
            ->first();
        $n = fn (string $key) => (int) ($row->{$key} ?? 0);
        $rate = fn (string $key) => $n('sent') > 0 ? round($n($key) / $n('sent') * 100, 1) : null;

        return [
            'recipients' => $n('recipients'), 'queued' => $n('queued'), 'sent' => $n('sent'), 'opened' => $n('opened'),
            'clicked' => $n('clicked'), 'clicks' => $n('clicks'), 'unsubscribed' => $n('unsubscribed'), 'failed' => $n('failed'),
            'bounced' => $n('bounced'), 'skipped' => $n('skipped'),
            'open_rate' => $rate('opened'), 'click_rate' => $rate('clicked'), 'unsubscribe_rate' => $rate('unsubscribed'),
        ];
    }
}
