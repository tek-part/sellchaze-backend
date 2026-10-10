<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Services\Commerce\DigitalDeliverySettings;
use App\Services\Commerce\StoreDigitalWhatsappClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreDigitalDeliveryController extends Controller
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store, 404);
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.settings.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    public function index(Request $request, DigitalDeliverySettings $settings): JsonResponse
    {
        $store = $this->store($request);
        $credentials = $store->digital_delivery_credentials ?? [];

        return response()->json(['data' => $settings->configured($store), 'variables' => DigitalDeliverySettings::VARIABLES,
            'connection' => ['provider' => 'wawp_v2', 'has_token' => ! empty($credentials['access_token']), 'instance_id' => $credentials['instance_id'] ?? '', 'verified_at' => $credentials['verified_at'] ?? null],
            'products' => Product::query()->where('store_id', $store->id)->whereIn('digital_type', ['codes', 'link'])->orderBy('name')->get(['id', 'name', 'digital_type'])->map(fn ($product) => ['id' => $product->id, 'name' => $product->name, 'type' => $product->digital_type])->all()]);
    }

    private function fields(string $prefix): array
    {
        return [
            $prefix.'email_enabled' => ['required', 'boolean'],
            $prefix.'sender_name' => ['nullable', 'string', 'max:100', 'regex:/^[^\x00-\x1f\x7f]*$/u'],
            $prefix.'email_subject' => ['required', 'string', 'max:200', 'regex:/^[^\x00-\x1f\x7f]+$/u'],
            $prefix.'email_body' => ['required', 'string', 'max:6000'],
            $prefix.'whatsapp_enabled' => ['required', 'boolean'],
            $prefix.'whatsapp_body' => ['required', 'string', 'max:3000'],
        ];
    }

    public function update(Request $request, DigitalDeliverySettings $settings): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['settings' => ['required', 'array:enabled,email_enabled,sender_name,email_subject,email_body,whatsapp_enabled,whatsapp_body,overrides'],
            'settings.enabled' => ['required', 'boolean'], 'settings.overrides' => ['present', 'array', 'max:200'],
            'settings.overrides.*' => ['required', 'array:product_id,email_enabled,sender_name,email_subject,email_body,whatsapp_enabled,whatsapp_body'],
            'settings.overrides.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where('store_id', $store->id)->whereIn('digital_type', ['codes', 'link'])],
        ] + $this->fields('settings.') + $this->fields('settings.overrides.*.'));
        $config = $data['settings'];
        foreach ([$config, ...$config['overrides']] as $index => $entry) {
            foreach (['email_subject', 'email_body', 'whatsapp_body'] as $key) {
                preg_match_all('/\{([^{}]+)\}/u', $entry[$key], $tokens);
                if (array_diff($tokens[1], DigitalDeliverySettings::VARIABLES)) {
                    throw ValidationException::withMessages(['settings' => 'Use only the four supported template variables.']);
                }
                if ($key !== 'email_subject' && ! str_contains($entry[$key], '{code_or_link}')) {
                    throw ValidationException::withMessages(['settings' => 'Delivery bodies must include {code_or_link}.']);
                }
            }
        }
        DB::transaction(function () use ($store, $config) {
            $current = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            if (collect([$config, ...$config['overrides']])->contains(fn ($entry) => $entry['whatsapp_enabled']) && empty($current->digital_delivery_credentials['verified_at'])) {
                throw ValidationException::withMessages(['settings.whatsapp_enabled' => 'Save and verify this store\'s WhatsApp connection before enabling delivery.']);
            }
            $current->update(['digital_delivery_configuration' => $config]);
        });
        $request->attributes->set('store', $store->fresh());

        return $this->index($request, $settings);
    }

    public function connection(Request $request, DigitalDeliverySettings $settings): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['instance_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'], 'access_token' => ['nullable', 'string', 'min:10', 'max:4096', 'regex:/^[^\s\x00-\x1f\x7f]+$/']]);
        DB::transaction(function () use ($store, $data, $settings) {
            $current = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $credentials = $current->digital_delivery_credentials ?? [];
            $token = $data['access_token'] ?? $credentials['access_token'] ?? '';
            if (! $token) {
                throw ValidationException::withMessages(['access_token' => 'Enter an access token.']);
            }
            if (($credentials['instance_id'] ?? null) !== $data['instance_id'] || ($credentials['access_token'] ?? null) !== $token) {
                $credentials = ['instance_id' => $data['instance_id'], 'access_token' => $token, 'verified_at' => null];
                $config = $settings->configured($current);
                $config['whatsapp_enabled'] = false;
                $config['overrides'] = array_map(fn ($entry) => array_replace($entry, ['whatsapp_enabled' => false]), $config['overrides']);
                $current->digital_delivery_configuration = $config;
            }
            $current->digital_delivery_credentials = $credentials;
            $current->save();
        });
        $request->attributes->set('store', $store->fresh());

        return $this->index($request, $settings);
    }

    public function verify(Request $request, StoreDigitalWhatsappClient $client, DigitalDeliverySettings $settings): JsonResponse
    {
        $store = $this->store($request);
        $credentials = $store->digital_delivery_credentials ?? [];
        if (empty($credentials['access_token']) || empty($credentials['instance_id']) || ! $client->verify($credentials)) {
            throw ValidationException::withMessages(['connection' => 'WhatsApp is not connected. Check the token, instance and Wawp subscription.']);
        }
        DB::transaction(function () use ($store, $credentials) {
            $current = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $latest = $current->digital_delivery_credentials ?? [];
            if (($latest['instance_id'] ?? null) !== $credentials['instance_id'] || ($latest['access_token'] ?? null) !== $credentials['access_token']) {
                throw ValidationException::withMessages(['connection' => 'Connection changed. Verify it again.']);
            }
            $current->update(['digital_delivery_credentials' => array_replace($latest, ['verified_at' => now()->toIso8601String()])]);
        });
        $request->attributes->set('store', $store->fresh());

        return $this->index($request, $settings);
    }
}
