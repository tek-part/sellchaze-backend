<?php

namespace App\Services\Stores\Ssl;

use App\Models\StoreDomain;

/**
 * TLS is terminated by infrastructure we do not drive from PHP — an nginx/HAProxy
 * edge, cPanel AutoSSL, or a corporate load balancer.
 *
 * Issuance is out-of-band, so `issue()` reports what the edge is *actually*
 * serving rather than pretending to have created anything. This is the honest
 * default for a deployment that terminates TLS upstream.
 */
class ReverseProxySslProvider implements SslProvider
{
    public function __construct(protected readonly TlsProbe $probe) {}

    public function name(): string
    {
        return 'reverse-proxy';
    }

    public function issue(StoreDomain $domain): CertificateResult
    {
        return $this->status($domain);
    }

    public function renew(StoreDomain $domain): CertificateResult
    {
        // Renewal is the edge's responsibility; we observe the outcome.
        return $this->status($domain);
    }

    public function revoke(StoreDomain $domain): CertificateResult
    {
        // Nothing to revoke here — removing the host from the edge is an ops action.
        return CertificateResult::revoked();
    }

    public function status(StoreDomain $domain): CertificateResult
    {
        $cert = $this->probe->inspect($domain->host);

        if ($cert === null) {
            return CertificateResult::pending(
                'No TLS certificate is being served for this host yet.',
            );
        }

        // The probe reads certificates without verifying them, so validate here
        // before reporting success. A certificate that does not cover this host,
        // or has already expired, is NOT a working certificate — reporting it as
        // active would show a green badge while browsers reject the site.
        if (! $this->probe->certificateCovers($cert['san'], $domain->host)) {
            return CertificateResult::failed(
                'The certificate being served does not cover this host.',
            );
        }

        if ($cert['expires_at'] !== null && $cert['expires_at']->getTimestamp() <= time()) {
            return CertificateResult::failed('The certificate being served has expired.');
        }

        return CertificateResult::issued(
            $cert['issuer'],
            $cert['fingerprint'],
            $cert['san'],
            $cert['issued_at'],
            $cert['expires_at'],
        );
    }
}
