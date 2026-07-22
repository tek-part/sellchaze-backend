<?php

namespace App\Notifications;

class DomainVerifiedNotification extends DomainNotification
{
    public function event(): string
    {
        return 'domain_verified';
    }

    public function subject(): string
    {
        return __('Your domain :host is verified', ['host' => $this->domain->host]);
    }

    public function lines(): array
    {
        return [
            __('Ownership of :host has been confirmed and it is now serving your storefront.', ['host' => $this->domain->host]),
            __('If a certificate is still being issued, HTTPS may take a few more minutes to become active.'),
        ];
    }
}
