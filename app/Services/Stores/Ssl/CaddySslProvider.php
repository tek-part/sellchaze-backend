<?php

namespace App\Services\Stores\Ssl;

use App\Models\StoreDomain;

/**
 * Caddy with `on_demand_tls`.
 *
 * Caddy issues on the first TLS handshake, gated by its `ask` endpoint — which
 * points at our own allow-check (StoreDomainService::isIssuableHost), so a
 * certificate is only ever obtained for a host we actually serve.
 *
 * There is therefore nothing to "request": issuance is triggered by real
 * traffic. We warm it with a probe (which itself performs a handshake and so
 * triggers issuance) and then report what is being served.
 */
class CaddySslProvider extends ReverseProxySslProvider
{
    public function name(): string
    {
        return 'caddy';
    }

    public function issue(StoreDomain $domain): CertificateResult
    {
        // The probe's handshake is what triggers on-demand issuance. The first
        // attempt commonly lands mid-issuance, so `pending` here is expected and
        // the scheduler polls until it flips to active.
        $result = $this->status($domain);

        if (! $result->ok) {
            return CertificateResult::pending(
                'Caddy issues on first handshake; certificate not yet presented.',
            );
        }

        return $result;
    }
}
