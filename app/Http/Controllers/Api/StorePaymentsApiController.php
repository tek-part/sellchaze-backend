<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreMediaAsset;
use App\Models\StorePaymentGateway;
use App\Services\Commerce\HostedPaymentGateways;
use App\Services\Commerce\PaymentGatewayCatalog;
use App\Services\Commerce\PaymentPriceAdjustment;
use App\Services\Commerce\RegionalHostedPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StorePaymentsApiController extends Controller
{
    private const MASK = '********';

    public function index(Request $request): JsonResponse
    {
        $store = $this->resolveStore($request);
        $saved = StorePaymentGateway::query()->where('store_id', $store->id)->get()->keyBy('gateway');
        $legacy = collect(data_get($store->theme_settings, 'payment_gateways', []))->keyBy('slug');

        $rows = collect(PaymentGatewayCatalog::all())->map(function (array $provider, string $slug) use ($saved, $legacy, $store) {
            $setting = $saved->get($slug);
            $legacySetting = $legacy->get($slug) ?? $legacy->get(str_replace('_', '-', $slug));
            $credentials = $setting?->credentials ?? data_get($legacySetting, 'credentials', []);

            return [
                'name' => $provider['name'], 'slug' => $slug, 'icon_class' => 'fas fa-credit-card',
                'credential_fields' => $provider['fields'],
                'visible_fields' => PaymentGatewayCatalog::VISIBLE_FIELDS,
                'required_fields' => PaymentGatewayCatalog::required($slug),
                'available' => $provider['available'],
                'provider_name' => $provider['name'],
                ...PaymentGatewayCatalog::presentation($setting ?? (new StorePaymentGateway)->forceFill(['store_id' => $store->id, 'gateway' => $slug, 'notes' => data_get($legacySetting, 'notes', '')])),
                'enabled' => (bool) ($setting?->enabled ?? data_get($legacySetting, 'enabled', false)),
                'test_mode' => (bool) ($setting?->test_mode ?? data_get($legacySetting, 'test_mode', true)),
                'credentials' => collect($credentials)->only($provider['fields'])->map(fn ($value, $key) => in_array($key, PaymentGatewayCatalog::VISIBLE_FIELDS, true) ? $value : (filled($value) ? self::MASK : ''))->all(),
                'order' => (int) ($setting?->sort_order ?? data_get($legacySetting, 'order', 0)),
                'notes' => (string) ($setting?->notes ?? data_get($legacySetting, 'notes', '')),
                'configured' => PaymentGatewayCatalog::configured($slug, $credentials),
                'return_url' => $slug === 'xpay' ? route('payments.xpay.return', ['store' => $store->id, 'payment_key' => XpayPaymentReturnController::key($store)]) : null,
                'webhook_url' => in_array($slug, ['stripe', 'paypal', 'tabby', 'tamara', 'paymob', 'tap', 'fawaterak'], true)
                    ? url('/api/v1/payment-webhooks/'.$store->id.'/'.$slug.($slug === 'fawaterak' ? '_json' : ''))
                    : null,
            ];
        })->sortBy('order')->values();

        return response()->json(['data' => $rows]);
    }

    public function update(Request $request): JsonResponse
    {
        $store = $this->resolveStore($request);
        $validated = $request->validate([
            'gateways' => ['required', 'array', 'min:1'],
            'gateways.*.slug' => ['required', 'string', 'distinct', Rule::in(array_keys(PaymentGatewayCatalog::all()))],
            'gateways.*.enabled' => ['required', 'boolean'],
            'gateways.*.test_mode' => ['nullable', 'boolean'],
            'gateways.*.credentials' => ['nullable', 'array'],
            'gateways.*.credentials.*' => ['nullable', 'string', 'max:5000'],
            'gateways.*.order' => ['nullable', 'integer', 'min:0'],
            'gateways.*.notes' => ['nullable', 'string', 'max:2000'],
            'gateways.*.presentation' => ['sometimes', 'array:name,description,logo_asset_id,price_adjustment'],
            'gateways.*.presentation.price_adjustment' => ['sometimes', 'array:enabled,type,direction,value'],
            'gateways.*.presentation.name' => ['nullable', 'string', 'max:120'],
            'gateways.*.presentation.description' => ['nullable', 'string', 'max:2000'],
            'gateways.*.presentation.logo_asset_id' => ['nullable', 'integer', Rule::exists('store_media_assets', 'id')->where(fn ($q) => $q->where('store_id', $store->id)->whereNull('deleted_at'))],
        ]);

        DB::transaction(function () use ($store, $validated) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            foreach ($validated['gateways'] as $position => $input) {
                $current = StorePaymentGateway::query()->where('store_id', $store->id)->where('gateway', $input['slug'])->first();
                $legacy = collect(data_get($store->theme_settings, 'payment_gateways', []))->first(fn ($row) => in_array(data_get($row, 'slug'), [$input['slug'], str_replace('_', '-', $input['slug'])], true));
                $credentials = $current?->credentials ?? data_get($legacy, 'credentials', []);
                $testMode = $input['test_mode'] ?? $current?->test_mode ?? data_get($legacy, 'test_mode', true);
                $provider = PaymentGatewayCatalog::all()[$input['slug']];
                $presentation = array_replace($current?->presentation ?? [], $input['presentation'] ?? []);
                if (array_key_exists('price_adjustment', $input['presentation'] ?? [])) {
                    try {
                        $presentation['price_adjustment'] = PaymentPriceAdjustment::configuration($input['presentation']['price_adjustment']);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages(['gateways.'.$position.'.presentation.price_adjustment' => $exception->validator->errors()->first()]);
                    }
                }
                if ($input['enabled'] && ! $provider['available']) {
                    throw ValidationException::withMessages(['gateways.'.$position.'.enabled' => 'This provider connection is not available yet.']);
                }
                if ($id = ($input['presentation']['logo_asset_id'] ?? null)) {
                    $asset = StoreMediaAsset::query()->where('store_id', $store->id)->findOrFail($id);
                    if (! in_array($asset->mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
                        throw ValidationException::withMessages(['gateways.'.$position.'.presentation.logo_asset_id' => 'Choose a supported image from this store.']);
                    }
                }
                foreach (($input['credentials'] ?? []) as $key => $value) {
                    if (in_array($key, $provider['fields'], true) && $value !== self::MASK && filled($value)) {
                        $credentials[$key] = $value;
                    }
                }
                $invalid = array_diff(array_keys($input['credentials'] ?? []), $provider['fields']);
                if ($invalid) {
                    throw ValidationException::withMessages(['gateways.'.$position.'.credentials' => 'Unknown payment credential fields.']);
                }
                if ($input['enabled'] && in_array($input['slug'], HostedPaymentGateways::PROVIDERS, true)) {
                    app(RegionalHostedPayments::class)->validate((new StorePaymentGateway)->forceFill(['gateway' => $input['slug'], 'test_mode' => $testMode, 'credentials' => $credentials]), $store->currency);
                }
                if ($input['enabled'] && ! PaymentGatewayCatalog::configured($input['slug'], $credentials)) {
                    throw ValidationException::withMessages(['gateways.'.$position.'.credentials' => 'Complete the required connection fields before enabling this payment method.']);
                }
                StorePaymentGateway::query()->updateOrCreate(
                    ['store_id' => $store->id, 'gateway' => $input['slug']],
                    ['enabled' => $input['enabled'], 'test_mode' => $testMode, 'credentials' => $credentials,
                        'sort_order' => $input['order'] ?? $position, 'notes' => $input['notes'] ?? null,
                        'presentation' => $presentation ?: null]
                );
            }
        });

        return $this->index($request);
    }

    private function resolveStore(Request $request): Store
    {
        $store = $request->attributes->get('store') ?? $request->route('store');
        abort_unless($store instanceof Store, 404, 'Store not found.');

        return $store;
    }
}
