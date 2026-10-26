<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreShippingZone;
use App\Models\StoreTaxOverride;

/**
 * The tax rate for a shipping destination. The store-wide rate is the default; an enabled
 * override for the destination region, else for its country, replaces it. Whether prices
 * include tax always follows the store setting. `tax_enabled` stays the master switch.
 */
class StoreTaxes
{
    /**
     * @return array{rate:string,name:array{ar:string,en:string}|null,included:bool,shipping:bool,source:string,override_id:string|null,country:string|null,region_id:string|null}|null
     */
    public function resolve(Store $store, array $selection = []): ?array
    {
        if (! $store->tax_enabled) {
            return null;
        }
        $override = null;
        $overrides = $store->exists ? StoreTaxOverride::query()->where('store_id', $store->id)->where('enabled', true)->get() : collect();
        [$country, $regionId] = $overrides->isEmpty() ? [null, null] : $this->destination($store, $selection);
        if ($regionId !== null) {
            $override = $overrides->first(fn (StoreTaxOverride $row) => $row->region_id === $regionId);
        }
        if ($override === null && $country !== null) {
            $override = $overrides->first(fn (StoreTaxOverride $row) => $row->region_id === null && $row->country === $country);
        }

        return [
            'rate' => $override ? (string) $override->rate : (string) ($store->tax_rate ?: '0'),
            'name' => $override?->name,
            'included' => (bool) $store->tax_prices_include,
            'shipping' => (bool) $store->tax_shipping,
            'source' => $override ? 'override' : 'store',
            'override_id' => $override?->id,
            'country' => $country,
            'region_id' => $regionId,
        ];
    }

    /** @return array{0:string|null,1:string|null} */
    private function destination(Store $store, array $selection): array
    {
        $country = is_string($selection['shipping_country'] ?? null) && $selection['shipping_country'] !== '' ? strtoupper($selection['shipping_country']) : null;
        $regionId = is_string($selection['shipping_region_id'] ?? null) && $selection['shipping_region_id'] !== '' ? $selection['shipping_region_id'] : null;
        if ($regionId === null) {
            return [$country, null];
        }
        // A region is only trusted when the store defines it (zone region, else legacy region).
        foreach (StoreShippingZone::query()->where('store_id', $store->id)->where('enabled', true)->get() as $zone) {
            foreach ($zone->regions as $region) {
                if ($region['id'] === $regionId) {
                    return [$region['country'], $regionId];
                }
            }
        }
        $legacy = collect(app(StoreShipping::class)->configuration($store)['regions'] ?? [])->first(fn (array $region) => $region['id'] === $regionId && $region['enabled']);

        return $legacy ? [$legacy['country'], $regionId] : [$country, null];
    }
}
