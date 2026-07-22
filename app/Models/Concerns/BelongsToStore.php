<?php

namespace App\Models\Concerns;

use App\Models\Scopes\StoreScope;
use App\Support\Tenancy\CurrentStore;

/**
 * Reusable tenant-isolation for store-owned models.
 *
 *  - Adds the StoreScope global scope (auto store_id filtering, fail-closed).
 *  - Auto-fills store_id from the current store on create.
 *  - Provides store() + ->forStore() via the shared {@see InteractsWithStore} trait.
 *
 * Any model that stores per-store data uses this trait to guarantee isolation.
 */
trait BelongsToStore
{
    use InteractsWithStore;

    public static function bootBelongsToStore(): void
    {
        static::addGlobalScope(new StoreScope);

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
