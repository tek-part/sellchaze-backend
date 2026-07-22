<?php

namespace App\Jobs\Domains;

use App\Models\StoreDomain;
use App\Services\Stores\StoreDomainService;

/**
 * Decides verified / rejected from the DNS evidence, and — on first success —
 * kicks off certificate issuance.
 *
 * This is the only place a domain becomes servable, so it is the security
 * boundary for the whole feature.
 */
class VerifyDomainOwnershipJob extends DomainJob
{
    public function handle(StoreDomainService $service): void
    {
        $domain = $this->domain();
        if ($domain === null || ! $domain->isCustom() || $domain->isLocked()) {
            return;
        }

        $wasVerified = $domain->status === StoreDomain::STATUS_VERIFIED;
        $verified = $service->verify($domain);

        // Only chase a certificate once ownership is proven, and only when the
        // domain has just become servable — this is what prevents certificate
        // farming through repeatedly attached, unverified domains.
        if ($verified && ! $wasVerified) {
            IssueDomainCertificateJob::dispatch($domain->id);
        }
    }
}
