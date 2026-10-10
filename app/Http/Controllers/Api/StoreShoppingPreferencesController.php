<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\ShoppingPreferences;
use App\Services\Storefront\StorefrontService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreShoppingPreferencesController extends Controller
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store, 404);
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.settings.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    public function index(Request $request, ShoppingPreferences $preferences): JsonResponse
    {
        return response()->json(['data' => $preferences->configured($this->store($request))]);
    }

    public function update(Request $request, ShoppingPreferences $preferences): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['auto_select_variants' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']]);
        $updated = DB::transaction(function () use ($store, $preferences, $data) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            abort_unless($preferences->configured($locked)['version'] === (int) $data['version'], 409, 'Settings changed. Reload before saving.');
            $locked->update(['shopping_preferences' => ['auto_select_variants' => (bool) $data['auto_select_variants'], 'version' => (int) $data['version'] + 1]]);

            return $locked;
        });
        StorefrontService::forgetHomepage($store->id);

        return response()->json(['data' => $preferences->configured($updated)]);
    }
}
