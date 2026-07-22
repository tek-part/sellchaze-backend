<?php

namespace App\Services\Stores;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Routes domain notifications to the store owner.
 *
 * A single seam so no caller has to know how an owner is reached — and so
 * notification delivery can never break a domain state transition: every send is
 * best-effort and failures are logged, not thrown.
 *
 * Uses Laravel's existing notification stack (channels resolved per-user by the
 * notification itself); no parallel delivery mechanism is introduced.
 */
class DomainNotifier
{
    public function send(?StoreDomain $domain, Notification $notification): void
    {
        $store = $domain?->store;
        $owner = $store instanceof Store ? $store->owner : null;

        if (! $owner instanceof User) {
            return;
        }

        try {
            $owner->notify($notification);
        } catch (\Throwable $e) {
            // A mail/queue outage must never roll back or block domain state.
            Log::warning('Domain notification failed', [
                'domain' => $domain?->host,
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
