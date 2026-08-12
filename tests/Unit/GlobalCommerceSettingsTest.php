<?php

namespace Tests\Unit;

use App\Models\Store;
use App\Services\Commerce\PricingCalculator;
use PHPUnit\Framework\TestCase;

class GlobalCommerceSettingsTest extends TestCase
{
    public function test_exclusive_tax_and_flat_shipping_are_applied_after_discount(): void
    {
        $store = new Store([
            'tax_enabled' => true, 'tax_rate' => '14', 'tax_prices_include' => false,
            'shipping_enabled' => true, 'shipping_flat_rate' => '15', 'shipping_free_over' => '200',
        ]);

        $this->assertSame([
            'subtotal' => '100.00', 'discount_total' => '10.00', 'shipping_total' => '15.00',
            'tax_total' => '12.60', 'grand_total' => '117.60',
        ], (new PricingCalculator)->forStore($store, '100.00', '10.00'));
    }

    public function test_inclusive_tax_is_reported_without_adding_it_twice_and_free_shipping_threshold_works(): void
    {
        $store = new Store([
            'tax_enabled' => true, 'tax_rate' => '14', 'tax_prices_include' => true,
            'shipping_enabled' => true, 'shipping_flat_rate' => '15', 'shipping_free_over' => '200',
        ]);

        $totals = (new PricingCalculator)->forStore($store, '250.00');
        $this->assertSame('0.00', $totals['shipping_total']);
        $this->assertSame('30.70', $totals['tax_total']);
        $this->assertSame('250.00', $totals['grand_total']);
    }
}
