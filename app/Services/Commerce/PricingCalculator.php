<?php

namespace App\Services\Commerce;

/**
 * Phase 6D: the single source of truth for order totals. Extracted from the
 * inline arithmetic previously in CheckoutService so cart preview and checkout
 * compute the grand total identically. All money is bcmath strings (2 dp).
 *
 * There is no tax or non-zero shipping engine in the platform yet, so both
 * default to '0.00'; the signature leaves room to plug them in later without
 * touching callers.
 */
class PricingCalculator
{
    /**
     * @return array{subtotal:string, discount_total:string, shipping_total:string, grand_total:string}
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
            'grand_total' => $grand,
        ];
    }
}
