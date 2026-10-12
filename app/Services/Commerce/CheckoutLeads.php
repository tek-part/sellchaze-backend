<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreCheckoutLead;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutLeads
{
    public const INACTIVE_MINUTES = 5;

    public function capture(Store $store, array $data): void
    {
        DB::transaction(function () use ($store, $data): void {
            $store = Store::query()->lockForUpdate()->findOrFail($store->id);
            $key = $this->key($data['lead_token']);
            $lead = StoreCheckoutLead::withoutGlobalScopes()->where('store_id', $store->id)->where('key_hash', $key)->lockForUpdate()->first();
            if ($lead && ($lead->converted_at !== null || $lead->capture_sequence >= $data['sequence'])) {
                return;
            }
            $clear = $data['clear'] ?? false;
            if ($clear && ! $lead) {
                // Keep a sequence fence so an older in-flight capture cannot restore cleared details.
                $lead = new StoreCheckoutLead(['store_id' => $store->id, 'key_hash' => $key, 'currency' => $store->currency ?: 'USD']);
            }
            if ($clear) {
                $lead->fill(['customer_name' => null, 'customer_email' => null, 'customer_phone' => null, 'phone_normalized' => null,
                    'shipping_address' => null, 'notes' => null, 'shipping_selection' => null, 'coupon_code' => null, 'items' => null, 'subtotal' => '0.00', 'capture_sequence' => $data['sequence'],
                    'capture_hash' => hash('sha256', 'clear'), 'last_activity_at' => now(), 'edit_version' => ($lead->edit_version ?? 0) + 1])->save();

                return;
            }
            $visible = $this->visibleContact($store, $data);
            $contact = $visible['contact'];
            $limits = app(OrderLimits::class);
            $phone = $limits->normalizePhone($contact['customer_phone'] ?? null, $limits->configured($store)['phone_country']);
            $email = isset($contact['customer_email']) ? mb_strtolower($contact['customer_email']) : null;
            if ($phone === null && ! filled($email)) {
                throw ValidationException::withMessages(['customer_phone' => 'Enter a valid phone number or email address.']);
            }
            $items = [];
            $subtotal = '0.00';
            foreach ($data['items'] as $item) {
                $selection = app(PurchasableSelection::class)->resolve($store, (int) $item['product_id'], isset($item['variant_id']) ? (int) $item['variant_id'] : null);
                $total = bcmul($selection['price'], (string) $item['quantity'], 2);
                $subtotal = bcadd($subtotal, $total, 2);
                $items[] = ['product_id' => $selection['product']->id, 'variant_id' => $selection['variant']?->id,
                    'name' => $selection['name'], 'sku' => $selection['variant']?->sku ?? $selection['product']->sku,
                    'quantity' => (int) $item['quantity'], 'unit_price' => $selection['price'], 'line_total' => $total];
            }
            $lead ??= new StoreCheckoutLead(['store_id' => $store->id, 'key_hash' => $key]);
            $lead->fill(['customer_name' => $contact['customer_name'] ?? null, 'customer_email' => $email,
                'customer_phone' => $phone !== null ? ($contact['customer_phone'] ?? null) : null, 'phone_normalized' => $phone,
                'shipping_address' => $contact['shipping_address'] ?? null, 'notes' => $contact['notes'] ?? null,
                'shipping_selection' => $visible['requires_shipping'] ? $this->shippingSelection($store, $data) : [], 'coupon_code' => $data['coupon_code'] ?? null,
                'items' => $items, 'subtotal' => $subtotal, 'currency' => $store->currency ?: 'USD',
                'source' => $data['source'], 'source_path' => $data['source_path'] ?? null,
                'capture_sequence' => $data['sequence'], 'capture_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
                'last_activity_at' => now(), 'edit_version' => ($lead->edit_version ?? 0) + 1])->save();
        });
    }

    /** Caller holds the store lock in the order transaction. This also fences delayed first captures. */
    public function converted(Store $store, ?string $token, StoreOrder $order): void
    {
        if ($token === null) {
            return;
        }
        $lead = StoreCheckoutLead::withoutGlobalScopes()->firstOrCreate(
            ['store_id' => $store->id, 'key_hash' => $this->key($token)],
            ['currency' => $store->currency ?: 'USD'],
        );
        if ($lead->converted_at === null) {
            $lead->update(['store_order_id' => $order->id, 'converted_at' => now(), 'edit_version' => $lead->edit_version + 1]);
        }
    }

    private function key(string $token): string
    {
        return hash('sha256', 'checkout-lead:'.mb_strtolower($token));
    }

    private function shippingSelection(Store $store, array $data): array
    {
        $configuration = app(StoreShipping::class)->publicConfiguration($store);
        $selection = [];
        foreach (['regions' => 'region', 'options' => 'option'] as $collection => $type) {
            $id = $data['shipping_'.$type.'_id'] ?? null;
            foreach ($configuration[$collection] as $item) {
                if ($id !== null && (string) $item['id'] === $id) {
                    $selection[$type.'_id'] = $id;
                    $selection[$type.'_name'] = $item['name'][app()->getLocale()] ?? $item['name']['en'] ?? '';
                }
            }
        }

        return $selection;
    }

    private function visibleContact(Store $store, array $data): array
    {
        $basket = app(CheckoutBasket::class)->fromIds($store, array_column($data['items'], 'product_id'), true);
        $requested = $data['payment_method'] ?? null;
        $method = $requested && StorePaymentGateway::query()->where('store_id', $store->id)->where('enabled', true)->where('gateway', $requested)->exists() ? $requested : null;
        $fields = app(CheckoutFields::class)->effective($store, $method, $basket['requires_shipping'], $basket['has_digital'], $basket['requires_whatsapp']);
        $contact = [];
        foreach ($fields as $field) {
            $path = CheckoutFields::PATHS[$field['key']];
            if ($field['enabled'] && ! ($field['shipping_region'] ?? false) && data_has($data, $path)) {
                data_set($contact, $path, data_get($data, $path));
            }
        }
        if (isset($contact['shipping_address'], $contact['customer_name'])) {
            $contact['shipping_address']['name'] = $contact['customer_name'];
        }

        return ['contact' => $contact, 'requires_shipping' => $basket['requires_shipping']];
    }
}
