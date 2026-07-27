<?php

namespace App\Models\Concerns;

use App\Models\Scopes\ProductScope;
use App\Support\Tenancy\CurrentStore;

/**
 * Unified per-owner tenancy for the canonical catalog models (Product / Category).
 *
 *  - Adds the {@see ProductScope} global scope: with a store context set (storefront) the query is
 *    limited to that store's OWNER catalog (store_id IS NULL AND user_id = owner); with no context
 *    it spans the store-less B2B catalog. Leak-safe by construction (see ProductScope).
 *  - Auto-fills user_id from the current store's owner on create (no-op in B2B contexts, where the
 *    controller sets user_id itself). store_id is intentionally left NULL — the catalog is store-less
 *    and owned by user_id, so a store-context create can never produce an unreadable store_id row.
 *  - Provides store() + ->forStore() via {@see InteractsWithStore}.
 */
trait HasStoreTenancy
{
    use InteractsWithStore;

    public static function bootHasStoreTenancy(): void
    {
        static::addGlobalScope(new ProductScope);

        static::creating(function ($model) {
            if (empty($model->user_id)) {
                $store = app(CurrentStore::class)->get();
                if ($store !== null) {
                    $model->user_id = $store->owner_user_id;
                }
            }
        });
    }
}
