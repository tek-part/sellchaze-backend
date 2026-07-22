<?php

namespace App\Notifications;

use App\Models\StoreDomain;

class DomainSslIssuedNotification extends DomainNotification
{
    public function __construct(StoreDomain $domain, public readonly bool $renewal = false)
    {
        parent::__construct($domain);
    }

    public function event(): string
    {
        return $this->renewal ? 'domain_ssl_renewed' : 'domain_ssl_issued';
    }

    public function subject(): string
    {
        return $this->renewal
            ? __('SSL renewed for :host', ['host' => $this->domain->host])
            : __('SSL is active for :host', ['host' => $this->domain->host]);
    }

    public function lines(): array
    {
        $expires = $this->domain->ssl_expires_at?->toFormattedDateString();

        return array_values(array_filter([
            __('HTTPS is active for :host.', ['host' => $this->domain->host]),
            $expires === null ? null : __('The certificate is valid until :date.', ['date' => $expires]),
        ]));
    }
}
