<?php

namespace App\Services\Stores\Ssl;

use App\Models\StoreDomain;
use App\Services\Stores\CpanelDomainHosting;

class CpanelSslProvider extends ReverseProxySslProvider
{
    public function name(): string
    {
        return 'cpanel';
    }

    public function issue(StoreDomain $domain): CertificateResult
    {
        if (! $domain->isServable() || ! $domain->dns_target_ok || $domain->hosting_status !== 'ready') {
            return CertificateResult::pending('Complete domain ownership, DNS hosting and routing before requesting SSL.');
        }
        app(CpanelDomainHosting::class)->call('SSL', 'start_autossl_check');

        return $this->status($domain);
    }

    public function status(StoreDomain $domain): CertificateResult
    {
        if (! $domain->isServable() || ! $domain->dns_target_ok || $domain->hosting_status !== 'ready') {
            return CertificateResult::pending('Waiting for public DNS routing and hosting readiness.');
        }
        $result = parent::status($domain);
        if ($result->status === 'active' && ! $this->probe->isTrusted($domain->host)) {
            return CertificateResult::pending('Waiting for a publicly trusted SSL certificate.');
        }

        return $result;
    }

    public function renew(StoreDomain $domain): CertificateResult
    {
        return $this->issue($domain);
    }
}
