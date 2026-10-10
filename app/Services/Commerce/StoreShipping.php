<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreMediaAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Resolves delivery selections from the current store, never from client-supplied prices. */
class StoreShipping
{
    public const SELECTION_RULES = [
        'shipping_region_id' => ['nullable', 'uuid'],
        'shipping_option_id' => ['nullable', 'uuid'],
    ];

    public function configuration(Store $store): array
    {
        return $store->shipping_configuration ?? ['regions_enabled' => false, 'auto_select_region' => true, 'regions' => [], 'options' => []];
    }

    public function regionsEnabled(Store $store): bool
    {
        return (bool) $store->shipping_enabled && $this->configuration($store)['regions_enabled'];
    }

    public function publicConfiguration(Store $store): array
    {
        $config = $this->configuration($store);

        return [
            'enabled' => (bool) $store->shipping_enabled,
            'regions_enabled' => $this->regionsEnabled($store),
            'auto_select_region' => $config['auto_select_region'],
            'currency' => $store->currency ?: 'USD',
            'flat_rate' => (string) ($store->shipping_flat_rate ?: '0.00'),
            'free_over' => $store->shipping_free_over,
            'regions' => $this->regionsEnabled($store) ? collect($config['regions'])->where('enabled', true)->sortBy('position')->values()->all() : [],
            'options' => $store->shipping_enabled ? $this->withIcons($store, collect($config['options'])->where('enabled', true)->sortByDesc('priority')->values()->all()) : [],
        ];
    }

    public function withIcons(Store $store, array $options): array
    {
        $assets = StoreMediaAsset::query()->where('store_id', $store->id)
            ->whereIn('id', array_filter(array_column($options, 'icon_asset_id')))->get()->keyBy('id');

        return array_map(function (array $option) use ($assets) {
            $asset = $assets->get($option['icon_asset_id'] ?? null);
            $option['icon_url'] = $asset ? url(Storage::disk($asset->disk)->url($asset->path)) : null;

            return $option;
        }, $options);
    }

    /** @return array{amount:string,address:array<string,mixed>,details:array<string,mixed>} */
    public function quote(Store $store, string $discountedSubtotal, array $selection = []): array
    {
        if (! $store->shipping_enabled) {
            return ['amount' => '0.00', 'address' => [], 'details' => ['mode' => 'free', 'base_rate' => '0.00', 'free_shipping_applied' => false]];
        }
        $config = $this->configuration($store);
        $rate = (string) ($store->shipping_flat_rate ?: '0.00');
        $mode = 'flat';
        $address = [];
        $region = null;
        $option = null;
        if ($config['regions_enabled']) {
            $region = collect($config['regions'])->first(fn (array $row) => $row['enabled'] && $row['id'] === ($selection['shipping_region_id'] ?? null));
            if ($region === null) {
                throw ValidationException::withMessages(['shipping_region_id' => __('Choose an available delivery region.')]);
            }
            $rate = $region['rate'];
            $mode = 'region';
            $address = ['city' => $this->label($region['name'], $store), 'country' => $region['country']];
        } elseif (! empty($selection['shipping_region_id'])) {
            throw ValidationException::withMessages(['shipping_region_id' => __('Delivery regions have changed. Please refresh the delivery options.')]);
        }
        $options = collect($config['options'])->where('enabled', true);
        if ($options->isNotEmpty()) {
            $option = $options->first(fn (array $row) => $row['id'] === ($selection['shipping_option_id'] ?? null));
            if ($option === null) {
                throw ValidationException::withMessages(['shipping_option_id' => __('Choose an available shipping option.')]);
            }
            // A named shipping option replaces the regional rate; it is not a surcharge.
            $rate = $option['rate'];
            $mode = 'option';
            $address['delivery_option'] = $this->label($option['name'], $store);
        } elseif (! empty($selection['shipping_option_id'])) {
            throw ValidationException::withMessages(['shipping_option_id' => __('Shipping options have changed. Please refresh the delivery options.')]);
        }
        $free = $store->shipping_free_over !== null && bccomp($discountedSubtotal, (string) $store->shipping_free_over, 2) >= 0;
        $details = ['mode' => $mode, 'base_rate' => bcadd($rate, '0', 2), 'free_shipping_applied' => $free,
            'region' => $region ? ['id' => $region['id'], 'name' => $region['name'], 'country' => $region['country']] : null,
            'option' => $option ? ['id' => $option['id'], 'name' => $option['name'], 'description' => $option['description']] : null];

        return ['amount' => $free ? '0.00' : bcadd($rate, '0', 2), 'address' => $address, 'details' => $details];
    }

    private function label(array $value, Store $store): string
    {
        return $value[app()->getLocale()] ?? $value[$store->default_locale ?: 'en'] ?? $value['en'];
    }
}
