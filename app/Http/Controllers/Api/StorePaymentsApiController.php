<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StorePaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StorePaymentsApiController extends Controller
{
    private const MASK = '********';

    private const PROVIDERS = [
        'cod' => ['name' => 'Cash on delivery', 'icon_class' => 'fas fa-money-bill-wave', 'fields' => []],
        'bank_transfer' => ['name' => 'Bank transfer', 'icon_class' => 'fas fa-building-columns', 'fields' => ['account_name', 'bank_name', 'iban', 'swift_code']],
        'stripe' => ['name' => 'Stripe', 'icon_class' => 'fab fa-stripe', 'fields' => ['publishable_key', 'secret_key', 'webhook_secret']],
        'paypal' => ['name' => 'PayPal', 'icon_class' => 'fab fa-paypal', 'fields' => ['client_id', 'client_secret', 'webhook_id']],
        'tabby' => ['name' => 'Tabby', 'icon_class' => 'fas fa-wallet', 'fields' => ['public_key', 'secret_key', 'merchant_code']],
        'tamara' => ['name' => 'Tamara', 'icon_class' => 'fas fa-wallet', 'fields' => ['api_token', 'notification_token', 'public_key']],
        'paymob' => ['name' => 'Paymob', 'icon_class' => 'fas fa-credit-card', 'fields' => ['secret_key', 'public_key', 'integration_id', 'hmac_secret', 'region']],
        'fawry' => ['name' => 'Fawry', 'icon_class' => 'fas fa-receipt', 'fields' => ['merchant_code', 'security_key']],
        'fawaterak' => ['name' => 'Fawaterak', 'icon_class' => 'fas fa-file-invoice-dollar', 'fields' => ['api_key', 'vendor_key']],
        'tap' => ['name' => 'Tap Payments', 'icon_class' => 'fas fa-credit-card', 'fields' => ['public_key', 'secret_key']],
        'paytabs' => ['name' => 'PayTabs', 'icon_class' => 'fas fa-credit-card', 'fields' => ['profile_id', 'server_key', 'client_key', 'region']],
        'hyperpay' => ['name' => 'HyperPay', 'icon_class' => 'fas fa-credit-card', 'fields' => ['entity_id', 'access_token', 'brands']],
    ];

    public function index(Request $request): JsonResponse
    {
        $store = $this->resolveStore($request);
        $saved = StorePaymentGateway::query()->where('store_id', $store->id)->get()->keyBy('gateway');
        $legacy = collect(data_get($store->theme_settings, 'payment_gateways', []))->keyBy('slug');

        $rows = collect(self::PROVIDERS)->map(function (array $provider, string $slug) use ($saved, $legacy, $store) {
            $setting = $saved->get($slug);
            $legacySetting = $legacy->get($slug) ?? $legacy->get(str_replace('_', '-', $slug));
            $credentials = $setting?->credentials ?? data_get($legacySetting, 'credentials', []);

            return [
                'name' => $provider['name'], 'slug' => $slug, 'icon_class' => $provider['icon_class'],
                'credential_fields' => $provider['fields'],
                'enabled' => (bool) ($setting?->enabled ?? data_get($legacySetting, 'enabled', false)),
                'test_mode' => (bool) ($setting?->test_mode ?? data_get($legacySetting, 'test_mode', true)),
                'credentials' => collect($credentials)->map(fn ($value) => filled($value) ? self::MASK : '')->all(),
                'order' => (int) ($setting?->sort_order ?? data_get($legacySetting, 'order', 0)),
                'notes' => (string) ($setting?->notes ?? data_get($legacySetting, 'notes', '')),
                'configured' => collect($credentials)->contains(fn ($value) => filled($value)),
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
            'gateways.*.slug' => ['required', 'string', Rule::in(array_keys(self::PROVIDERS))],
            'gateways.*.enabled' => ['required', 'boolean'],
            'gateways.*.test_mode' => ['nullable', 'boolean'],
            'gateways.*.credentials' => ['nullable', 'array'],
            'gateways.*.credentials.*' => ['nullable', 'string', 'max:5000'],
            'gateways.*.order' => ['nullable', 'integer', 'min:0'],
            'gateways.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($store, $validated) {
            foreach ($validated['gateways'] as $position => $input) {
                $current = StorePaymentGateway::query()->where('store_id', $store->id)->where('gateway', $input['slug'])->first();
                $credentials = $current?->credentials ?? [];
                foreach (($input['credentials'] ?? []) as $key => $value) {
                    if ($value !== self::MASK) {
                        $credentials[$key] = $value;
                    }
                }
                StorePaymentGateway::query()->updateOrCreate(
                    ['store_id' => $store->id, 'gateway' => $input['slug']],
                    ['enabled' => $input['enabled'], 'test_mode' => $input['test_mode'] ?? true, 'credentials' => $credentials,
                        'sort_order' => $input['order'] ?? $position, 'notes' => $input['notes'] ?? null]
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
