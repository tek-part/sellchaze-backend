<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesShippingSettings;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreShippingRate;
use App\Models\StoreShippingZone;
use App\Models\StoreTaxOverride;
use App\Services\Commerce\PricingCalculator;
use App\Services\Commerce\StoreShipping;
use App\Services\Storefront\StorefrontService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shipping zones, zone rates and tax overrides (Shipping and taxes v2).
 *
 * Validation policy: a country may be covered country-wide by only one enabled zone, a
 * region name may appear once per country, and a band's maximum must exceed its minimum —
 * these are rejected. Overlapping bands of the same type inside a zone are allowed (e.g.
 * "Standard" and "Express" for the same weights) and returned as `warnings`.
 */
class StoreShippingZonesController extends Controller
{
    use AuthorizesShippingSettings;

    private const MONEY = ['numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'];

    public function index(Request $request, StoreShipping $shipping): JsonResponse
    {
        $store = $this->store($request);
        $zones = $shipping->zones($store, false);

        return response()->json(['data' => [
            'mode' => $zones->contains('enabled', true) ? 'zones' : 'legacy',
            'zones' => $zones->map(fn (StoreShippingZone $zone) => $this->present($zone, $shipping))->values(),
            'legacy_zone' => $this->legacyZone($store, $shipping),
            'tax_overrides' => $this->overrides($store),
            'tax_shipping' => (bool) $store->tax_shipping,
        ]]);
    }

    public function create(Request $request, StoreShipping $shipping): JsonResponse
    {
        return $this->save($request, $shipping, null);
    }

    public function update(Request $request, StoreShipping $shipping): JsonResponse
    {
        return $this->save($request, $shipping, $this->findZone($request));
    }

    public function destroy(Request $request): Response
    {
        $model = $this->findZone($request);
        DB::transaction(function () use ($model) {
            StoreTaxOverride::query()->where('store_id', $model->store_id)->whereIn('region_id', array_column($model->regions, 'id'))->delete();
            $model->delete();
        });
        StorefrontService::forgetHomepage($model->store_id);

        return response()->noContent();
    }

    public function storeOverride(Request $request): JsonResponse
    {
        return $this->saveOverride($request, null);
    }

    public function updateOverride(Request $request): JsonResponse
    {
        return $this->saveOverride($request, $this->findOverride($request));
    }

    public function destroyOverride(Request $request): Response
    {
        $this->findOverride($request)->delete();

        return response()->noContent();
    }

    /** Merchant-side "test a quote": which zone and rates an address gets, and the tax applied. */
    public function preview(Request $request, PricingCalculator $pricing): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate([
            'country' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'], 'region_id' => ['nullable', 'uuid'],
            'subtotal' => ['required', ...self::MONEY], 'weight_grams' => ['required', 'integer', 'min:0', 'max:100000000'],
            'rate_id' => ['nullable', 'uuid'], 'payment_method' => ['nullable', 'string', 'max:80'],
        ]);
        $selection = ['shipping_country' => strtoupper($data['country']), 'shipping_region_id' => $data['region_id'] ?? null,
            'shipping_rate_id' => $data['rate_id'] ?? null, 'payment_method' => $data['payment_method'] ?? null];
        $subtotal = bcadd((string) $data['subtotal'], '0', 2);
        try {
            $result = $pricing->breakdown($store, $subtotal, '0.00', $selection, true, false, false, (int) $data['weight_grams']);
        } catch (ValidationException $exception) {
            return response()->json(['data' => ['available' => false, 'errors' => $exception->errors()]]);
        }
        $details = $result['shipping']['details'] ?? [];

