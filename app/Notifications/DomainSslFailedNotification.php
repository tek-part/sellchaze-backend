<?php

namespace App\Notifications;

use App\Models\StoreDomain;

class DomainSslFailedNotification extends DomainNotification
{
    public function __construct(StoreDomain $domain, public readonly string $reason)
    {
        parent::__construct($domain);
    }

    public function event(): string
    {
        return 'domain_ssl_failed';
    }

    public function subject(): string
    {
        return __('SSL could not be issued for :host', ['host' => $this->domain->host]);
    }

    public function lines(): array
    {
        return [
            __('We could not obtain an SSL certificate for :host: :reason', [
                'host' => $this->domain->host,
                'reason' => $this->reason,
            ]),
            __('We will keep retrying automatically. Your storefront remains reachable on its other domains.'),
        ];
    }
}
