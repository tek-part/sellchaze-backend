<?php

namespace App\Jobs\Domains;

use App\Models\StoreDomain;
use App\Models\StoreDomainEvent;
use App\Services\Stores\DomainAuditLogger;
use App\Services\Stores\StoreDomainService;

/**
 * Daily re-verification of an ALREADY VERIFIED domain.
 *
 * Deliberately gentler than first-time verification: a single DNS blip must not
 * take a live storefront offline, so a verified domain is only disabled after a
 * run of consecutive failures. This is what closes the dangling-DNS window —
 * a tenant who removes their records eventually stops being served.
 */
class RefreshDomainVerificationJob extends DomainJob
{
    public function handle(StoreDomainService $service, DomainAuditLogger $audit): void
    {
        $domain = $this->domain();
        if ($domain === null || ! $domain->isCustom()) {
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

        if ($txtOk) {
            // Still ours: clear the failure streak.
            if ($domain->verification_attempts > 0 || $domain->last_error !== null) {
                $domain->forceFill(['verification_attempts' => 0, 'last_error' => null])->save();
            }

            return;
        }

        $attempts = $domain->verification_attempts + 1;
        $domain->forceFill([
            'verification_attempts' => $attempts,
            'last_error' => __('The verification TXT record is no longer present.'),
        ])->save();

        $threshold = (int) config('sellchase.storefront.domains.stale_after_failures', 5);

        if ($domain->status === StoreDomain::STATUS_VERIFIED && $attempts >= $threshold) {
            // Never leave an unverifiable domain marked verified.
            $service->disable(
                $domain,
                null,
                __('DNS verification failed :count days running.', ['count' => $attempts]),
            );

            $audit->record($domain->refresh(), StoreDomainEvent::OWNERSHIP_REJECTED, null, null, [
                'attempts' => $attempts,
            ], 'Stale domain automatically disabled.');
        }
    }
}
