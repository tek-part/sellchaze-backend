<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreCustomer;
use Illuminate\Validation\ValidationException;

/** Live prices and totals without creating or changing the shopper's cart. */
class CheckoutQuote
{
    public function __construct(
        private readonly PurchasableSelection $selections,
        private readonly CouponService $coupons,
        private readonly PricingCalculator $pricing,
    ) {}

    public function calculate(Store $store, array $items, ?string $code, ?StoreCustomer $customer, array $shippingSelection = []): array
    {
        $subtotal = '0.00';
        $lines = [];
        $counts = [];
        $digitalCounts = [];
        $requiresShipping = false;
        foreach ($items as $item) {
            $variantId = isset($item['variant_id']) ? (int) $item['variant_id'] : null;
            $selection = $this->selections->resolve($store, (int) $item['product_id'], $variantId);
            $requiresShipping = $requiresShipping || $selection['product']->digital_type === 'physical';
            $custom = app(ProductPersonalization::class)->resolve($selection['product'], $item['personalization'] ?? []);
            $quantity = (int) $item['quantity'];
            $key = $item['product_id'].':'.($variantId ?? 'base');
            $counts[$key] = ($counts[$key] ?? 0) + $quantity;
            if ($quantity < 1 || $counts[$key] > 999) {
                throw ValidationException::withMessages(['quantity' => 'Choose between 1 and 999 items.']);
            }
            app(StoreInventory::class)->assertAvailable($selection['variant'] ?? $selection['product'], $counts[$key]);
            $digitalId = $selection['product']->id;
            $digitalCounts[$digitalId] = ($digitalCounts[$digitalId] ?? 0) + $quantity;
            app(OrderLimits::class)->assertQuantity($store, $digitalCounts[$digitalId]);
            app(DigitalProducts::class)->assertAvailable($selection['product'], $digitalCounts[$digitalId]);
            $lineTotal = bcmul($selection['price'], (string) $quantity, 2);
            $subtotal = bcadd($subtotal, $lineTotal, 2);
            $lines[] = ['product_id' => $selection['product']->id, 'variant_id' => $variantId,
                'personalization' => ProductPersonalization::present($custom['snapshot'], $store->id), 'personalization_key' => $custom['key'],
                'name' => $selection['name'], 'unit_price' => $selection['price'], 'quantity' => $quantity, 'line_total' => $lineTotal];
        }
        $discount = '0.00';
        if ($code !== null && trim($code) !== '') {
            $coupon = $this->coupons->resolveActive($code);
            if ($coupon === null || (int) $coupon->store_id !== (int) $store->id) {
                throw ValidationException::withMessages(['coupon_code' => 'This coupon is not available.']);
            }
            $this->coupons->validate($coupon, $customer, $subtotal);
            $discount = $this->coupons->computeDiscount($coupon, $subtotal);
        }

        return ['items' => $lines, 'currency' => $store->currency ?: 'USD', 'requires_shipping' => $requiresShipping,
            'totals' => $this->pricing->forStore($store, $subtotal, $discount, $shippingSelection, $requiresShipping)];
    }
}
