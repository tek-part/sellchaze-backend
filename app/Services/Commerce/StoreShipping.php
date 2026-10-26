<?php

namespace App\Services\Commerce;

use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreMediaAsset;
use App\Models\StoreShippingRate;
use App\Models\StoreShippingZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Resolves delivery selections from the current store, never from client-supplied prices.
 *
 * Two modes:
 *  - Legacy (no enabled zone): the store-wide flat rate, free-over threshold, regions and
 *    named options. This is the implicit default zone and behaves exactly as before zones.
 *  - Zones (at least one enabled zone): the zone is picked from the shipping address
 *    (a zone region first, then a country-wide zone) and the shopper chooses one of the
 *    zone's rates that is eligible for the cart (subtotal after discounts, total weight).
 */
class StoreShipping
{
    public const SELECTION_RULES = [
        'shipping_region_id' => ['nullable', 'uuid'],
        'shipping_option_id' => ['nullable', 'uuid'],
        'shipping_rate_id' => ['nullable', 'uuid'],
        'shipping_country' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
    ];

    public function configuration(Store $store): array
    {
        return $store->shipping_configuration ?? ['regions_enabled' => false, 'auto_select_region' => true, 'regions' => [], 'options' => []];
    }

    /** Legacy region picker; zones replace it with country + zone region selection. */
    public function regionsEnabled(Store $store): bool
    {
        return (bool) $store->shipping_enabled && $this->configuration($store)['regions_enabled'] && ! $this->zonesEnabled($store);
    }

    public function zonesEnabled(Store $store): bool
    {
        // An unsaved store (pricing previews, unit tests) cannot own zones.
        return $store->exists && StoreShippingZone::query()->where('store_id', $store->id)->where('enabled', true)->exists();
    }

    /** @return Collection<int, StoreShippingZone> */
    public function zones(Store $store, bool $enabledOnly = true): Collection
    {
        if (! $store->exists) {
            return new Collection;
        }

        return StoreShippingZone::query()->where('store_id', $store->id)
            ->when($enabledOnly, fn ($query) => $query->where('enabled', true))
            ->with('rates')->orderBy('position')->orderBy('created_at')->get();
    }

    /** @param array{country?:string|null,region_id?:string|null} $address */
    public function publicConfiguration(Store $store, array $address = []): array
    {
        $config = $this->configuration($store);
        $zones = $store->shipping_enabled ? $this->zones($store) : collect();
        if ($zones->isEmpty()) {
            return [
                'enabled' => (bool) $store->shipping_enabled,
                'regions_enabled' => $this->regionsEnabled($store),
                'auto_select_region' => $config['auto_select_region'],
                'currency' => $store->currency ?: 'USD',
                'flat_rate' => (string) ($store->shipping_flat_rate ?: '0.00'),
                'free_over' => $store->shipping_free_over,
                'regions' => $this->regionsEnabled($store) ? collect($config['regions'])->where('enabled', true)->sortBy('position')->values()->all() : [],
                'options' => $store->shipping_enabled ? $this->withIcons($store, collect($config['options'])->where('enabled', true)->sortByDesc('priority')->values()->all()) : [],
                'zones_enabled' => false,
            ];
        }
        $match = $this->matchZone($zones, $address['country'] ?? null, $address['region_id'] ?? null);

        return [
            'enabled' => true,
            'regions_enabled' => false,
            'auto_select_region' => false,
            'currency' => $store->currency ?: 'USD',
            'flat_rate' => '0.00',
            // Thresholds belong to each zone rate once zones are in use.
            'free_over' => null,
            'regions' => [],
            'options' => [],
            'zones_enabled' => true,
            'countries' => $this->countries($zones),
            'zone_regions' => $zones->flatMap(fn (StoreShippingZone $zone) => $zone->regions)->sortBy(fn (array $region) => $region['country'].':'.$region['name']['en'])->values()->all(),
            'zone' => $match['zone'] ? ['id' => $match['zone']->id, 'name' => $match['zone']->name] : null,
            'rates' => $match['zone'] ? $match['zone']->rates->where('enabled', true)->map(fn (StoreShippingRate $rate) => $this->presentRate($rate))->values()->all() : [],
        ];
    }

