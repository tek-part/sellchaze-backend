<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualStoreOrders
{
    public const MAX_MONEY = '9999999999.99';

    public function authorized(Store $store, User $actor): bool
    {
        return $store->status === 'active' && $actor->is_active && ! $actor->pending_approval && $actor->can('store.orders.manage')
            && ((int) $store->owner_user_id === (int) $actor->id || (int) $actor->parent_user_id === (int) $store->owner_user_id || $actor->hasRole('Admin'));
    }

    /** @param array<string,mixed> $data */
    public function place(Store $store, User $actor, array $data): StoreOrder
    {
        return DB::transaction(function () use ($store, $actor, $data) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($actor->id);
            abort_unless($this->authorized($store, $actor), 403);
            $hash = $this->fingerprint($data);
            $existing = StoreOrder::withTrashed()->forStore($store)->where('manual_actor_id', $actor->id)->where('manual_request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless(! $existing->trashed() && (int) $existing->manual_owner_id === (int) $store->owner_user_id
                    && hash_equals((string) $existing->manual_request_hash, $hash), 409, 'Manual request changed or its order is no longer available.');

                return $existing->load('items');
            }
            $phone = app(OrderLimits::class)->assertPhone($store, $data['customer_phone']);
            $subtotal = '0.00';
            $lines = [];
            $productCounts = [];
            $stockCounts = [];
            $hasPhysical = false;
            $hasDigital = false;
            $requiresWhatsapp = false;
            $sorted = $data['items'];
            usort($sorted, fn ($a, $b) => [$a['product_id'], $a['variant_id'] ?? 0] <=> [$b['product_id'], $b['variant_id'] ?? 0]);
            foreach ($sorted as $line) {
                $selection = app(PurchasableSelection::class)->resolve($store, (int) $line['product_id'], isset($line['variant_id']) ? (int) $line['variant_id'] : null, true);
                $product = $selection['product'];
                if (! isset($line['unit_price']) && isset($line['expected_price']) && bccomp((string) $line['expected_price'], $selection['price'], 2) !== 0) {
                    throw ValidationException::withMessages(['items' => 'The product price changed. Select the product again before placing this order.']);
                }
                $variant = $selection['variant'];
                $stock = $variant ?? $product;
                $quantity = (int) $line['quantity'];
                $productCounts[$product->id] = ($productCounts[$product->id] ?? 0) + $quantity;
                app(OrderLimits::class)->assertQuantity($store, $productCounts[$product->id]);
                $stockKey = $product->id.':'.($variant?->id ?? 'base');
                $stockCounts[$stockKey] = ($stockCounts[$stockKey] ?? 0) + $quantity;
                app(StoreInventory::class)->assertAvailable($stock, $stockCounts[$stockKey]);
                app(DigitalProducts::class)->assertAvailable($product, $productCounts[$product->id]);
                $custom = app(ProductPersonalization::class)->resolve($product, $line['personalization'] ?? [], true);
                $price = bcadd((string) ($line['unit_price'] ?? $selection['price']), '0', 2);
                $lineTotal = bcmul($price, (string) $quantity, 2);
                $subtotal = bcadd($subtotal, $lineTotal, 2);
                $this->assertMoney($subtotal);
                $hasPhysical = $hasPhysical || $product->digital_type === 'physical';
                $hasDigital = $hasDigital || $product->digital_type !== 'physical';
                $delivery = app(DigitalDeliverySettings::class)->effective($store, (int) $product->id);
                $requiresWhatsapp = $requiresWhatsapp || ($product->digital_type !== 'physical' && $delivery['enabled'] && $delivery['whatsapp_enabled']);
                $lines[] = ['product' => $product, 'stock' => $stock, 'snapshot' => [
                    'store_product_id' => $product->id, 'variant_id' => $variant?->id, 'variant_name' => $variant?->name,
                    'variant_options' => $variant?->options, 'sku' => $variant?->sku ?? $product->sku,
                    'personalization' => $custom['snapshot'] ?: null, 'name' => $selection['name'],
                    'unit_price' => $price, 'unit_cost' => $variant?->cost ?? $product->cost, 'quantity' => $quantity, 'line_total' => $lineTotal,
                ]];
            }
            if ($hasDigital && ! filter_var($data['customer_email'] ?? null, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['customer_email' => 'Enter a valid email address for digital delivery.']);
            }
            if ($requiresWhatsapp && StoreDigitalWhatsappClient::phone($data['customer_phone']) === null) {
                throw ValidationException::withMessages(['customer_phone' => 'Enter an international WhatsApp number for digital delivery.']);
            }
            if ($hasPhysical && ! filled($data['address'] ?? null)) {
                throw ValidationException::withMessages(['address' => 'Enter a delivery address for physical products.']);
            }
            $payment = $data['payment_method'] ?? ($hasPhysical ? 'cod' : 'bank_transfer');
            if (! $hasPhysical && $payment === 'cod') {
                throw ValidationException::withMessages(['payment_method' => 'Cash on delivery is not available for an entirely digital order.']);
            }
            $shipping = $hasPhysical ? bcadd((string) $data['shipping_total'], '0', 2) : '0.00';
            if (! $hasPhysical && bccomp((string) $data['shipping_total'], '0', 2) !== 0) {
                throw ValidationException::withMessages(['shipping_total' => 'An entirely digital order has no delivery fee.']);
            }
            app(OrderLimits::class)->assertMinimum($store, $subtotal, '0.00');
            $totals = app(PricingCalculator::class)->forStore($store, $subtotal, '0.00', [], false);
            $normalTotal = bcadd($totals['grand_total'], $shipping, 2);
            $this->assertMoney($normalTotal);
            $grand = isset($data['grand_total']) ? bcadd((string) $data['grand_total'], '0', 2) : $normalTotal;
            $order = StoreOrder::create([
                'store_id' => $store->id, 'order_number' => app(StoreOrderService::class)->generateNumber(), 'status' => 'pending',
                'currency' => $store->currency ?: 'USD', 'customer_name' => $data['customer_name'], 'customer_phone' => $data['customer_phone'],
                'customer_phone_normalized' => $phone, 'customer_email' => $data['customer_email'] ?? null,
                'shipping_address' => $hasPhysical ? ['line1' => $data['address'], 'shipping_details' => ['mode' => 'manual', 'base_rate' => $shipping]] : null,
                'notes' => null, 'payment_method' => $payment, 'payment_status' => 'pending',
                'subtotal' => $subtotal, 'shipping_total' => $shipping, 'discount_total' => '0.00', 'tax_total' => $totals['tax_total'], 'grand_total' => $grand,
                'manual_adjustment_total' => bcsub($grand, $normalTotal, 2), 'placed_at' => now(),
                'manual_tax_included' => (bool) $store->tax_prices_include,
                'manual_actor_id' => $actor->id, 'manual_owner_id' => $store->owner_user_id,
                'manual_request_key' => $data['request_key'], 'manual_request_hash' => $hash,
            ]);
            foreach ($lines as $line) {
                $item = $order->items()->create($line['snapshot']);
                app(StoreInventory::class)->reserve($line['stock'], $item);
                app(DigitalProducts::class)->reserve($line['product'], $item);
            }
            $order->statusChanges()->create(['from_status' => 'pending', 'to_status' => 'pending', 'actor_id' => $actor->id, 'source' => 'merchant_manual', 'notes' => $data['notes'] ?? null]);
            $setting = StorePaymentGateway::where('store_id', $store->id)->where('gateway', $payment)->where('enabled', true)->first()
                ?? (new StorePaymentGateway)->forceFill(['store_id' => $store->id, 'gateway' => $payment, 'enabled' => true, 'test_mode' => false, 'credentials' => [], 'notes' => '']);
            app(StorePaymentService::class)->start($store, $order, $setting);
            app(StoreOrderPlacement::class)->record($order);

            return $order->load('items');
        });
    }

    private function assertMoney(string $amount): void
    {
        if (bccomp($amount, self::MAX_MONEY, 2) > 0 || bccomp($amount, '0', 2) < 0) {
            throw ValidationException::withMessages(['items' => 'The order total exceeds the supported amount.']);
        }
    }

    /** The keyed digest keeps retry comparison private without duplicating customer data. */
    private function fingerprint(array $data): string
    {
        unset($data['request_key']);
        foreach (['shipping_total', 'grand_total'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = bcadd((string) $data[$key], '0', 2);
            }
        }
        foreach ($data['items'] as &$line) {
            $line['product_id'] = (int) $line['product_id'];
            $line['variant_id'] = isset($line['variant_id']) ? (int) $line['variant_id'] : null;
            $line['quantity'] = (int) $line['quantity'];
            if (isset($line['expected_price'])) {
                $line['expected_price'] = bcadd((string) $line['expected_price'], '0', 2);
            }
            if (isset($line['unit_price'])) {
                $line['unit_price'] = bcadd((string) $line['unit_price'], '0', 2);
            }
            ksort($line);
        }
        unset($line);
        ksort($data);

        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
