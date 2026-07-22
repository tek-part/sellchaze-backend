<?php

namespace App\Console\Commands;

use App\Models\StoreDomain;
use App\Models\StoreDomainEvent;
use App\Notifications\DomainSslExpiringNotification;
use App\Services\Stores\DomainAuditLogger;
use App\Services\Stores\DomainNotifier;
use Illuminate\Console\Command;

/**
 * Certificate expiry monitoring.
 *
 * Notifies the owner as a certificate crosses each configured threshold
 * (90/60/30/15/7/1 days, then expired). Each threshold fires at most once per
 * certificate: the audit log is the dedupe key, keyed on
 * (fingerprint, threshold), so re-running the command never spams the owner —
 * and a renewed certificate (new fingerprint) is free to notify again.
 */
class DomainsMonitorCertificatesCommand extends Command
{
    protected $signature = 'domains:monitor-certificates';

    protected $description = 'Notify store owners about certificates approaching expiry';

    public function handle(DomainNotifier $notifier, DomainAuditLogger $audit): int
    {
        /** @var list<int> $thresholds */
        $thresholds = config('sellchase.storefront.ssl.expiry_notice_days', [90, 60, 30, 15, 7, 1]);
        $widest = $thresholds === [] ? 90 : max($thresholds);

        $notified = 0;

        StoreDomain::query()
            ->custom()
            ->whereNotNull('ssl_expires_at')
            ->where('ssl_expires_at', '<=', now()->addDays($widest))
            ->orderBy('id')
            ->chunkById(200, function ($domains) use ($thresholds, $notifier, $audit, &$notified): void {
                foreach ($domains as $domain) {
                    $days = $domain->sslDaysRemaining();
                    if ($days === null) {
                        continue;
                    }

                    $threshold = $this->thresholdFor($days, $thresholds);
                    if ($threshold === null) {
                        continue;
                    }

                    $already = StoreDomainEvent::query()
                        ->where('store_domain_id', $domain->id)
                        ->where('event', StoreDomainEvent::SSL_EXPIRING)
                        ->where('new_value->threshold', $threshold)
                        ->where('new_value->fingerprint', $domain->ssl_fingerprint)
                        ->exists();

                    if ($already) {
                        continue;
                    }

                    $audit->record($domain, StoreDomainEvent::SSL_EXPIRING, null, null, [
                        'threshold' => $threshold,
                        'days_remaining' => $days,
                        'fingerprint' => $domain->ssl_fingerprint,
                    ]);

                    $notifier->send($domain, new DomainSslExpiringNotification($domain, $days));
                    $notified++;
                }
            });

        $this->info("Sent {$notified} certificate expiry notification(s).");

        return self::SUCCESS;
    }

    /**
     * The tightest threshold this certificate has crossed. 0 means expired.
     *
     * @param  list<int>  $thresholds
     */
    private function thresholdFor(int $days, array $thresholds): ?int
    {
        if ($days <= 0) {
            return 0;
        }

        $crossed = null;
        foreach ($thresholds as $threshold) {
            if ($days <= $threshold && ($crossed === null || $threshold < $crossed)) {
                $crossed = $threshold;
            }
        }

        return $crossed;
    }
}
