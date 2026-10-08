<?php

namespace App\Jobs\Domains;

use App\Services\Stores\StoreDomainService;

/**
 * Kicks off verification without invalidating the TXT record already shown to the owner.
 *
 * Dispatched when a domain is connected or when the owner presses "Verify" — the
 * HTTP request returns immediately and never blocks on DNS.
 */
class StartDomainVerificationJob extends DomainJob
{
    public function handle(StoreDomainService $service): void
    {
        $domain = $this->domain();
        if ($domain === null || ! $domain->isCustom()) {
            return;
        }

        // Locked domains are skipped silently — the lock exists precisely to
        // stop repeated work, so re-queueing here would defeat it.
        if ($domain->isLocked()) {
            return;
        }

        // Rotation is explicit via the verification endpoint. A normal Verify click
        // must keep the published challenge valid, including queued retries.

        CheckDomainDnsJob::dispatch($domain->id);
    }
}
