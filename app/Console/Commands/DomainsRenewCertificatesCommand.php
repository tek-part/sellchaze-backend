<?php

namespace App\Console\Commands;

use App\Jobs\Domains\IssueDomainCertificateJob;
use App\Jobs\Domains\RefreshSslStatusJob;
use App\Models\StoreDomain;
use Illuminate\Console\Command;

/**
 * Certificate renewal sweep.
 *
 * Renews anything inside the renewal window (default 30 days before expiry),
 * retries previously failed issuance, and polls domains whose certificate is
 * still pending at the provider (Caddy and Cloudflare both issue out-of-band).
 *
 * Per-domain backoff lives in the job; this command only decides what is due.
 */
class DomainsRenewCertificatesCommand extends Command
{
    protected $signature = 'domains:renew-certificates {--days=}';

    protected $description = 'Renew expiring certificates, retry failures and poll pending issuance';

    public function handle(): int
    {
        $window = (int) ($this->option('days') ?? config('sellchase.storefront.ssl.renew_before_days', 30));
        $maxAttempts = (int) config('sellchase.storefront.ssl.max_renewal_attempts', 8);

        // 1) Renew certificates approaching expiry.
        $renewing = StoreDomain::query()
            ->servable()
            ->custom()
            ->expiringWithin($window)
            ->where('ssl_renewal_attempts', '<', $maxAttempts)
            ->pluck('id');

        foreach ($renewing as $id) {
            IssueDomainCertificateJob::dispatch((int) $id, renewal: true);
        }

        // 2) Retry domains with no certificate yet, or a failed one, that have
        //    not exhausted their attempts.
        $retrying = StoreDomain::query()
            ->servable()
            ->custom()
            ->whereIn('ssl_status', [StoreDomain::SSL_NONE, StoreDomain::SSL_FAILED])
            ->where('ssl_renewal_attempts', '<', $maxAttempts)
            ->pluck('id');

        foreach ($retrying as $id) {
            IssueDomainCertificateJob::dispatch((int) $id);
        }

        // 3) Poll issuance the provider is completing out-of-band.
        $pending = StoreDomain::query()
            ->servable()
            ->custom()
            ->where('ssl_status', StoreDomain::SSL_PENDING)
            ->pluck('id');

        foreach ($pending as $id) {
            RefreshSslStatusJob::dispatch((int) $id);
        }

        $this->info(sprintf(
            'Queued %d renewal(s), %d retry(ies), %d status poll(s).',
            $renewing->count(),
            $retrying->count(),
            $pending->count(),
        ));

        return self::SUCCESS;
    }
}
