<?php

namespace App\Services\Stores;

use App\Models\StoreDomain;
use App\Models\StoreDomainCertificate;
use App\Models\StoreDomainEvent;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide custom-domain metrics.
 *
 * Exposed both as JSON (for the admin console) and as Prometheus text exposition
 * (for Grafana). Everything is a single aggregate query — no per-domain loops —
 * so scraping stays cheap as the tenant base grows.
 */
class DomainMetricsService
{
    /**
     * @return array<string, int|float|null>
     */
    public function collect(): array
    {
        $byStatus = StoreDomain::query()
            ->where('type', 'custom')
            ->groupBy('status')
            ->pluck(DB::raw('count(*)'), 'status');

        $bySsl = StoreDomain::query()
            ->where('type', 'custom')
            ->groupBy('ssl_status')
            ->pluck(DB::raw('count(*)'), 'ssl_status');

        return [
            'domains_total' => (int) StoreDomain::query()->where('type', 'custom')->count(),
            'domains_verified' => (int) ($byStatus[StoreDomain::STATUS_VERIFIED] ?? 0),
            'domains_pending' => (int) ($byStatus[StoreDomain::STATUS_PENDING] ?? 0),
            'domains_rejected' => (int) ($byStatus[StoreDomain::STATUS_REJECTED] ?? 0),
            'domains_disabled' => (int) ($byStatus[StoreDomain::STATUS_DISABLED] ?? 0),

            'ssl_active' => (int) ($bySsl[StoreDomain::SSL_ACTIVE] ?? 0),
            'ssl_pending' => (int) ($bySsl[StoreDomain::SSL_PENDING] ?? 0),
            'ssl_failed' => (int) ($bySsl[StoreDomain::SSL_FAILED] ?? 0),
            'ssl_none' => (int) ($bySsl[StoreDomain::SSL_NONE] ?? 0),

            'certificates_expiring_30d' => (int) StoreDomain::query()->custom()->expiringWithin(30)->count(),
            'certificates_expiring_7d' => (int) StoreDomain::query()->custom()->expiringWithin(7)->count(),
            'certificates_expired' => (int) StoreDomain::query()->custom()->expiringWithin(0)->count(),

            'verification_failures_24h' => (int) StoreDomainEvent::query()
                ->where('event', StoreDomainEvent::VERIFICATION_FAILED)
                ->where('created_at', '>=', now()->subDay())
                ->count(),
            'renewal_failures_24h' => (int) StoreDomainEvent::query()
                ->where('event', StoreDomainEvent::SSL_FAILED)
                ->where('created_at', '>=', now()->subDay())
                ->count(),

            'avg_verification_seconds' => $this->averageVerificationSeconds(),
            'avg_ssl_issuance_seconds' => $this->averageSslIssuanceSeconds(),
        ];
    }

    /**
     * Mean time from a domain being added to it passing verification.
     *
     * Measured from the audit trail rather than a stored duration, so it stays
     * correct even for domains verified before this metric existed.
     */
    private function averageVerificationSeconds(): ?float
    {
        $added = StoreDomainEvent::query()
            ->select('store_domain_id', DB::raw('MIN(created_at) as at'))
            ->where('event', StoreDomainEvent::DOMAIN_ADDED)
            ->whereNotNull('store_domain_id')
            ->groupBy('store_domain_id');

        $passed = StoreDomainEvent::query()
            ->select('store_domain_id', DB::raw('MIN(created_at) as at'))
            ->where('event', StoreDomainEvent::VERIFICATION_PASSED)
            ->whereNotNull('store_domain_id')
            ->groupBy('store_domain_id');

        return $this->averageGapSeconds($added, $passed);
    }

    /** Mean time from verification passing to a certificate being issued. */
    private function averageSslIssuanceSeconds(): ?float
    {
        $verified = StoreDomainEvent::query()
            ->select('store_domain_id', DB::raw('MIN(created_at) as at'))
            ->where('event', StoreDomainEvent::VERIFICATION_PASSED)
            ->whereNotNull('store_domain_id')
            ->groupBy('store_domain_id');

        $issued = StoreDomainCertificate::query()
            ->select('store_domain_id', DB::raw('MIN(created_at) as at'))
            ->where('status', StoreDomainCertificate::STATUS_ISSUED)
            ->groupBy('store_domain_id');

        return $this->averageGapSeconds($verified, $issued);
    }

    /**
     * Average seconds between two per-domain timestamps.
     *
     * Done in PHP over a bounded result set rather than with a database-specific
     * datediff, so this works identically on MySQL, Postgres and SQLite.
     */
    private function averageGapSeconds(mixed $startQuery, mixed $endQuery): ?float
    {
        $starts = $startQuery->pluck('at', 'store_domain_id');
        if ($starts->isEmpty()) {
            return null;
        }

        $ends = $endQuery->pluck('at', 'store_domain_id');

        $gaps = [];
        foreach ($ends as $domainId => $end) {
            $start = $starts[$domainId] ?? null;
            if ($start === null) {
                continue;
            }

            $seconds = strtotime((string) $end) - strtotime((string) $start);
            if ($seconds >= 0) {
                $gaps[] = $seconds;
            }
        }

        return $gaps === [] ? null : round(array_sum($gaps) / count($gaps), 2);
    }

    /**
     * Prometheus text exposition format (version 0.0.4).
     */
    public function toPrometheus(): string
    {
        $metrics = $this->collect();
        $lines = [];

        foreach ($metrics as $key => $value) {
            if ($value === null) {
                continue; // absent is better than a misleading zero
            }

            $name = 'sellchase_custom_domain_'.$key;
            $type = str_contains($key, 'avg_') ? 'gauge' : 'gauge';

            $lines[] = '# HELP '.$name.' '.$this->help($key);
            $lines[] = '# TYPE '.$name.' '.$type;
            $lines[] = $name.' '.$value;
        }

        return implode("\n", $lines)."\n";
    }

    private function help(string $key): string
    {
        return match ($key) {
            'domains_total' => 'Total custom domains connected across all stores.',
            'domains_verified' => 'Custom domains that are verified and serving.',
            'domains_pending' => 'Custom domains awaiting DNS verification.',
            'domains_rejected' => 'Custom domains whose verification failed.',
            'domains_disabled' => 'Custom domains taken out of service.',
            'ssl_active' => 'Domains with an active certificate.',
            'ssl_pending' => 'Domains with issuance in progress.',
            'ssl_failed' => 'Domains whose certificate issuance failed.',
            'ssl_none' => 'Domains with no certificate.',
            'certificates_expiring_30d' => 'Certificates expiring within 30 days.',
            'certificates_expiring_7d' => 'Certificates expiring within 7 days.',
            'certificates_expired' => 'Certificates that have already expired.',
            'verification_failures_24h' => 'Verification failures in the last 24 hours.',
            'renewal_failures_24h' => 'Certificate failures in the last 24 hours.',
            'avg_verification_seconds' => 'Mean seconds from domain added to verified.',
            'avg_ssl_issuance_seconds' => 'Mean seconds from verified to certificate issued.',
            default => 'Custom domain metric.',
        };
    }
}