        return response()->json(['data' => [
            'available' => true, 'mode' => $details['mode'] ?? 'free', 'zone' => $details['zone'] ?? null, 'region' => $details['region'] ?? null,
            'rate' => $details['rate'] ?? null, 'rates' => $result['shipping']['rates'] ?? [], 'cod_fee' => $details['cod_fee'] ?? '0.00',
            'free_shipping_applied' => $details['free_shipping_applied'] ?? false, 'tax' => $result['tax'], 'totals' => $result['totals'],
        ]]);
    }

    // Route parameters are read by name: the `stores/{store}` prefix also passes the store positionally.
    private function findZone(Request $request): StoreShippingZone
    {
        $store = $this->store($request);

        return StoreShippingZone::query()->where('store_id', $store->id)->whereKey((string) $request->route('zone'))->firstOrFail();
    }

    private function findOverride(Request $request): StoreTaxOverride
    {
        $store = $this->store($request);

        return StoreTaxOverride::query()->where('store_id', $store->id)->whereKey((string) $request->route('override'))->firstOrFail();
    }

    private function save(Request $request, StoreShipping $shipping, ?StoreShippingZone $zone): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate($this->zoneRules());
        $data['countries'] = array_values(array_unique(array_map('strtoupper', $data['countries'])));
        $data['regions'] = array_map(fn (array $region) => ['id' => $region['id'] ?? (string) Str::uuid(), 'name' => ['ar' => $region['name']['ar'], 'en' => $region['name']['en']],
            'country' => strtoupper($region['country'])], $data['regions']);
        $this->assertBands($data['rates']);

        $previousRegions = $zone?->regions ?? [];
        $saved = DB::transaction(function () use ($store, $zone, $data, $previousRegions) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $others = StoreShippingZone::query()->where('store_id', $store->id)->when($zone, fn ($query) => $query->whereKeyNot($zone->id))->get();
            $this->assertCoverage($data, $others);
            $model = $zone ?? new StoreShippingZone(['store_id' => $store->id]);
            $model->fill(['name' => $data['name'], 'countries' => $data['countries'], 'regions' => $data['regions'], 'enabled' => (bool) $data['enabled'],
                'position' => (int) ($data['position'] ?? $zone?->position ?? $others->count())]);
            $model->save();
            $this->syncRates($store, $model, $data['rates']);
            // Overrides for regions removed from this zone would never match again.
            if ($zone !== null) {
                $removed = array_diff(array_column($previousRegions, 'id'), array_column($data['regions'], 'id'));
                StoreTaxOverride::query()->where('store_id', $store->id)->whereIn('region_id', $removed)->delete();
            }

            return $model->fresh('rates');
        });
        StorefrontService::forgetHomepage($store->id);

        return response()->json(['data' => $this->present($saved, $shipping), 'warnings' => $this->overlaps($saved)], $zone ? 200 : 201);
    }

    /** @return array<string, mixed> */
    private function zoneRules(): array
    {
        $name = ['required', 'string', 'max:120'];

        return [
            'name' => ['required', 'array:ar,en'], 'name.ar' => $name, 'name.en' => $name,
            'countries' => ['required', 'array', 'min:1', 'max:250'], 'countries.*' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/', 'distinct:ignore_case'],
            'regions' => ['present', 'array', 'max:500'], 'regions.*' => ['array:id,name,country'],
            'regions.*.id' => ['nullable', 'uuid', 'distinct'], 'regions.*.name' => ['required', 'array:ar,en'], 'regions.*.name.ar' => $name, 'regions.*.name.en' => $name,
            'regions.*.country' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'enabled' => ['required', 'boolean'], 'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'rates' => ['required', 'array', 'min:1', 'max:50'],
            // Read-only keys from GET (amount, etc.) may be echoed back; only the fields below are stored.
            'rates.*' => ['array'],
            'rates.*.id' => ['nullable', 'uuid', 'distinct'], 'rates.*.name' => ['required', 'array:ar,en'], 'rates.*.name.ar' => $name, 'rates.*.name.en' => $name,
            'rates.*.type' => ['required', Rule::in(StoreShippingRate::TYPES)],
            'rates.*.min_subtotal' => ['nullable', 'required_if:rates.*.type,price', ...self::MONEY],
            'rates.*.max_subtotal' => ['nullable', ...self::MONEY],
            'rates.*.min_weight_grams' => ['nullable', 'required_if:rates.*.type,weight', 'integer', 'min:0', 'max:100000000'],
            'rates.*.max_weight_grams' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'rates.*.price' => ['required', ...self::MONEY], 'rates.*.free_over' => ['nullable', ...self::MONEY], 'rates.*.cod_fee' => ['nullable', ...self::MONEY],
            'rates.*.delivery_time' => ['nullable', 'array:ar,en'], 'rates.*.delivery_time.ar' => ['nullable', 'string', 'max:120'], 'rates.*.delivery_time.en' => ['nullable', 'string', 'max:120'],
            'rates.*.enabled' => ['required', 'boolean'], 'rates.*.position' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }

    private function assertBands(array $rates): void
    {
        $errors = [];
        foreach ($rates as $index => $rate) {
            if ($rate['type'] === 'price' && isset($rate['max_subtotal']) && bccomp((string) $rate['max_subtotal'], (string) $rate['min_subtotal'], 2) <= 0) {
                $errors["rates.$index.max_subtotal"] = __('The maximum must be greater than the minimum.');
            }
            if ($rate['type'] === 'weight' && isset($rate['max_weight_grams']) && (int) $rate['max_weight_grams'] <= (int) $rate['min_weight_grams']) {
                $errors["rates.$index.max_weight_grams"] = __('The maximum must be greater than the minimum.');
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param Collection<int, StoreShippingZone> $others */
    private function assertCoverage(array $data, Collection $others): void
    {
        $errors = [];
        $regionIds = $others->flatMap(fn (StoreShippingZone $zone) => array_column($zone->regions, 'id'))->all();
        $seen = [];
        foreach ($others as $other) {
            foreach ($other->regions as $region) {
                $seen[$region['country'].':'.mb_strtolower(trim($region['name']['en']))] = true;
                $seen[$region['country'].':'.mb_strtolower(trim($region['name']['ar']))] = true;
            }
        }
        foreach ($data['regions'] as $index => $region) {
            if (! in_array($region['country'], $data['countries'], true)) {
                $errors["regions.$index.country"] = __('Add the region\'s country to the zone first.');
            }
            if (in_array($region['id'], $regionIds, true)) {
                $errors["regions.$index.id"] = __('This region belongs to another zone.');
            }
            $keys = [$region['country'].':'.mb_strtolower(trim($region['name']['en'])), $region['country'].':'.mb_strtolower(trim($region['name']['ar']))];
            if (isset($seen[$keys[0]]) || isset($seen[$keys[1]])) {
                $errors["regions.$index.name"] = __('This region already exists in another zone or twice in this zone.');
            }
            foreach (array_unique($keys) as $key) {
                $seen[$key] = true;
            }
        }
        if ((bool) $data['enabled']) {
            foreach ($data['countries'] as $index => $country) {
                if (collect($data['regions'])->contains('country', $country)) {
                    continue;
                }
                $taken = $others->first(fn (StoreShippingZone $zone) => $zone->enabled && in_array($country, $zone->countries, true) && ! collect($zone->regions)->contains('country', $country));
                if ($taken !== null) {
                    $errors["countries.$index"] = __('This country is already covered by the zone ":zone".', ['zone' => $taken->name[app()->getLocale()] ?? $taken->name['en']]);
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function syncRates(Store $store, StoreShippingZone $zone, array $rates): void
    {
        $existing = StoreShippingRate::query()->where('zone_id', $zone->id)->get()->keyBy('id');
        $keep = [];
        foreach (array_values($rates) as $index => $row) {
            $id = $row['id'] ?? null;
            $rate = $id !== null ? $existing->get($id) : null;
            if ($id !== null && $rate === null && StoreShippingRate::query()->whereKey($id)->exists()) {
                throw ValidationException::withMessages(["rates.$index.id" => __('This rate belongs to another zone.')]);
            }
            $rate ??= new StoreShippingRate(['store_id' => $store->id, 'zone_id' => $zone->id]);
            if ($id !== null && ! $rate->exists) {
                $rate->id = $id;
            }
            $price = $row['type'] === 'price';
            $weight = $row['type'] === 'weight';
            $rate->fill([
                'name' => ['ar' => $row['name']['ar'], 'en' => $row['name']['en']], 'type' => $row['type'],
                'min_subtotal' => $price ? bcadd((string) $row['min_subtotal'], '0', 2) : null,
                'max_subtotal' => $price && isset($row['max_subtotal']) ? bcadd((string) $row['max_subtotal'], '0', 2) : null,
                'min_weight_grams' => $weight ? (int) $row['min_weight_grams'] : null,
                'max_weight_grams' => $weight && isset($row['max_weight_grams']) ? (int) $row['max_weight_grams'] : null,
                'price' => bcadd((string) $row['price'], '0', 2),
                'free_over' => isset($row['free_over']) ? bcadd((string) $row['free_over'], '0', 2) : null,
                'cod_fee' => isset($row['cod_fee']) && bccomp((string) $row['cod_fee'], '0', 2) > 0 ? bcadd((string) $row['cod_fee'], '0', 2) : null,
                'delivery_time' => ['ar' => (string) ($row['delivery_time']['ar'] ?? ''), 'en' => (string) ($row['delivery_time']['en'] ?? '')],
                'enabled' => (bool) $row['enabled'], 'position' => (int) ($row['position'] ?? $index),
            ])->save();
            $keep[] = $rate->id;
        }
        StoreShippingRate::query()->where('zone_id', $zone->id)->whereNotIn('id', $keep)->delete();
    }

    /** @return list<array{type:string,band:string,rates:list<string>}> */
    private function overlaps(StoreShippingZone $zone): array
    {
        $warnings = [];
        $rates = $zone->rates->where('enabled', true)->whereIn('type', ['price', 'weight'])->values();
        foreach ($rates as $i => $a) {
            foreach ($rates->slice($i + 1) as $b) {
                if ($a->type !== $b->type) {
                    continue;
                }
                [$minA, $maxA, $minB, $maxB] = $a->type === 'price'
                    ? [(float) $a->min_subtotal, $a->max_subtotal === null ? INF : (float) $a->max_subtotal, (float) $b->min_subtotal, $b->max_subtotal === null ? INF : (float) $b->max_subtotal]
                    : [(float) $a->min_weight_grams, $a->max_weight_grams ?? INF, (float) $b->min_weight_grams, $b->max_weight_grams ?? INF];
                if ($minA < $maxB && $minB < $maxA) {
                    $warnings[] = ['type' => 'overlapping_bands', 'band' => $a->type, 'rates' => [$a->id, $b->id]];
                }
            }
        }

        return $warnings;
    }

    private function present(StoreShippingZone $zone, StoreShipping $shipping): array
    {
        return ['id' => $zone->id, 'name' => $zone->name, 'countries' => $zone->countries, 'regions' => $zone->regions, 'enabled' => $zone->enabled, 'position' => $zone->position,
            'rates' => $zone->rates->map(fn (StoreShippingRate $rate) => $shipping->presentRate($rate) + ['enabled' => $rate->enabled, 'position' => $rate->position])->values()->all()];
    }

    /** The current simple settings, shown read-only as the store's implicit default zone. */
    private function legacyZone(Store $store, StoreShipping $shipping): array
    {
        $config = $shipping->configuration($store);
        $regions = $config['regions_enabled'] ? collect($config['regions'])->where('enabled', true)->sortBy('position')->values() : collect();
        $options = collect($config['options'])->where('enabled', true)->sortByDesc('priority')->values();

        return ['id' => 'default', 'implicit' => true, 'shipping_enabled' => (bool) $store->shipping_enabled,
            'countries' => $regions->pluck('country')->unique()->values()->all(),
            'regions' => $regions->map(fn (array $region) => ['id' => $region['id'], 'name' => $region['name'], 'country' => $region['country'], 'rate' => $region['rate']])->all(),
            'rates' => $options->isNotEmpty()
                ? $options->map(fn (array $option) => ['id' => $option['id'], 'name' => $option['name'], 'type' => 'flat', 'price' => $option['rate'], 'free_over' => $store->shipping_free_over])->all()
                : [['id' => null, 'name' => null, 'type' => 'flat', 'price' => (string) ($store->shipping_flat_rate ?: '0.00'), 'free_over' => $store->shipping_free_over]],
        ];
    }

    private function overrides(Store $store): array
    {
        return StoreTaxOverride::query()->where('store_id', $store->id)->orderBy('country')->orderBy('created_at')->get()
            ->map(fn (StoreTaxOverride $row) => ['id' => $row->id, 'country' => $row->country, 'region_id' => $row->region_id, 'name' => $row->name, 'rate' => (string) $row->rate, 'enabled' => $row->enabled])->all();
    }

    private function saveOverride(Request $request, ?StoreTaxOverride $override): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate([
            'country' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'], 'region_id' => ['nullable', 'uuid'],
            'name' => ['required', 'array:ar,en'], 'name.ar' => ['required', 'string', 'max:60'], 'name.en' => ['required', 'string', 'max:60'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,3'], 'enabled' => ['required', 'boolean'],
        ]);
        $country = strtoupper($data['country']);
        $regionId = $data['region_id'] ?? null;
        if ($regionId !== null) {
            $region = app(StoreShipping::class)->zones($store, false)->flatMap(fn (StoreShippingZone $zone) => $zone->regions)->firstWhere('id', $regionId)
                ?? collect(app(StoreShipping::class)->configuration($store)['regions'] ?? [])->firstWhere('id', $regionId);
            if ($region === null || $region['country'] !== $country) {
                throw ValidationException::withMessages(['region_id' => __('Choose a delivery region in the selected country.')]);
            }
        }
        $duplicate = StoreTaxOverride::query()->where('store_id', $store->id)->where('country', $country)
            ->when($regionId === null, fn ($query) => $query->whereNull('region_id'), fn ($query) => $query->where('region_id', $regionId))
            ->when($override, fn ($query) => $query->whereKeyNot($override->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['country' => __('A tax override already exists for this destination.')]);
        }
        $model = $override ?? new StoreTaxOverride(['store_id' => $store->id]);
        $model->fill(['country' => $country, 'region_id' => $regionId, 'name' => ['ar' => $data['name']['ar'], 'en' => $data['name']['en']],
            'rate' => bcadd((string) $data['rate'], '0', 3), 'enabled' => (bool) $data['enabled']])->save();

        return response()->json(['data' => ['id' => $model->id, 'country' => $model->country, 'region_id' => $model->region_id, 'name' => $model->name, 'rate' => (string) $model->fresh()?->rate, 'enabled' => $model->enabled]], $override ? 200 : 201);
    }
}
