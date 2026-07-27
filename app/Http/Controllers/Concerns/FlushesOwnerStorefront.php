<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Store;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontService;

/**
 * Invalidate an owner's storefront caches after a catalog write.
 *
 * The unified catalog is edited through the B2B controllers (/products,
 * /categories), which have no store context. Since a store surfaces its owner's
 * catalog, a change there must still flush that owner's storefront homepage +
 * page caches — otherwise the storefront serves stale data until the TTL lapses.
 */
trait FlushesOwnerStorefront
{
    protected function flushOwnerStorefront(?int $ownerUserId): void
    {
        if ($ownerUserId === null) {
            return;
        }

        $storeId = Store::query()->where('owner_user_id', $ownerUserId)->value('id');
        if ($storeId === null) {
            return;
        }

        StorefrontService::forgetHomepage((int) $storeId);
        app(StorefrontPageCache::class)->flushStore((int) $storeId);
    }
}
