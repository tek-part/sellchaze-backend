<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Separates every storefront catalog from the store-less B2B catalog.
 * A store context sees only its store_id; no context sees only B2B rows.
 */
class ProductScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $store = app(CurrentStore::class)->get();
        $column = $model->getTable().'.store_id';

        if ($store !== null) {
            $builder->where($column, $store->id);
        } else {
            $builder->whereNull($column);
        }
    }
}
