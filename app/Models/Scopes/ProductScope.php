<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Unified tenant scope for the canonical catalog models (Product / Category).
 *
 * The catalog is ONE per-owner catalog. Every catalog row lives store-less
 * (store_id IS NULL) and is owned by a user (user_id) — the same ownership that
 * scopes the B2B catalog pages. A store simply surfaces its owner's catalog:
 *
 *   - Store context set (storefront request via ResolveStoreFromHost / ScopeToStore):
 *       -> store_id IS NULL AND user_id = the store's owner_user_id
 *          (the storefront shows exactly its owner's catalog)
 *   - No store context (B2B admin / orders / inventory / jobs / commands):
 *       -> store_id IS NULL
 *          (the store-less catalog; the B2B controllers narrow by user_id themselves)
 *
 * LEAK-SAFE BY CONSTRUCTION: owner_user_id is unique per store, so two stores can never resolve the
 * same rows. A storefront path that somehow runs without a store context sees the store-less catalog,
 * never a specific other store's rows.
 *
 * Explicit cross-context access opts out via ->forStore($store) or ->withoutGlobalScope(ProductScope::class).
 */
class ProductScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $store = app(CurrentStore::class)->get();
        $table = $model->getTable();

        $builder->whereNull($table.'.store_id');

        if ($store !== null) {
            $builder->where($table.'.user_id', $store->owner_user_id);
        }
    }
}
