<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\Apps\MerchantAppRegistry;
use App\Services\Storefront\StorefrontService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Settings of first-party App store apps, versioned like the other store settings. */
class StoreAppSettingsController extends Controller
{
    private function store(Request $request, string $app): Store
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store, 404);
        $owner = $user && (int) $store->owner_user_id === (int) $user->id;
        $required = $app === 'bundles' ? ['store.settings.manage', 'store.coupons.manage'] : ['store.settings.manage'];
        abort_unless($user && ($owner || $user->can('stores-edit') || collect($required)->every(fn ($name) => $user->can($name))), 403);

        return $store;
    }

    public function show(Request $request, Store $store, string $app): JsonResponse
    {
        $settings = MerchantAppRegistry::for($app);
        $store = $this->store($request, $app);

        return response()->json(['data' => $settings->configured($store), 'meta' => $settings->meta($store)], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request, Store $store, string $app): JsonResponse
    {
        $settings = MerchantAppRegistry::for($app);
        $store = $this->store($request, $app);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']] + $settings->rules($store));
        DB::transaction(function () use ($store, $settings, $data) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $current = $settings->configured($locked);
            abort_unless((int) $data['version'] === (int) $current['version'], 409, 'These settings changed. Reload before saving.');
            $locked->forceFill([$settings->column() => $settings->normalize($locked, $data) + ['version' => $current['version'] + 1]])->save();
        });
        StorefrontService::forgetHomepage($store->id);
        $request->attributes->set('store', $store->fresh());

        return $this->show($request, $store, $app);
    }
}
