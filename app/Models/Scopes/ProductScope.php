<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Unified tenant scope for the canonical catalog models (Product / Category).
 *
 * A single table now serves BOTH the global B2B catalog (store_id IS NULL) and the multi-tenant
 * storefront catalog (store_id = the store). This scope keeps the two provably isolated:
 *
 *   - Store context set (storefront request via ResolveStoreFromHost / ScopeToStore):
 *       -> where store_id = current store   (identical to the former StoreScope)
 *   - No store context (B2B admin / orders / inventory / jobs / commands):
 *       -> where store_id IS NULL           (the store-less B2B catalog)
 *
 * LEAK-SAFE BY CONSTRUCTION: a storefront path that somehow runs without a store context sees the
 * B2B (NULL-store) rows, NEVER another store's rows — so cross-tenant leakage is impossible. B2B
 * behaviour is unchanged because every B2B catalog row has store_id = NULL.
 *
 * Explicit cross-context access opts out via ->forStore($store) or ->withoutGlobalScope(ProductScope::class).
 */
class ProductScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $storeId = app(CurrentStore::class)->id();

        if ($storeId === null) {
            $builder->whereNull($model->getTable().'.store_id');

            return;
        }

        $builder->where($model->getTable().'.store_id', $storeId);
    }
}
