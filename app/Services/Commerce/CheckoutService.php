<?php

namespace App\Services\Commerce;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreOrder;
use App\Services\Outbox\OutboxRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5 checkout. Converts an active cart into an immutable StoreOrder inside
 * a single transaction. Every line is re-validated against the live product
 * (existence, active state, price) — fail-closed — before the order is written,
 * and all customer/line data is snapshotted so the order never mutates later.
 */
class CheckoutService
{
    public function __construct(
        private readonly StoreOrderService $orders,
        private readonly CouponService $coupons,
        private readonly PricingCalculator $pricing,
        private readonly OutboxRecorder $outbox,
        private readonly PurchasableSelection $selections,
        private readonly StoreInventory $inventory,
    ) {}

    /**
     * @param  array{name:string,email:string|null,phone?:string|null,notes?:string|null}  $contact
     * @param  array<string,mixed>|null  $shippingAddress
     */
    public function place(Store $store, Cart $cart, ?StoreCustomer $customer, array $contact, ?array $shippingAddress, string $paymentMethod, array $shippingSelection = []): StoreOrder
    {
        return DB::transaction(function () use ($store, $cart, $customer, $contact, $shippingAddress, $paymentMethod, $shippingSelection) {
            $cart = Cart::query()->where('store_id', $store->id)->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            if ($cart->status !== 'active') {
                throw ValidationException::withMessages(['cart' => 'This cart is no longer active.']);
            }
            $items = $cart->items()->orderBy('store_product_id')->orderBy('variant_id')->orderBy('id')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
            }
            $subtotal = '0.00';
            $lines = [];
            $stocks = [];
            $counts = [];
            $requiresShipping = false;
            $hasDigital = false;
            $requiresWhatsapp = false;

            foreach ($items as $item) {
                $selection = $this->selections->resolve($store, (int) $item->store_product_id, $item->variant_id === null ? null : (int) $item->variant_id, true);
                $product = $selection['product'];
                $requiresShipping = $requiresShipping || $product->digital_type === 'physical';
                $hasDigital = $hasDigital || $product->digital_type !== 'physical';
                $deliverySettings = app(DigitalDeliverySettings::class)->effective($store, (int) $product->id);
                $requiresWhatsapp = $requiresWhatsapp || ($product->digital_type !== 'physical' && $deliverySettings['enabled'] && $deliverySettings['whatsapp_enabled']);
                $variant = $selection['variant'];
                $custom = app(ProductPersonalization::class)->resolve($product, $item->personalization ?? [], true);
                $stocks[] = $variant ?? $product;
                $stockKey = $product->id.':'.($variant?->id ?? 'base');
                $counts[$stockKey] = ($counts[$stockKey] ?? 0) + $item->quantity;
                $this->inventory->assertAvailable($variant ?? $product, $counts[$stockKey]);
                // Price validation: the snapshot must still match the live price.
                if (bccomp($selection['price'], (string) $item->unit_price, 2) !== 0) {
                    throw ValidationException::withMessages([
                        'items' => "The price of \"{$product->name}\" changed. Please review your cart.",
                    ]);
                }

                $lineTotal = bcmul($selection['price'], (string) $item->quantity, 2);
                $subtotal = bcadd($subtotal, $lineTotal, 2);
                $lines[] = [
                    'store_product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'variant_name' => $variant?->name,
                    'variant_options' => $variant?->options,
                    'sku' => $variant?->sku ?? $product->sku,
                    'personalization' => $custom['snapshot'] ?: null,
                    'name' => $selection['name'],
                    'unit_price' => $selection['price'],
                    'quantity' => $item->quantity,
                    'line_total' => $lineTotal,
                ];
            }

            // Coupon: re-validated fail-closed at order time (it may have expired
            // or hit its limit since it was applied to the cart). lockForUpdate
            // serializes concurrent redemptions of the same coupon so the
            // usage-count check + insert is atomic — max_uses cannot be exceeded
            // under concurrent checkouts.
            $coupon = $cart->coupon_id ? Coupon::query()->lockForUpdate()->find($cart->coupon_id) : null;
            $discount = '0.00';
            if ($coupon !== null) {
                $this->coupons->validate($coupon, $customer, $subtotal);
                $discount = $this->coupons->computeDiscount($coupon, $subtotal);
            }

            if ($hasDigital && (! is_string($contact['email']) || ! filter_var($contact['email'], FILTER_VALIDATE_EMAIL))) {
                throw ValidationException::withMessages(['customer_email' => 'A valid email address is required for digital delivery.']);
            }
            if ($requiresWhatsapp && StoreDigitalWhatsappClient::phone((string) ($contact['phone'] ?? '')) === null) {
                throw ValidationException::withMessages(['customer_phone' => 'Enter a valid international WhatsApp number with its country code.']);
            }
            if (! $requiresShipping && $paymentMethod === 'cod') {
                throw ValidationException::withMessages(['payment_method' => 'Cash on delivery is not available for an entirely digital order.']);
            }
            // Recheck delivery fields after locking the authoritative cart. A
            // concurrent cart edit must not turn a digital form into an addressless parcel.
            if ($requiresShipping) {
                $addressRules = array_filter(app(CheckoutFields::class)->rules($store, $paymentMethod, true, $hasDigital),
                    fn (string $key) => str_starts_with($key, 'shipping_address'), ARRAY_FILTER_USE_KEY);
                Validator::make(['shipping_address' => $shippingAddress], $addressRules)->validate();
            }
            $totals = $this->pricing->forStore($store, $subtotal, $discount, $shippingSelection, $requiresShipping);
            if ($requiresShipping) {
                $delivery = app(StoreShipping::class)->quote($store, bcsub($subtotal, $totals['discount_total'], 2), $shippingSelection);
                if ($shippingAddress !== null || $delivery['address'] !== []) {
                    $shippingAddress = array_merge($shippingAddress ?? [], $delivery['address'], ['shipping_details' => $delivery['details']]);
                }
            } else {
                $shippingAddress = null;
            }

            $order = StoreOrder::create([
                'store_customer_id' => $customer?->id,
                'order_number' => $this->orders->generateNumber(),
                'status' => 'pending',
                'currency' => $cart->currency ?: ($store->currency ?: 'USD'),
                'customer_name' => $contact['name'],
                'customer_email' => $contact['email'],
                'customer_phone' => $contact['phone'] ?? null,
                'shipping_address' => $shippingAddress,
                'payment_method' => $paymentMethod,
                'payment_status' => 'pending',
                'subtotal' => $totals['subtotal'],
                'shipping_total' => $totals['shipping_total'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'notes' => $contact['notes'] ?? null,
                'placed_at' => now(),
            ]);

            foreach ($lines as $index => $line) {
                $item = $order->items()->create($line);
                $this->inventory->reserve($stocks[$index], $item);
                $digitalProduct = $stocks[$index] instanceof Product ? $stocks[$index] : Product::query()->where('store_id', $store->id)->findOrFail($item->store_product_id);
                app(DigitalProducts::class)->reserve($digitalProduct, $item);
            }

            if ($coupon !== null) {
                $this->coupons->recordUsage($coupon, $customer, $order, $totals['discount_total']);
            }

            $cart->update(['status' => 'converted', 'coupon_id' => null]);

            StoreAnalyticsService::forget($store->id);

            $this->outbox->record('StorefrontOrderPlaced', 'store_order', $order->id, [
                'store_id' => $store->id,
                'store_order_id' => $order->id,
                'order_number' => $order->order_number,
                'grand_total' => $totals['grand_total'],
                'currency' => $order->currency,
                'payment_method' => $paymentMethod,
            ]);

            app(DigitalDelivery::class)->recordReceipt($order);

            // Mirror into the B2B pipeline after commit, including bank transfers.
            // A bridge failure can never roll the customer order back.
            BridgeStorefrontOrderJob::dispatch($order->id, $store->id)->afterCommit();

            return $order->load('items');
        });
    }
}
