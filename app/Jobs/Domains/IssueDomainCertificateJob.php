<?php

namespace App\Jobs\Domains;

use App\Services\Stores\DomainSslService;

/**
 * Asynchronous certificate issuance / renewal.
 *
 * Whether this reaches an ACME client, the Cloudflare API or simply observes an
 * on-demand-TLS edge is entirely the configured provider's business — this job
 * only decides *when*.
 */
class IssueDomainCertificateJob extends DomainJob
{
    public bool $renewal;

    public function __construct(int $storeDomainId, bool $renewal = false)
    {
        parent::__construct($storeDomainId);
        $this->renewal = $renewal;
    }

    /** Issuance can be slow (DNS-01 propagation, CA round trips). */
    public int $timeout = 300;

    public function handle(DomainSslService $ssl): void
    {
        $domain = $this->domain();
        if ($domain === null || ! $domain->isServable()) {
            return;
        }

        // Stop retrying a hopeless certificate forever; the daily sweep still
        // re-attempts, but slowly, so a broken domain cannot burn CA rate limits.
        $max = (int) config('sellchase.storefront.ssl.max_renewal_attempts', 8);
        if ($domain->ssl_renewal_attempts >= $max) {
            return;
        }

        $this->renewal ? $ssl->renew($domain) : $ssl->issue($domain);
    }
}
