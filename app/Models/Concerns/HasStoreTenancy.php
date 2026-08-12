<?php

namespace App\Models\Concerns;

use App\Models\Scopes\ProductScope;
use App\Support\Tenancy\CurrentStore;

/** Independent B2B/store tenancy for canonical Product and Category models. */
trait HasStoreTenancy
{
    use InteractsWithStore;

    public static function bootHasStoreTenancy(): void
    {
        static::addGlobalScope(new ProductScope);

        static::creating(function ($model): void {
            $store = app(CurrentStore::class)->get();
            if ($store === null) {
                return;
            }
            if (empty($model->user_id)) {
                $model->user_id = $store->owner_user_id;
            }
            if (empty($model->store_id)) {
                $model->store_id = $store->id;
            }
        });
    }
}
