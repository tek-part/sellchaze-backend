<?php

namespace App\Notifications;

use App\Models\StoreDomain;

class DomainDisabledNotification extends DomainNotification
{
    public function __construct(StoreDomain $domain, public readonly string $reason)
    {
        parent::__construct($domain);
    }

    public function event(): string
    {
        return 'domain_disabled';
    }

    public function subject(): string
    {
        return __('The domain :host has been disabled', ['host' => $this->domain->host]);
    }

    public function lines(): array
    {
        return [
            __(':host is no longer serving your storefront: :reason', [
                'host' => $this->domain->host,
                'reason' => $this->reason,
            ]),
            __('Restore the required DNS records and verify the domain again to bring it back online.'),
        ];
    }
}
