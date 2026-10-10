<?php

namespace App\Services\Commerce;

use App\Models\Store;

/**
 * Phase 6D: the single source of truth for order totals. Extracted from the
 * inline arithmetic previously in CheckoutService so cart preview and checkout
 * compute the grand total identically. All money is bcmath strings (2 dp).
 *
 * Delivery prices are resolved by StoreShipping using store-owned selections.
 */
class PricingCalculator
{
    /** @return array{subtotal:string, discount_total:string, shipping_total:string, tax_total:string, grand_total:string} */
    public function forStore(Store $store, string $subtotal, string $discount = '0.00', array $selection = []): array
    {
        $discount = bccomp($discount, $subtotal, 2) > 0 ? $subtotal : $discount;
        $taxable = bcsub($subtotal, $discount, 2);
        $shipping = app(StoreShipping::class)->quote($store, $taxable, $selection)['amount'];

        $tax = '0.00';
        $rate = (string) ($store->tax_rate ?: '0');
        if ($store->tax_enabled && bccomp($rate, '0', 3) > 0) {
            $tax = $store->tax_prices_include
                ? bcdiv(bcmul($taxable, $rate, 5), bcadd('100', $rate, 3), 2)
                : bcdiv(bcmul($taxable, $rate, 5), '100', 2);
        }
        $grand = bcadd(bcadd($taxable, $shipping, 2), $store->tax_prices_include ? '0.00' : $tax, 2);

        return ['subtotal' => $subtotal, 'discount_total' => $discount, 'shipping_total' => $shipping, 'tax_total' => $tax, 'grand_total' => $grand];
    }

    /**
     * @return array{subtotal:string, discount_total:string, shipping_total:string, tax_total:string, grand_total:string}
     */
    public function totals(string $subtotal, string $discount = '0.00', string $shipping = '0.00'): array
    {
        // A discount can never exceed the subtotal (grand total floored at zero).
        $discount = bccomp($discount, $subtotal, 2) > 0 ? $subtotal : $discount;

        $grand = bcsub(bcadd($subtotal, $shipping, 2), $discount, 2);
        if (bccomp($grand, '0.00', 2) < 0) {
            $grand = '0.00';
        }

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'shipping_total' => $shipping,
            'tax_total' => '0.00',
            'grand_total' => $grand,
        ];
    }
}
