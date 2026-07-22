<?php

namespace App\Notifications;

use App\Models\StoreDomain;

class DomainPrimaryChangedNotification extends DomainNotification
{
    public function __construct(StoreDomain $domain, public readonly ?string $previousHost = null)
    {
        parent::__construct($domain);
    }

    public function event(): string
    {
        return 'domain_primary_changed';
    }

    public function subject(): string
    {
        return __('Your primary domain is now :host', ['host' => $this->domain->host]);
    }

    public function lines(): array
    {
        return array_values(array_filter([
            __('Customers and search engines will now be sent to :host.', ['host' => $this->domain->host]),
            $this->previousHost === null ? null : __('The previous domain :host now redirects here permanently.', ['host' => $this->previousHost]),
        ]));
    }
}
