<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that isolates store-owned models by the current tenant store.
 *
 * FAIL-CLOSED (Phase 3.5): if no current store is set, the query is forced to
 * return NOTHING (`1 = 0`) rather than spanning all stores. A store-owned model
 * can therefore never be read without an explicit tenant, eliminating accidental
 * cross-store reads from jobs, commands, or future code paths.
 *
 * Explicit, intentional cross-context access must opt out deliberately via
 * ->forStore($store) (which removes this scope) or ->withoutGlobalScope(StoreScope::class).
 */
class StoreScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $storeId = app(CurrentStore::class)->id();

        if ($storeId === null) {
            $builder->whereRaw('1 = 0'); // fail-closed: no tenant -> no rows

            return;
        }

        $builder->where($model->getTable().'.store_id', $storeId);
    }
}
