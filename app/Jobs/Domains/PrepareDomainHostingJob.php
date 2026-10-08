<?php

namespace App\Jobs\Domains;

use App\Services\Stores\CpanelDomainHosting;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Throwable;

class PrepareDomainHostingJob extends DomainJob
{
    public int $timeout = 300;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('domain-hosting:'.$this->storeDomainId))->releaseAfter(15)->expireAfter(330)];
    }

    public function handle(CpanelDomainHosting $hosting): void
    {
        $domain = $this->domain();
        if ($domain === null || ! $domain->isServable() || ! $hosting->enabled()) {
            return;
        }
        try {
            $hosting->prepare($domain);
        } catch (Throwable $error) {
            $domain->forceFill(['hosting_error' => Str::limit($error->getMessage(), 490)])->save();
            throw $error;
        }
    }
}
