<?php

namespace App\Notifications;

use App\Models\StoreDomain;

class DomainVerificationFailedNotification extends DomainNotification
{
    public function __construct(StoreDomain $domain, public readonly string $reason)
    {
        parent::__construct($domain);
    }

    public function event(): string
    {
        return 'domain_verification_failed';
    }

    public function subject(): string
    {
        return __('We could not verify :host', ['host' => $this->domain->host]);
    }

    public function lines(): array
    {
        return [
            __('Verification for :host did not succeed: :reason', [
                'host' => $this->domain->host,
                'reason' => $this->reason,
            ]),
            __('Check the DNS records shown in your store settings, then try again. DNS changes can take up to 48 hours to propagate.'),
        ];
    }
}
