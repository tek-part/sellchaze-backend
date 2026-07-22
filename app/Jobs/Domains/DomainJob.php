<?php

namespace App\Jobs\Domains;

use App\Models\StoreDomain;
use App\Models\StoreDomainEvent;
use App\Services\Stores\DomainAuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Shared base for every custom-domain job.
 *
 * All domain work is queued — an HTTP request must never perform a DNS lookup or
 * talk to a certificate authority. Jobs carry only the domain id (SerializesModels
 * re-fetches), so a job can never act on a stale snapshot of domain state.
 *
 * Queue connection/name comes from config so Redis, database and SQS are all
 * supported without a code change.
 */
abstract class DomainJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // Not readonly: Laravel's SerializesModels restores properties by reflection
    // when a queued job is unserialized, which a readonly property forbids.
    public int $storeDomainId;

    public function __construct(int $storeDomainId)
    {
        $this->storeDomainId = $storeDomainId;
        $this->onQueue((string) config('sellchase.storefront.domains.queue', 'domains'));
    }

    /**
     * Resolve the domain fresh. Returns null when it was deleted between
     * dispatch and execution — a normal race, never an error.
     */
    protected function domain(): ?StoreDomain
    {
        return StoreDomain::query()->find($this->storeDomainId);
    }

    /**
     * Exponential backoff with a ceiling, shared by every domain job.
     *
     * DNS propagation and ACME rate limits both reward patience: retrying a
     * failed check every few seconds achieves nothing and risks a CA lockout.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 10800];
    }

    /** Give up after this many attempts; the scheduler will retry on its next sweep. */
    public int $tries = 5;

    /** A stuck DNS or TLS handshake must not occupy a worker indefinitely. */
    public int $timeout = 120;

    /**
     * Dead-letter handler: runs after `tries` is exhausted or the job times out.
     *
     * Without this a permanently failing job lands in `failed_jobs` and the
     * domain is left in whatever intermediate state it was in — typically
     * `pending` — with no audit entry and nothing for the owner or an operator
     * to see. Recording the failure keeps the domain's visible state honest.
     *
     * Deliberately best-effort and swallow-everything: a throw here would mask
     * the original failure in the worker log.
     */
    public function failed(?\Throwable $exception): void
    {
        try {
            $domain = $this->domain();
            if ($domain === null) {
                return;
            }

            $reason = $exception?->getMessage() ?? 'The job failed without an exception.';

            app(DomainAuditLogger::class)->record(
                $domain,
                StoreDomainEvent::VERIFICATION_FAILED,
                null,
                null,
                ['job' => static::class, 'attempts' => $this->attempts()],
                'Background job failed permanently: '.$reason,
            );

            $domain->forceFill([
                'last_checked_at' => now(),
                'last_error' => Str::limit(
                    __('A background check failed and will be retried on the next scheduled run.').' '.$reason,
                    490,
                ),
            ])->save();
        } catch (\Throwable) {
            // Never let dead-letter bookkeeping throw.
        }
    }
}
