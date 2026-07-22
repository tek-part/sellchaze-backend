<?php

namespace App\Services\Stores\Ssl;

use App\Models\StoreDomain;

/**
 * Contract every certificate provider implements.
 *
 * Deliberately provider-neutral: Let's Encrypt is one implementation among
 * several, never the default assumption. Adding a provider means adding one
 * class and a config entry — no change anywhere in the domain lifecycle.
 *
 * Implementations MUST be non-blocking from the caller's perspective: they are
 * only ever invoked from queued jobs, never inside an HTTP request.
 */
interface SslProvider
{
    /** Short identifier stored on the domain and in certificate history. */
    public function name(): string;

    /**
     * Request a certificate for the domain.
     *
     * May return `pending` when issuance happens out-of-band (Caddy on-demand,
     * Cloudflare custom hostnames) — the scheduler polls status afterwards.
     */
    public function issue(StoreDomain $domain): CertificateResult;

    /** Renew an existing certificate. Providers that auto-renew may delegate to status(). */
    public function renew(StoreDomain $domain): CertificateResult;

    /** Revoke / detach the certificate. Best-effort; must not throw on "already gone". */
    public function revoke(StoreDomain $domain): CertificateResult;

    /** Current certificate state as the provider sees it. */
    public function status(StoreDomain $domain): CertificateResult;
}