    /** @return list<array{code:string,region_required:bool}> */
    private function countries(Collection $zones): array
    {
        $countries = [];
        foreach ($zones as $zone) {
            foreach ($zone->countries as $country) {
                $wide = ! collect($zone->regions)->contains('country', $country);
                $countries[$country] = ($countries[$country] ?? false) || $wide;
            }
        }
        ksort($countries);

        return array_map(fn (string $code, bool $wide) => ['code' => $code, 'region_required' => ! $wide], array_keys($countries), array_values($countries));
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

    /**
     * Picks the zone for an address: a zone region wins, then a zone that covers the whole
     * country. A zone that lists regions for a country only serves those regions.
     *
     * @param  Collection<int, StoreShippingZone>  $zones
     * @return array{zone: StoreShippingZone|null, region: array<string,mixed>|null, country: string|null, error: array{0:string,1:string}|null}
     */
    public function matchZone(Collection $zones, ?string $country, ?string $regionId): array
    {
        $country = $country !== null && $country !== '' ? strtoupper($country) : null;
        if ($regionId !== null && $regionId !== '') {
            foreach ($zones as $zone) {
                foreach ($zone->regions as $region) {
                    if ($region['id'] === $regionId) {
                        return $country !== null && $country !== $region['country']
                            ? ['zone' => null, 'region' => null, 'country' => $country, 'error' => ['shipping_region_id', 'Choose a delivery region in the selected country.']]
                            : ['zone' => $zone, 'region' => $region, 'country' => $region['country'], 'error' => null];
                    }
                }
            }

            return ['zone' => null, 'region' => null, 'country' => $country, 'error' => ['shipping_region_id', 'Choose an available delivery region.']];
        }
        if ($country === null) {
            return ['zone' => null, 'region' => null, 'country' => null, 'error' => ['shipping_country', 'Choose a delivery country.']];
        }
        $zone = $zones->first(fn (StoreShippingZone $zone) => in_array($country, $zone->countries, true) && ! collect($zone->regions)->contains('country', $country));
        if ($zone !== null) {
            return ['zone' => $zone, 'region' => null, 'country' => $country, 'error' => null];
        }
        $regional = $zones->contains(fn (StoreShippingZone $zone) => collect($zone->regions)->contains('country', $country));

        return ['zone' => null, 'region' => null, 'country' => $country,
            'error' => $regional ? ['shipping_region_id', 'Choose your delivery region.'] : ['shipping_country', 'We do not deliver to this country yet.']];
    }

    public function eligible(StoreShippingRate $rate, string $subtotal, int $weightGrams): bool
    {
        return match ($rate->type) {
            'price' => bccomp($subtotal, (string) ($rate->min_subtotal ?? '0'), 2) >= 0 && ($rate->max_subtotal === null || bccomp($subtotal, (string) $rate->max_subtotal, 2) < 0),
            'weight' => $weightGrams >= (int) $rate->min_weight_grams && ($rate->max_weight_grams === null || $weightGrams < $rate->max_weight_grams),
            default => true,
        };
    }

    /** Public rate shape; `amount` is the shipping price for the given subtotal (0.00 when free). */
    public function presentRate(StoreShippingRate $rate, ?string $subtotal = null, bool $free = false): array
    {
        $price = bcadd((string) $rate->price, '0', 2);
        $free = $free || ($subtotal !== null && $rate->free_over !== null && bccomp($subtotal, (string) $rate->free_over, 2) >= 0);

        return ['id' => $rate->id, 'name' => $rate->name, 'type' => $rate->type,
            'min_subtotal' => $rate->min_subtotal, 'max_subtotal' => $rate->max_subtotal,
            'min_weight_grams' => $rate->min_weight_grams, 'max_weight_grams' => $rate->max_weight_grams,
            'price' => $price, 'amount' => $free ? '0.00' : $price, 'free_over' => $rate->free_over,
            'delivery_time' => $rate->delivery_time ?? ['ar' => '', 'en' => ''], 'cod_fee' => $rate->cod_fee];
    }

    /** Units weigh their variant weight, else the product weight (kg); digital lines weigh nothing. */
    public static function lineWeightGrams(Product $product, ?ProductVariant $variant, int $quantity): int
    {
        if ($product->digital_type !== null && $product->digital_type !== 'physical') {
            return 0;
        }
        $kilograms = (string) ($variant?->weight ?? $product->weight ?? '0');

        return (int) round((float) bcmul($kilograms, '1000', 3)) * $quantity;
    }

    public function cartWeightGrams(Cart $cart): int
    {
        $items = $cart->items()->get();
        $products = Product::query()->where('store_id', $cart->store_id)->whereIn('id', $items->pluck('store_product_id'))->get()->keyBy('id');
        $variants = ProductVariant::query()->whereIn('id', $items->pluck('variant_id')->filter())->get()->keyBy('id');
        $grams = 0;
        foreach ($items as $item) {
            $product = $products->get($item->store_product_id);
            if ($product !== null) {
                $grams += self::lineWeightGrams($product, $item->variant_id ? $variants->get($item->variant_id) : null, (int) $item->quantity);
            }
        }

        return $grams;
    }

    /** Rejects missing, removed or foreign delivery choices before any cart or order change. */
    public function validateSelection(Store $store, array $selection): void
    {
        if (! $store->shipping_enabled) {
            return;
        }
        $zones = $this->zones($store);
        if ($zones->isEmpty()) {
            $this->quote($store, '0.00', $selection);

            return;
        }
        $this->assertNoLegacyOption($selection);
        $match = $this->matchZone($zones, $selection['shipping_country'] ?? null, $selection['shipping_region_id'] ?? null);
        if ($match['error'] !== null) {
            throw ValidationException::withMessages([$match['error'][0] => __($match['error'][1])]);
        }
        $rateId = $selection['shipping_rate_id'] ?? null;
        if (! empty($rateId) && ! $match['zone']->rates->contains(fn (StoreShippingRate $rate) => $rate->enabled && $rate->id === $rateId)) {
            throw ValidationException::withMessages(['shipping_rate_id' => __('Choose an available delivery rate.')]);
        }
    }

    /** @return array{amount:string,address:array<string,mixed>,details:array<string,mixed>,rates?:list<array<string,mixed>>} */
    public function quote(Store $store, string $discountedSubtotal, array $selection = [], bool $offerFreeShipping = false, bool $productFreeShipping = false, int $weightGrams = 0): array
    {
        if (! $store->shipping_enabled) {
            return ['amount' => '0.00', 'address' => [], 'details' => ['mode' => 'free', 'base_rate' => '0.00', 'free_shipping_applied' => false]];
        }
        $zones = $this->zones($store);
        if ($zones->isNotEmpty()) {
            return $this->zoneQuote($store, $zones, $discountedSubtotal, $selection, $offerFreeShipping, $productFreeShipping, $weightGrams);
        }
        if (! empty($selection['shipping_rate_id'])) {
            throw ValidationException::withMessages(['shipping_rate_id' => __('Delivery rates have changed. Please refresh the delivery options.')]);
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
        $free = $offerFreeShipping || $productFreeShipping || ($store->shipping_free_over !== null && bccomp($discountedSubtotal, (string) $store->shipping_free_over, 2) >= 0);
        $details = ['mode' => $mode, 'base_rate' => bcadd($rate, '0', 2), 'free_shipping_applied' => $free,
            'region' => $region ? ['id' => $region['id'], 'name' => $region['name'], 'country' => $region['country']] : null,
            'option' => $option ? ['id' => $option['id'], 'name' => $option['name'], 'description' => $option['description']] : null];
        if ($offerFreeShipping) {
            $details['quantity_offer_free_shipping'] = true;
        }
        if ($productFreeShipping) {
            $details['product_free_shipping'] = true;
        }

        return ['amount' => $free ? '0.00' : bcadd($rate, '0', 2), 'address' => $address, 'details' => $details];
    }

    /**
     * @param  Collection<int, StoreShippingZone>  $zones
     * @return array{amount:string,address:array<string,mixed>,details:array<string,mixed>,rates:list<array<string,mixed>>}
     */
    private function zoneQuote(Store $store, Collection $zones, string $subtotal, array $selection, bool $offerFreeShipping, bool $productFreeShipping, int $weightGrams): array
    {
        $this->assertNoLegacyOption($selection);
        $match = $this->matchZone($zones, $selection['shipping_country'] ?? null, $selection['shipping_region_id'] ?? null);
        if ($match['error'] !== null) {
            throw ValidationException::withMessages([$match['error'][0] => __($match['error'][1])]);
        }
        $zone = $match['zone'];
        $region = $match['region'];
        $eligible = $zone->rates->filter(fn (StoreShippingRate $rate) => $rate->enabled && $this->eligible($rate, $subtotal, $weightGrams))
            ->sort(fn (StoreShippingRate $a, StoreShippingRate $b) => bccomp((string) $a->price, (string) $b->price, 2) ?: $a->position <=> $b->position)->values();
        $rateId = $selection['shipping_rate_id'] ?? null;
        if (! empty($rateId)) {
            $rate = $eligible->first(fn (StoreShippingRate $row) => $row->id === $rateId);
            if ($rate === null) {
                $known = $zone->rates->contains(fn (StoreShippingRate $row) => $row->enabled && $row->id === $rateId);
                throw ValidationException::withMessages(['shipping_rate_id' => $known ? __('This delivery rate is not available for your order.') : __('Choose an available delivery rate.')]);
            }
        } else {
            // Like the legacy default option: an untouched form gets the cheapest eligible rate.
            $rate = $eligible->first();
            if ($rate === null) {
                throw ValidationException::withMessages(['shipping_rate_id' => __('No delivery rate is available for this order.')]);
            }
        }
        $thresholdFree = $rate->free_over !== null && bccomp($subtotal, (string) $rate->free_over, 2) >= 0;
        $free = $offerFreeShipping || $productFreeShipping || $thresholdFree;
        $codFee = ($selection['payment_method'] ?? null) === 'cod' && $rate->cod_fee !== null ? bcadd((string) $rate->cod_fee, '0', 2) : '0.00';
        $price = bcadd((string) $rate->price, '0', 2);
        $address = ['country' => $match['country']];
        if ($region !== null) {
            $address['state'] = $this->label($region['name'], $store);
        }
        $address['delivery_option'] = $this->label($rate->name, $store);
        $details = ['mode' => 'zone', 'base_rate' => $price, 'free_shipping_applied' => $free,
            'zone' => ['id' => $zone->id, 'name' => $zone->name],
            'region' => $region ? ['id' => $region['id'], 'name' => $region['name'], 'country' => $region['country']] : null,
            'rate' => ['id' => $rate->id, 'name' => $rate->name, 'type' => $rate->type, 'delivery_time' => $rate->delivery_time],
            'country' => $match['country'], 'weight_grams' => $weightGrams, 'cod_fee' => $codFee];
        if ($offerFreeShipping) {
            $details['quantity_offer_free_shipping'] = true;
        }
        if ($productFreeShipping) {
            $details['product_free_shipping'] = true;
        }

        return ['amount' => bcadd($free ? '0.00' : $price, $codFee, 2), 'address' => $address, 'details' => $details,
            'rates' => $eligible->map(fn (StoreShippingRate $row) => $this->presentRate($row, $subtotal, $offerFreeShipping || $productFreeShipping))->all()];
    }

    private function assertNoLegacyOption(array $selection): void
    {
        if (! empty($selection['shipping_option_id'])) {
            throw ValidationException::withMessages(['shipping_option_id' => __('Shipping options have changed. Please refresh the delivery options.')]);
        }
    }

    private function label(array $value, Store $store): string
    {
        return $value[app()->getLocale()] ?? $value[$store->default_locale ?: 'en'] ?? $value['en'];
    }
}
