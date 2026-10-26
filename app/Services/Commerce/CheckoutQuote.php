<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreCustomer;
use App\Services\Commerce\Apps\CountryRestriction;
use Illuminate\Validation\ValidationException;

/** Live prices and totals without creating or changing the shopper's cart. */
class CheckoutQuote
{
    public function __construct(
        private readonly PurchasableSelection $selections,
        private readonly CouponService $coupons,
        private readonly PricingCalculator $pricing,
        private readonly DiscountEngine $discounts,
    ) {}

    public function calculate(Store $store, array $items, ?string $code, ?StoreCustomer $customer, array $shippingSelection = []): array
    {
        $subtotal = '0.00';
        $lines = [];
        $counts = [];
        $digitalCounts = [];
        $requiresShipping = false;
        $offerFreeShipping = false;
        $allPhysicalFree = true;
        $weightGrams = 0;
        foreach ($items as $item) {
            $variantId = isset($item['variant_id']) ? (int) $item['variant_id'] : null;
            $selection = $this->selections->resolve($store, (int) $item['product_id'], $variantId, false,
                $item['quantity_offer_id'] ?? null, (int) $item['quantity'], isset($item['quantity_offer_version']) ? (int) $item['quantity_offer_version'] : null);
            $requiresShipping = $requiresShipping || $selection['product']->digital_type === 'physical';
            if ($selection['product']->digital_type === 'physical') {
                $allPhysicalFree = $allPhysicalFree && ProductPreferences::flag($selection['product'], 'is_free_shipping');
            }
            $offerFreeShipping = $offerFreeShipping || ($selection['product']->digital_type === 'physical' && ($selection['offer']['free_shipping'] ?? false));
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
            $weightGrams += StoreShipping::lineWeightGrams($selection['product'], $selection['variant'] ?? null, $quantity);
            $lineTotal = bcmul($selection['price'], (string) $quantity, 2);
            $subtotal = bcadd($subtotal, $lineTotal, 2);
            $lines[] = ['product_id' => $selection['product']->id, 'variant_id' => $variantId,
                'quantity_offer' => $selection['offer'],
                'personalization' => ProductPersonalization::present($custom['snapshot'], $store->id), 'personalization_key' => $custom['key'],
                'name' => $selection['name'], 'unit_price' => $selection['price'], 'quantity' => $quantity, 'line_total' => $lineTotal];
        }
        $restriction = app(CountryRestriction::class);
        $restriction->assertVisitor($store, $shippingSelection['client_ip'] ?? null);
        // A quote is computed before the shopper finishes the form: only a chosen country can be refused.
        $restriction->assertDelivery($store, $restriction->deliveryCountry($store, $shippingSelection, $shippingSelection['shipping_country'] ?? null), $requiresShipping, false);
        $coupon = null;
        if ($code !== null && trim($code) !== '') {
            $coupon = $this->coupons->resolveActive($code);
            if ($coupon === null || (int) $coupon->store_id !== (int) $store->id) {
                throw ValidationException::withMessages(['coupon_code' => 'This coupon is not available.']);
            }
        }
        $discounts = $this->discounts->evaluate($store, array_map(fn (array $line) => ['product_id' => (int) $line['product_id'], 'quantity' => (int) $line['quantity'], 'unit_price' => (string) $line['unit_price']], $lines), $coupon, $customer);
        $discount = $discounts['discount'];
        $offerFreeShipping = $offerFreeShipping || $discounts['free_shipping'];

        $productFreeShipping = $requiresShipping && $allPhysicalFree;
        $breakdown = $this->pricing->breakdown($store, $subtotal, $discount, $shippingSelection, $requiresShipping, $offerFreeShipping, $productFreeShipping, $weightGrams);
        $totals = $breakdown['totals'];
        $adjustments = app(PaymentPriceAdjustment::class);
        $totals = $adjustments->forMethod($store, $shippingSelection['payment_method'] ?? '', $totals);

        return ['items' => $lines, 'currency' => $store->currency ?: 'USD', 'discounts' => $discounts['applied'], 'requires_shipping' => $requiresShipping, 'offer_free_shipping' => $offerFreeShipping, 'product_free_shipping' => $productFreeShipping,
            'payment_adjustment_token' => $adjustments->token($store, $totals['payment_adjustment']),
            'order_minimum' => app(OrderLimits::class)->minimum($store, $subtotal, $discount),
            'shipping' => $this->shipping($breakdown['shipping']), 'tax' => $this->tax($breakdown['tax']),
            'totals' => $totals];
    }

    /** What the shopper can choose for this cart and address (zones), and what was applied. */
    private function shipping(?array $delivery): ?array
    {
        if ($delivery === null) {
            return null;
        }
        $details = $delivery['details'];

        return ['mode' => $details['mode'], 'zone' => $details['zone'] ?? null, 'rate_id' => $details['rate']['id'] ?? null,
            'rates' => $delivery['rates'] ?? [], 'free_shipping_applied' => $details['free_shipping_applied'], 'cod_fee' => $details['cod_fee'] ?? '0.00',
            'weight_grams' => $details['weight_grams'] ?? null];
    }

    private function tax(?array $rule): ?array
    {
        return $rule === null ? null : ['name' => $rule['name'], 'rate' => $rule['rate'], 'included' => $rule['included'], 'shipping' => $rule['shipping'], 'source' => $rule['source'], 'amount' => $rule['amount']];
    }
}
