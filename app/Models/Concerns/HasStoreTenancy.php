<?php

namespace App\Models\Concerns;

use App\Models\Scopes\ProductScope;
use App\Support\Tenancy\CurrentStore;

/**
 * Unified tenancy for the canonical catalog models (Product / Category).
 *
 *  - Adds the {@see ProductScope} global scope: store-scoped when a store context is set (storefront),
 *    store_id IS NULL otherwise (B2B). Leak-safe by construction (see ProductScope).
 *  - Auto-fills store_id from the current store on create (no-op in B2B contexts → store_id stays NULL).
 *  - Provides store() + ->forStore() via {@see InteractsWithStore}.
 *
 * This replaces the former split between BelongsToStore (fail-closed, store-only) and the un-scoped
 * legacy models: one table, one tenancy implementation.
 */
trait HasStoreTenancy
{
    use InteractsWithStore;

    public static function bootHasStoreTenancy(): void
    {
        static::addGlobalScope(new ProductScope);

        static::creating(function ($model) {
            if (empty($model->store_id)) {
                $currentId = app(CurrentStore::class)->id();
                if ($currentId !== null) {
                    $model->store_id = $currentId;
                }
            }
        });
    }
}
