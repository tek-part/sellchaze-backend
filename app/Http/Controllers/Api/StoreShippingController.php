<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\ShoppingPreferences;
use App\Services\Commerce\StoreShipping;
use App\Services\Storefront\StorefrontService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreShippingController extends Controller
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404);
        $user = $request->user();
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.settings.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    public function index(Request $request, StoreShipping $shipping): JsonResponse
    {
        $store = $this->store($request);
        $config = $shipping->configuration($store);
        $config['options'] = $shipping->withIcons($store, $config['options']);

        return response()->json(['data' => $config + [
            'version' => app(ShoppingPreferences::class)->configured($store)['version'],
            'shipping_enabled' => (bool) $store->shipping_enabled, 'shipping_flat_rate' => $store->shipping_flat_rate ?? '0.00',
            'shipping_free_over' => $store->shipping_free_over, 'tax_enabled' => (bool) $store->tax_enabled,
            'tax_rate' => $store->tax_rate ?? '0', 'tax_prices_include' => (bool) $store->tax_prices_include,
        ]]);
    }

    public function update(Request $request, StoreShipping $shipping): JsonResponse
    {
        $store = $this->store($request);
        $money = ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'];
        $rules = [
            'version' => ['sometimes', 'integer', 'min:1'],
            'shipping_enabled' => ['required', 'boolean'], 'shipping_flat_rate' => $money,
            'shipping_free_over' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'tax_enabled' => ['required', 'boolean'], 'tax_rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,3'],
            'tax_prices_include' => ['required', 'boolean'], 'regions_enabled' => ['required', 'boolean'],
            'auto_select_region' => ['required', 'boolean'], 'regions' => ['present', 'array', 'max:500'], 'options' => ['present', 'array', 'max:50'],
            'regions.*' => ['array:id,name,country,rate,enabled,position'],
            'regions.*.country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'regions.*.position' => ['required', 'integer', 'min:0', 'max:999'],
            'options.*' => ['array:id,name,description,rate,enabled,is_default,priority,icon_asset_id'],
            'options.*.description' => ['required', 'array:ar,en'],
            'options.*.description.ar' => ['nullable', 'string', 'max:500'], 'options.*.description.en' => ['nullable', 'string', 'max:500'],
            'options.*.is_default' => ['required', 'boolean'], 'options.*.priority' => ['required', 'integer', 'min:0', 'max:999'],
            'options.*.icon_asset_id' => ['nullable', 'integer', Rule::exists('store_media_assets', 'id')->where('store_id', $store->id)->whereNull('deleted_at')],
        ];
        foreach (['regions', 'options'] as $collection) {
            $rules[$collection.'.*.id'] = ['required', 'uuid', 'distinct'];
            $rules[$collection.'.*.name'] = ['required', 'array:ar,en'];
            $rules[$collection.'.*.name.ar'] = $rules[$collection.'.*.name.en'] = ['required', 'string', 'max:120'];
            $rules[$collection.'.*.enabled'] = ['required', 'boolean'];
            $rules[$collection.'.*.rate'] = $money;
        }
        $data = $request->validate($rules);
        foreach (['regions_enabled', 'auto_select_region'] as $flag) {
            $data[$flag] = (bool) $data[$flag];
        }
        if ($data['regions_enabled'] && ! collect($data['regions'])->contains('enabled', true)) {
            throw ValidationException::withMessages(['regions' => __('Add at least one active delivery region.')]);
        }
        if (collect($data['options'])->where('is_default', true)->count() > 1 || collect($data['options'])->contains(fn (array $row) => $row['is_default'] && ! $row['enabled'])) {
            throw ValidationException::withMessages(['options' => __('Choose at most one active default shipping option.')]);
        }
        foreach (['regions', 'options'] as $collection) {
            foreach ($data[$collection] as &$row) {
                $row['rate'] = bcadd((string) $row['rate'], '0', 2);
                $row['enabled'] = (bool) $row['enabled'];
                $orderKey = $collection === 'regions' ? 'position' : 'priority';
                $row[$orderKey] = (int) $row[$orderKey];
                if ($collection === 'options') {
                    $row['is_default'] = (bool) $row['is_default'];
                    $row['description'] = ['ar' => $row['description']['ar'] ?? '', 'en' => $row['description']['en'] ?? ''];
                    $row['icon_asset_id'] = $row['icon_asset_id'] ?? null;
                }
            }
            unset($row);
        }
        $updated = DB::transaction(function () use ($store, $data) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $preferences = app(ShoppingPreferences::class)->configured($locked);
            if (array_key_exists('version', $data)) {
                abort_unless($preferences['version'] === (int) $data['version'], 409, 'Settings changed. Reload before saving.');
            }
            $locked->update([
                'shipping_enabled' => $data['shipping_enabled'], 'shipping_flat_rate' => $data['shipping_flat_rate'],
                'shipping_free_over' => $data['shipping_free_over'] ?? null,
                'tax_enabled' => $data['tax_enabled'], 'tax_rate' => $data['tax_rate'], 'tax_prices_include' => $data['tax_prices_include'],
                'shipping_configuration' => array_intersect_key($data, array_flip(['regions_enabled', 'auto_select_region', 'regions', 'options'])),
                'shopping_preferences' => ['auto_select_variants' => $preferences['auto_select_variants'], 'version' => $preferences['version'] + 1],
            ]);

            return $locked;
        });
        StorefrontService::forgetHomepage($store->id);
        $request->attributes->set('store', $updated);

        return $this->index($request, $shipping);
    }
}
