<?php

namespace App\Jobs\Domains;

use App\Services\Stores\DomainSslService;

/**
 * Polls the provider for current certificate state without attempting issuance.
 *
 * Needed because several providers issue out-of-band: Caddy on first handshake,
 * Cloudflare after its own validation. Polling is how a `pending` certificate
 * ever becomes `active`.
 */
class RefreshSslStatusJob extends DomainJob
{
    public function handle(DomainSslService $ssl): void
    {
        $domain = $this->domain();
        if ($domain === null || ! $domain->isServable()) {
            return;
        }

        $ssl->refreshStatus($domain);
    }
}
