<?php

namespace App\Jobs\Domains;

use App\Services\Stores\StoreDomainService;

/**
 * Records the DNS picture — challenge TXT plus whether the host actually points
 * at us (CNAME or A) — then hands off to ownership verification.
 *
 * Split from VerifyDomainOwnershipJob so the health dashboard can show *which*
 * record is missing rather than a single opaque "verification failed".
 */
class CheckDomainDnsJob extends DomainJob
{
    public function handle(StoreDomainService $service): void
    {
        $domain = $this->domain();
        if ($domain === null || ! $domain->isCustom() || $domain->isLocked()) {
            return;
        }

        $txtOk = $service->checkDns($domain);
        $target = $service->checkDnsTarget($domain);

        $domain->forceFill([
            'dns_txt_ok' => $txtOk,
            'dns_target_ok' => $target['ok'],
            'dns_target_type' => $target['type'],
            'last_checked_at' => now(),
        ])->save();

        VerifyDomainOwnershipJob::dispatch($domain->id);
    }
}
