<?php

namespace App\Services\Stores\Ssl;

use App\Models\StoreDomain;

/**
 * The default when no SSL provider is configured.
 *
 * Deliberately inert and honest: it never claims a certificate exists. Domains
 * stay at ssl_status = none with a clear reason, so an unconfigured deployment
 * reports "SSL not configured" in the health dashboard instead of silently
 * looking healthy.
 */
class NullSslProvider implements SslProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function issue(StoreDomain $domain): CertificateResult
    {
        return $this->unconfigured();
    }

    public function renew(StoreDomain $domain): CertificateResult
    {
        return $this->unconfigured();
    }

    public function revoke(StoreDomain $domain): CertificateResult
    {
        return CertificateResult::revoked();
    }

    public function status(StoreDomain $domain): CertificateResult
    {
        return $this->unconfigured();
    }

    private function unconfigured(): CertificateResult
    {
        return CertificateResult::failed(
            'No SSL provider is configured. Set sellchase.storefront.ssl.provider.',
            retryable: false,
        );
    }
}
