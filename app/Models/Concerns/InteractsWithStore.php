<?php

namespace App\Models\Concerns;

use App\Models\Scopes\ProductScope;
use App\Models\Scopes\StoreScope;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared, single implementation of store association + explicit store scoping for catalog models.
 *
 * Used by both {@see BelongsToStore} (tenant-isolated store-scoped models such as media/brands/
 * collections) and the canonical Product/Category models. Keeping store() + scopeForStore() in ONE
 * place gives every catalog model identical signatures.
 *
 * scopeForStore() removes the fail-closed StoreScope and the unified ProductScope where present (a
 * no-op on models that never add them), so it works uniformly across the catalog.
 */
trait InteractsWithStore
{
    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForStore(Builder $query, Store|int $store): Builder
    {
        return $query->withoutGlobalScope(StoreScope::class)
            ->withoutGlobalScope(ProductScope::class)
            ->where($this->getTable().'.store_id', $store instanceof Store ? $store->id : (int) $store);
    }
}
