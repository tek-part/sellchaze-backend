<?php

namespace App\Notifications;

use App\Models\StoreDomain;

class DomainSslExpiringNotification extends DomainNotification
{
    public function __construct(StoreDomain $domain, public readonly int $daysRemaining)
    {
        parent::__construct($domain);
    }

    public function event(): string
    {
        return 'domain_ssl_expiring';
    }

    public function subject(): string
    {
        return $this->daysRemaining <= 0
            ? __('The SSL certificate for :host has expired', ['host' => $this->domain->host])
            : __('SSL for :host expires in :days days', [
                'host' => $this->domain->host,
                'days' => $this->daysRemaining,
            ]);
    }

    public function lines(): array
    {
        if ($this->daysRemaining <= 0) {
            return [
                __('The certificate for :host has expired and visitors will see a security warning.', ['host' => $this->domain->host]),
                __('We are attempting to renew it automatically.'),
            ];
        }

        return [
            __('The certificate for :host expires in :days days.', [
                'host' => $this->domain->host,
                'days' => $this->daysRemaining,
            ]),
            __('Renewal is automatic — no action is needed unless it keeps failing.'),
        ];
    }
}
