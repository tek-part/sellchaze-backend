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
    ) {}

    /**
     * @param  array{name:string,email:string,phone?:string|null,notes?:string|null}  $contact
     * @param  array<string,mixed>|null  $shippingAddress
     */
    public function place(Store $store, Cart $cart, ?StoreCustomer $customer, array $contact, ?array $shippingAddress, string $paymentMethod): StoreOrder
    {
        $cart->load('items');

        if ($cart->items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
        }

        return DB::transaction(function () use ($store, $cart, $customer, $contact, $shippingAddress, $paymentMethod) {
            $subtotal = '0.00';
            $lines = [];

            foreach ($cart->items as $item) {
                $product = $item->store_product_id
                    ? Product::query()->find($item->store_product_id)
                    : null;

                // Inventory + availability validation (fail-closed).
                if ($product === null || ! $product->is_active) {
                    throw ValidationException::withMessages([
                        'items' => "\"{$item->name}\" is no longer available.",
                    ]);
                }
                // Price validation: the snapshot must still match the live price.
                if (bccomp((string) $product->price, (string) $item->unit_price, 2) !== 0) {
                    throw ValidationException::withMessages([
                        'items' => "The price of \"{$product->name}\" changed. Please review your cart.",
                    ]);
                }

                $lineTotal = bcmul((string) $product->price, (string) $item->quantity, 2);
                $subtotal = bcadd($subtotal, $lineTotal, 2);
                $lines[] = [
                    'store_product_id' => $product->id,
                    'name' => $product->name,
                    'unit_price' => $product->price,
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

            $totals = $this->pricing->forStore($store, $subtotal, $discount);

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

            foreach ($lines as $line) {
                $order->items()->create($line);
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

            // Mirror into the B2B orders pipeline once the customer order is durable. afterCommit
            // covers COD/bank transfer too, and a bridge failure can never roll this order back.
            BridgeStorefrontOrderJob::dispatch($order->id, $store->id)->afterCommit();

            return $order->load('items');
        });
    }
}
