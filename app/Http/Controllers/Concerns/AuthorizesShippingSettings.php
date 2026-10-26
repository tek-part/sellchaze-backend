<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Store;
use Illuminate\Http\Request;

/** Shipping and tax settings: the store owner, or staff who may manage store settings. */
trait AuthorizesShippingSettings
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404);
        $user = $request->user();
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.settings.manage') || $user->can('stores-edit')), 403);

        return $store;
    }
}
