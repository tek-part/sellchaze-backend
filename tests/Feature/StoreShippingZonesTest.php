<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\Commerce\CheckoutFields;
use App\Services\Commerce\PricingCalculator;
use App\Services\Commerce\StoreShipping;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shipping and taxes v2: zones, price/weight bands, COD fees and regional tax overrides.
 * The legacy regression cases were captured against main before zones existed
 * (tests/Fixtures/shipping_legacy_quotes.json) and must keep producing identical quotes.
 */
class StoreShippingZonesTest extends TestCase
{
    use RefreshDatabase;

    private const R_CAIRO = '11111111-1111-4111-8111-111111111111';

    private const R_JEDDAH = '22222222-2222-4222-8222-222222222222';

    private const O_EXPRESS = '33333333-3333-4333-8333-333333333333';

    private User $owner;

    private Store $store;

    private Product $product;

    private string $base = 'http://zones.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Zones', 'slug' => 'zones', 'currency' => 'EGP', 'status' => 'active', 'default_locale' => 'en', 'supported_locales' => ['ar', 'en'],
            'checkout_fields' => app(CheckoutFields::class)->defaults(), 'shipping_enabled' => true, 'shipping_flat_rate' => '25.00']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'zones.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Kettle', 'slug' => 'kettle', 'price' => 100, 'weight' => '1.250', 'is_active' => true]);
    }

    /** @return array<string, array{0: array<string,mixed>, 1: array<string,string>}> */
    private function legacyScenarios(): array
    {
        $regions = [['id' => self::R_CAIRO, 'name' => ['ar' => 'القاهرة', 'en' => 'Cairo'], 'country' => 'EG', 'rate' => '40.00', 'enabled' => true, 'position' => 0],
            ['id' => self::R_JEDDAH, 'name' => ['ar' => 'جدة', 'en' => 'Jeddah'], 'country' => 'SA', 'rate' => '60.00', 'enabled' => true, 'position' => 1]];
        $options = [['id' => self::O_EXPRESS, 'name' => ['ar' => 'سريع', 'en' => 'Express'], 'description' => ['ar' => '', 'en' => 'Fast'], 'rate' => '85.00', 'enabled' => true, 'is_default' => true, 'priority' => 1, 'icon_asset_id' => null]];

        return [
            'disabled' => [['shipping_enabled' => false, 'shipping_flat_rate' => '25'], []],
            'flat' => [['shipping_enabled' => true, 'shipping_flat_rate' => '25'], []],
            'flat_free_over' => [['shipping_enabled' => true, 'shipping_flat_rate' => '25', 'shipping_free_over' => '150'], []],
            'regions' => [['shipping_enabled' => true, 'shipping_flat_rate' => '25', 'shipping_configuration' => ['regions_enabled' => true, 'auto_select_region' => true, 'regions' => $regions, 'options' => []]], ['shipping_region_id' => self::R_JEDDAH]],
            'regions_options' => [['shipping_enabled' => true, 'shipping_flat_rate' => '25', 'shipping_configuration' => ['regions_enabled' => true, 'auto_select_region' => true, 'regions' => $regions, 'options' => $options]], ['shipping_region_id' => self::R_CAIRO, 'shipping_option_id' => self::O_EXPRESS]],
            'options_only' => [['shipping_enabled' => true, 'shipping_flat_rate' => '25', 'shipping_configuration' => ['regions_enabled' => false, 'auto_select_region' => true, 'regions' => [], 'options' => $options]], ['shipping_option_id' => self::O_EXPRESS]],
        ];
    }

    public function test_legacy_settings_produce_the_quotes_captured_on_main(): void
    {
        $golden = json_decode((string) file_get_contents(base_path('tests/Fixtures/shipping_legacy_quotes.json')), true, 512, JSON_THROW_ON_ERROR);
        $taxes = ['none' => ['tax_enabled' => false, 'tax_rate' => 0, 'tax_prices_include' => false], 'excl' => ['tax_enabled' => true, 'tax_rate' => '14', 'tax_prices_include' => false], 'incl' => ['tax_enabled' => true, 'tax_rate' => '15', 'tax_prices_include' => true]];
        $checked = 0;
        $i = 0;
        foreach ($this->legacyScenarios() as $name => [$attributes, $selection]) {
            foreach ($taxes as $taxName => $tax) {
                $store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'S', 'slug' => 'legacy'.$i++, 'currency' => 'EGP', 'status' => 'active'] + $attributes + $tax);
                foreach ([[false, false], [true, false], [false, true]] as [$offer, $product]) {
                    $key = "$name|$taxName|".($offer ? 'offer' : '').($product ? 'product' : '');
                    $this->assertSame($golden[$key]['quote'], app(StoreShipping::class)->quote($store, '120.00', $selection, $offer, $product), $key);
                    $this->assertSame($golden[$key]['totals'], app(PricingCalculator::class)->forStore($store, '200.00', '30.00', $selection, true, $offer, $product), $key);
                    $checked++;
                }
            }
        }
        $this->assertSame(54, $checked);
        $this->assertCount(54, $golden);
    }

    public function test_legacy_checkout_quote_and_order_are_unchanged_over_http(): void
    {
        [$attributes] = $this->legacyScenarios()['regions_options'];
        $this->store->update($attributes + ['tax_enabled' => true, 'tax_rate' => '10', 'tax_prices_include' => false]);
        $items = [['product_id' => $this->product->id, 'quantity' => 2]];
        $selection = ['shipping_region_id' => self::R_CAIRO, 'shipping_option_id' => self::O_EXPRESS];
        $this->postJson($this->base.'/checkout/quote', ['items' => $items] + $selection)->assertOk()
            ->assertJsonPath('data.totals.shipping_total', '85.00')->assertJsonPath('data.totals.tax_total', '20.00')->assertJsonPath('data.totals.grand_total', '305.00');
        $this->postJson($this->base.'/checkout/quote', ['items' => $items, 'shipping_option_id' => self::O_EXPRESS])->assertUnprocessable()->assertJsonValidationErrors('shipping_region_id');
        $this->getJson($this->base.'/checkout/fields')->assertOk()->assertJsonPath('shipping.regions_enabled', true)->assertJsonCount(2, 'shipping.regions')->assertJsonCount(1, 'shipping.options');
        $this->postJson($this->base.'/checkout', ['customer_name' => 'Buyer', 'customer_phone' => '01000000000', 'shipping_address' => ['line1' => 'Street 1', 'city' => 'Typed'], 'items' => $items] + $selection)
            ->assertCreated()->assertJsonPath('data.shipping_total', '85.00')->assertJsonPath('data.grand_total', '305.00')
            ->assertJsonPath('data.shipping_address.city', 'Cairo')->assertJsonPath('data.shipping_address.country', 'EG')->assertJsonPath('data.shipping_address.delivery_option', 'Express');
    }

    private function rate(array $overrides = []): array
    {
        return array_merge(['name' => ['ar' => 'عادي', 'en' => 'Standard'], 'type' => 'flat', 'price' => '50.00', 'free_over' => null,
            'delivery_time' => ['ar' => '٢-٣ أيام', 'en' => '2-3 days'], 'cod_fee' => null, 'enabled' => true], $overrides);
    }

    private function zone(array $overrides = [], int $status = 201): array
    {
        return $this->owner()->postJson('/api/v1/my-store/shipping/zones', array_merge(['name' => ['ar' => 'مصر', 'en' => 'Egypt'], 'countries' => ['EG'], 'regions' => [],
            'enabled' => true, 'rates' => [$this->rate()]], $overrides))->assertStatus($status)->json();
    }

    private function region(string $id, string $en, string $ar, string $country = 'EG'): array
    {
        return ['id' => $id, 'name' => ['ar' => $ar, 'en' => $en], 'country' => $country];
    }

    private function quote(array $data, int $quantity = 1): TestResponse
    {
        return $this->postJson($this->base.'/checkout/quote', ['items' => [['product_id' => $this->product->id, 'quantity' => $quantity]]] + $data);
    }

    /** @return array{0:string,1:string,2:string} zone ids: Greater Cairo (regions), Egypt (country-wide), Gulf (weight bands) */
    private function egyptAndGulf(): array
    {
        $cairo = $this->zone(['name' => ['ar' => 'القاهرة الكبرى', 'en' => 'Greater Cairo'], 'regions' => [$this->region(self::R_CAIRO, 'Cairo', 'القاهرة'), $this->region(self::R_JEDDAH, 'Giza', 'الجيزة')],
            'rates' => [$this->rate(['price' => '30.00', 'name' => ['ar' => 'داخل القاهرة', 'en' => 'Inside Cairo']])]])['data'];
        $egypt = $this->zone()['data'];
        $gulf = $this->zone(['name' => ['ar' => 'الخليج', 'en' => 'Gulf'], 'countries' => ['SA', 'AE'], 'rates' => [
            $this->rate(['name' => ['ar' => 'خفيف', 'en' => 'Light'], 'type' => 'weight', 'min_weight_grams' => 0, 'max_weight_grams' => 2000, 'price' => '120.00']),
            $this->rate(['name' => ['ar' => 'ثقيل', 'en' => 'Heavy'], 'type' => 'weight', 'min_weight_grams' => 2000, 'max_weight_grams' => null, 'price' => '200.00']),
        ]])['data'];

        return [$cairo, $egypt, $gulf];
    }

    public function test_zone_is_matched_by_region_then_country_and_unknown_destinations_are_rejected(): void
    {
        [$cairo, $egypt, $gulf] = $this->egyptAndGulf();
        $this->quote(['shipping_country' => 'EG', 'shipping_region_id' => self::R_CAIRO])->assertOk()
            ->assertJsonPath('data.shipping.zone.id', $cairo['id'])->assertJsonPath('data.totals.shipping_total', '30.00')->assertJsonPath('data.shipping.rates.0.delivery_time.en', '2-3 days');
        $this->quote(['shipping_country' => 'eg'])->assertOk()->assertJsonPath('data.shipping.zone.id', $egypt['id'])->assertJsonPath('data.totals.shipping_total', '50.00');
        $this->quote(['shipping_country' => 'AE'])->assertOk()->assertJsonPath('data.shipping.zone.id', $gulf['id'])->assertJsonPath('data.totals.shipping_total', '120.00');
        $this->quote(['shipping_country' => 'US'])->assertUnprocessable()->assertJsonValidationErrors('shipping_country');
        $this->quote([])->assertUnprocessable()->assertJsonValidationErrors('shipping_country');
        $this->quote(['shipping_country' => 'SA', 'shipping_region_id' => self::R_CAIRO])->assertUnprocessable()->assertJsonValidationErrors('shipping_region_id');

        $fields = $this->getJson($this->base.'/checkout/fields?shipping_country=EG&shipping_region_id='.self::R_CAIRO)->assertOk()
            ->assertJsonPath('shipping.zones_enabled', true)->assertJsonPath('shipping.regions_enabled', false)->assertJsonPath('shipping.free_over', null)
            ->assertJsonPath('shipping.zone.id', $cairo['id'])->assertJsonPath('shipping.rates.0.name.en', 'Inside Cairo')->assertJsonCount(2, 'shipping.zone_regions');
        $this->assertSame([['code' => 'AE', 'region_required' => false], ['code' => 'EG', 'region_required' => false], ['code' => 'SA', 'region_required' => false]], $fields->json('shipping.countries'));
        $country = collect($fields->json('data'))->firstWhere('key', 'country');
        $this->assertTrue($country['enabled'] && $country['required']);
        $this->getJson($this->base.'/checkout/fields?shipping_country=SA')->assertOk()->assertJsonPath('shipping.zone.id', $gulf['id'])->assertJsonCount(2, 'shipping.rates');
        $this->getJson($this->base)->assertOk()->assertJsonPath('store.shipping.free_over', null);

        // Removing the country-wide zone leaves Egypt reachable through its regions only.
        $this->owner()->deleteJson('/api/v1/my-store/shipping/zones/'.$egypt['id'])->assertNoContent();
        $this->quote(['shipping_country' => 'EG'])->assertUnprocessable()->assertJsonValidationErrors('shipping_region_id');
        $this->getJson($this->base.'/checkout/fields')->assertOk()->assertJsonPath('shipping.countries.1', ['code' => 'EG', 'region_required' => true]);
    }

    public function test_price_bands_use_the_subtotal_after_discounts_and_reject_ineligible_rates(): void
    {
        $zone = $this->zone(['rates' => [
            $this->rate(['name' => ['ar' => 'أساسي', 'en' => 'Small orders'], 'type' => 'price', 'min_subtotal' => '0', 'max_subtotal' => '250', 'price' => '60.00']),
            $this->rate(['name' => ['ar' => 'كبير', 'en' => 'Large orders'], 'type' => 'price', 'min_subtotal' => '250', 'max_subtotal' => null, 'price' => '20.00']),
        ]])['data'];
        [$small, $large] = [$zone['rates'][0]['id'], $zone['rates'][1]['id']];
        $this->quote(['shipping_country' => 'EG'], 2)->assertOk()->assertJsonPath('data.shipping.rate_id', $small)->assertJsonCount(1, 'data.shipping.rates')->assertJsonPath('data.totals.shipping_total', '60.00');
        $this->quote(['shipping_country' => 'EG'], 3)->assertOk()->assertJsonPath('data.shipping.rate_id', $large)->assertJsonPath('data.totals.shipping_total', '20.00');
        $this->quote(['shipping_country' => 'EG', 'shipping_rate_id' => $large], 2)->assertUnprocessable()->assertJsonValidationErrors('shipping_rate_id');
        Coupon::create(['store_id' => $this->store->id, 'code' => 'LESS60', 'type' => 'fixed', 'value' => 60, 'is_active' => true]);
        $this->quote(['shipping_country' => 'EG', 'coupon_code' => 'LESS60'], 3)->assertOk()->assertJsonPath('data.shipping.rate_id', $small)->assertJsonPath('data.totals.grand_total', '300.00');
    }

    public function test_weight_bands_use_product_and_variant_weights(): void
    {
        [, , $gulf] = $this->egyptAndGulf();
        [$light, $heavy] = [$gulf['rates'][0]['id'], $gulf['rates'][1]['id']];
        $this->quote(['shipping_country' => 'SA'])->assertOk()->assertJsonPath('data.shipping.rate_id', $light)->assertJsonPath('data.shipping.weight_grams', 1250);
        $this->quote(['shipping_country' => 'SA'], 2)->assertOk()->assertJsonPath('data.shipping.rate_id', $heavy)->assertJsonPath('data.totals.shipping_total', '200.00')->assertJsonPath('data.shipping.weight_grams', 2500);
        $this->quote(['shipping_country' => 'SA', 'shipping_rate_id' => $light], 2)->assertUnprocessable()->assertJsonValidationErrors('shipping_rate_id');
        $variant = $this->product->variants()->create(['store_id' => $this->store->id, 'name' => 'Mini', 'weight' => '0.400', 'options' => ['Size' => 'Mini'], 'is_active' => true, 'position' => 0]);
        $this->postJson($this->base.'/checkout/quote', ['items' => [['product_id' => $this->product->id, 'variant_id' => $variant->id, 'quantity' => 4]], 'shipping_country' => 'SA'])
            ->assertOk()->assertJsonPath('data.shipping.weight_grams', 1600)->assertJsonPath('data.shipping.rate_id', $light);
    }

    public function test_rate_free_over_threshold_cod_fee_and_discount_free_shipping(): void
    {
        $zone = $this->zone(['rates' => [$this->rate(['free_over' => '300.00', 'cod_fee' => '15.00'])]])['data'];
        $this->quote(['shipping_country' => 'EG', 'payment_method' => 'cod'])->assertOk()->assertJsonPath('data.totals.shipping_total', '65.00')->assertJsonPath('data.shipping.cod_fee', '15.00');
        $this->quote(['shipping_country' => 'EG', 'payment_method' => 'bank_transfer'])->assertOk()->assertJsonPath('data.totals.shipping_total', '50.00');
        $this->quote(['shipping_country' => 'EG'], 3)->assertOk()->assertJsonPath('data.totals.shipping_total', '0.00')->assertJsonPath('data.shipping.rates.0.amount', '0.00')->assertJsonPath('data.shipping.free_shipping_applied', true);
        Coupon::create(['store_id' => $this->store->id, 'method' => 'automatic', 'code' => null, 'title' => 'Free delivery', 'type' => 'free_shipping', 'value' => 0, 'is_active' => true]);
        $this->quote(['shipping_country' => 'EG', 'shipping_rate_id' => $zone['rates'][0]['id']])->assertOk()->assertJsonPath('data.totals.shipping_total', '0.00')->assertJsonPath('data.offer_free_shipping', true);
        // A cash-on-delivery fee is a payment fee: free shipping does not waive it.
        $this->quote(['shipping_country' => 'EG', 'payment_method' => 'cod'])->assertOk()->assertJsonPath('data.totals.shipping_total', '15.00');
    }

    public function test_tax_overrides_by_country_and_region_with_included_prices_and_tax_on_shipping(): void
    {
        $this->store->update(['tax_enabled' => true, 'tax_rate' => '10', 'tax_prices_include' => false]);
        $this->egyptAndGulf();
        $this->owner()->postJson('/api/v1/my-store/shipping/tax-overrides', ['country' => 'EG', 'region_id' => null, 'name' => ['ar' => 'ضريبة القيمة المضافة', 'en' => 'VAT'], 'rate' => '14', 'enabled' => true])->assertCreated()->assertJsonPath('data.rate', '14.000');
        $this->owner()->postJson('/api/v1/my-store/shipping/tax-overrides', ['country' => 'EG', 'region_id' => self::R_CAIRO, 'name' => ['ar' => 'ضريبة القاهرة', 'en' => 'Cairo tax'], 'rate' => '5', 'enabled' => true])->assertCreated();
        $this->owner()->postJson('/api/v1/my-store/shipping/tax-overrides', ['country' => 'EG', 'region_id' => null, 'name' => ['ar' => 'م', 'en' => 'Dup'], 'rate' => '1', 'enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('country');
        $this->owner()->postJson('/api/v1/my-store/shipping/tax-overrides', ['country' => 'SA', 'region_id' => self::R_CAIRO, 'name' => ['ar' => 'م', 'en' => 'Wrong'], 'rate' => '1', 'enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('region_id');

        $this->quote(['shipping_country' => 'EG'], 2)->assertOk()->assertJsonPath('data.tax.name.en', 'VAT')->assertJsonPath('data.tax.rate', '14.000')->assertJsonPath('data.tax.source', 'override')
            ->assertJsonPath('data.totals.tax_total', '28.00')->assertJsonPath('data.totals.grand_total', '278.00');
        $this->quote(['shipping_country' => 'EG', 'shipping_region_id' => self::R_JEDDAH], 2)->assertOk()->assertJsonPath('data.tax.rate', '14.000');
        $this->quote(['shipping_country' => 'EG', 'shipping_region_id' => self::R_CAIRO], 2)->assertOk()->assertJsonPath('data.tax.name.en', 'Cairo tax')->assertJsonPath('data.totals.tax_total', '10.00');
        $this->quote(['shipping_country' => 'SA'], 2)->assertOk()->assertJsonPath('data.tax.name', null)->assertJsonPath('data.tax.source', 'store')->assertJsonPath('data.totals.tax_total', '20.00');

        // Prices include tax: the tax is extracted, never added.
        $this->store->update(['tax_prices_include' => true]);
        $this->quote(['shipping_country' => 'EG'], 2)->assertOk()->assertJsonPath('data.tax.included', true)->assertJsonPath('data.totals.tax_total', '24.56')->assertJsonPath('data.totals.grand_total', '250.00');
        // Charge tax on shipping: the 50.00 delivery joins the taxable base.
        $this->store->update(['tax_shipping' => true]);
        $this->quote(['shipping_country' => 'EG'], 2)->assertOk()->assertJsonPath('data.tax.shipping', true)->assertJsonPath('data.totals.tax_total', '30.70')->assertJsonPath('data.totals.grand_total', '250.00');
        $this->store->update(['tax_prices_include' => false]);
        $this->quote(['shipping_country' => 'EG'], 2)->assertOk()->assertJsonPath('data.totals.tax_total', '35.00')->assertJsonPath('data.totals.grand_total', '285.00');

        // The placed order uses the submitted address country, not a forged selection.
        $this->postJson($this->base.'/checkout', ['customer_name' => 'Buyer', 'customer_phone' => '01000000000', 'shipping_address' => ['line1' => 'Street 1', 'city' => 'Riyadh', 'country' => 'SA'],
            'shipping_country' => 'EG', 'items' => [['product_id' => $this->product->id, 'quantity' => 2]]])
            ->assertCreated()->assertJsonPath('data.tax_total', '40.00')->assertJsonPath('data.shipping_total', '200.00')->assertJsonPath('data.shipping_address.country', 'SA')
            ->assertJsonPath('data.shipping_address.delivery_option', 'Heavy')->assertJsonPath('data.shipping_address.shipping_details.mode', 'zone');
    }

    public function test_invalid_selections_are_rejected_before_any_order_or_cart_change(): void
    {
        [$cairo, , $gulf] = $this->egyptAndGulf();
        $order = fn (array $extra) => $this->postJson($this->base.'/checkout', array_merge(['customer_name' => 'Buyer', 'customer_phone' => '01000000000',
            'shipping_address' => ['line1' => 'Street 1', 'city' => 'Cairo', 'country' => 'EG'], 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]], $extra));
        $order(['shipping_rate_id' => $gulf['rates'][0]['id']])->assertUnprocessable()->assertJsonValidationErrors('shipping_rate_id');
        $order(['shipping_rate_id' => '99999999-9999-4999-8999-999999999999'])->assertUnprocessable()->assertJsonValidationErrors('shipping_rate_id');
        $order(['shipping_option_id' => self::O_EXPRESS])->assertUnprocessable()->assertJsonValidationErrors('shipping_option_id');
        $order(['shipping_region_id' => '99999999-9999-4999-8999-999999999999'])->assertUnprocessable()->assertJsonValidationErrors('shipping_region_id');
        $order(['shipping_address' => ['line1' => 'Street 1', 'city' => 'X']])->assertUnprocessable()->assertJsonValidationErrors('shipping_address.country');
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('carts', 0);

        $placed = $order(['shipping_region_id' => self::R_CAIRO, 'shipping_rate_id' => $cairo['rates'][0]['id']])->assertCreated()
            ->assertJsonPath('data.shipping_total', '30.00')->assertJsonPath('data.shipping_address.state', 'Cairo')->assertJsonPath('data.shipping_address.country', 'EG')
            ->assertJsonPath('data.shipping_address.shipping_details.zone.id', $cairo['id'])->assertJsonPath('data.shipping_address.shipping_details.weight_grams', 1250);
        $this->assertSame('Inside Cairo', $placed->json('data.shipping_address.delivery_option'));
    }

    public function test_settings_validation_warnings_permissions_and_store_isolation(): void
    {
        $egypt = $this->zone()['data'];
        $this->zone(['name' => ['ar' => 'ثانية', 'en' => 'Second']], 422);
        $this->zone(['countries' => ['SA'], 'regions' => [$this->region(self::R_CAIRO, 'Cairo', 'القاهرة')]], 422);
        $this->zone(['countries' => ['SA'], 'rates' => [$this->rate(['type' => 'weight', 'min_weight_grams' => 500, 'max_weight_grams' => 500])]], 422);
        $this->zone(['countries' => ['SA'], 'rates' => [$this->rate(['type' => 'price'])]], 422);
        $warned = $this->zone(['countries' => ['SA'], 'rates' => [
            $this->rate(['type' => 'weight', 'min_weight_grams' => 0, 'max_weight_grams' => 3000]),
            $this->rate(['name' => ['ar' => 'سريع', 'en' => 'Express'], 'type' => 'weight', 'min_weight_grams' => 1000, 'max_weight_grams' => null]),
            $this->rate(['name' => ['ar' => 'تالي', 'en' => 'Adjacent'], 'type' => 'price', 'min_subtotal' => '0', 'max_subtotal' => '100']),
            $this->rate(['name' => ['ar' => 'تالي ٢', 'en' => 'Adjacent 2'], 'type' => 'price', 'min_subtotal' => '100', 'max_subtotal' => null]),
        ]]);
        $this->assertSame([['type' => 'overlapping_bands', 'band' => 'weight', 'rates' => [$warned['data']['rates'][0]['id'], $warned['data']['rates'][1]['id']]]], $warned['warnings']);

        // Editing keeps rate identity, replaces removed rates and returns the zone.
        $rates = $warned['data']['rates'];
        $this->owner()->putJson('/api/v1/my-store/shipping/zones/'.$warned['data']['id'], ['name' => ['ar' => 'السعودية', 'en' => 'Saudi'], 'countries' => ['SA'], 'regions' => [], 'enabled' => true,
            'rates' => [array_merge($rates[0], ['price' => '75.50'])]])->assertOk()->assertJsonPath('data.rates.0.id', $rates[0]['id'])->assertJsonPath('data.rates.0.price', '75.50')->assertJsonCount(1, 'data.rates')->assertJsonPath('warnings', []);
        $this->owner()->getJson('/api/v1/my-store/shipping/zones')->assertOk()->assertJsonPath('data.mode', 'zones')->assertJsonCount(2, 'data.zones')
            ->assertJsonPath('data.legacy_zone.implicit', true)->assertJsonPath('data.legacy_zone.rates.0.price', '25.00');

        $employee = User::factory()->create(['parent_user_id' => $this->owner->id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($employee))->getJson('/api/v1/my-store/shipping/zones')->assertForbidden();
        $this->postJson('/api/v1/my-store/shipping/zones', [])->assertForbidden();
        $this->postJson('/api/v1/my-store/shipping/quote-preview', ['country' => 'EG', 'subtotal' => 1, 'weight_grams' => 1])->assertForbidden();

        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        $foreign = Store::create(['owner_user_id' => $other->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-zones', 'currency' => 'EGP', 'status' => 'active', 'shipping_enabled' => true]);
        StoreDomain::create(['store_id' => $foreign->id, 'host' => 'other-zones.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $otherToken = JwtTokenService::fromConfig()->issueAccessToken($other);
        $this->withToken($otherToken)->putJson('/api/v1/my-store/shipping/zones/'.$egypt['id'], ['name' => ['ar' => 'x', 'en' => 'x'], 'countries' => ['EG'], 'regions' => [], 'enabled' => true, 'rates' => [$this->rate()]])->assertNotFound();
        $this->withToken($otherToken)->deleteJson('/api/v1/my-store/shipping/zones/'.$egypt['id'])->assertNotFound();
        $this->withToken($otherToken)->getJson('/api/v1/my-store/shipping/zones')->assertOk()->assertJsonPath('data.mode', 'legacy')->assertJsonCount(0, 'data.zones');
        // The other store has no zones, so it still quotes its own legacy flat rate and rejects our rate.
        $foreignProduct = Product::create(['store_id' => $foreign->id, 'user_id' => $other->id, 'name' => 'Cup', 'slug' => 'cup', 'price' => 10, 'is_active' => true]);
        $this->postJson('http://other-zones.sellchase.com/api/v1/storefront/checkout/quote', ['items' => [['product_id' => $foreignProduct->id, 'quantity' => 1]], 'shipping_country' => 'EG', 'shipping_rate_id' => $egypt['rates'][0]['id']])
            ->assertUnprocessable()->assertJsonValidationErrors('shipping_rate_id');
        $this->postJson('http://other-zones.sellchase.com/api/v1/storefront/checkout/quote', ['items' => [['product_id' => $foreignProduct->id, 'quantity' => 1]], 'shipping_country' => 'EG'])
            ->assertOk()->assertJsonPath('data.totals.shipping_total', '0.00')->assertJsonPath('data.shipping.mode', 'flat');
        // A rate id copied from another store cannot be attached to a zone here.
        $this->withToken($otherToken)->postJson('/api/v1/my-store/shipping/zones', ['name' => ['ar' => 'x', 'en' => 'x'], 'countries' => ['EG'], 'regions' => [], 'enabled' => true, 'rates' => [$this->rate(['id' => $egypt['rates'][0]['id']])]])
            ->assertUnprocessable()->assertJsonValidationErrors('rates.0.id');
    }

    public function test_quote_preview_and_legacy_fallback_when_zones_are_removed_or_disabled(): void
    {
        $this->store->update(['tax_enabled' => true, 'tax_rate' => '14', 'tax_prices_include' => true]);
        [, $egypt, $gulf] = $this->egyptAndGulf();
        $this->owner()->postJson('/api/v1/my-store/shipping/quote-preview', ['country' => 'SA', 'subtotal' => '500', 'weight_grams' => 2500, 'payment_method' => 'cod'])->assertOk()
            ->assertJsonPath('data.available', true)->assertJsonPath('data.zone.id', $gulf['id'])->assertJsonPath('data.rate.name.en', 'Heavy')->assertJsonCount(1, 'data.rates')
            ->assertJsonPath('data.totals.shipping_total', '200.00')->assertJsonPath('data.tax.rate', '14.000')->assertJsonPath('data.totals.tax_total', '61.40');
        $this->owner()->postJson('/api/v1/my-store/shipping/quote-preview', ['country' => 'US', 'subtotal' => '10', 'weight_grams' => 0])->assertOk()
            ->assertJsonPath('data.available', false)->assertJsonStructure(['data' => ['errors' => ['shipping_country']]]);
        $this->owner()->postJson('/api/v1/my-store/shipping/quote-preview', ['country' => 'USA', 'subtotal' => '-1', 'weight_grams' => 0])->assertUnprocessable()->assertJsonValidationErrors(['country', 'subtotal']);

        // Saving the simple settings from an older client keeps "tax on shipping" untouched.
        $this->store->update(['tax_shipping' => true]);
        $legacy = $this->owner()->getJson('/api/v1/my-store/shipping')->assertOk()->assertJsonPath('data.tax_shipping', true)->assertJsonPath('data.zones_enabled', true)->json('data');
        unset($legacy['tax_shipping'], $legacy['zones_enabled'], $legacy['version']);
        $this->owner()->putJson('/api/v1/my-store/shipping', $legacy)->assertOk()->assertJsonPath('data.tax_shipping', true);

        // Disabling every zone brings back the untouched legacy settings (the implicit default zone).
        foreach ($this->owner()->getJson('/api/v1/my-store/shipping/zones')->json('data.zones') as $zone) {
            $this->owner()->putJson('/api/v1/my-store/shipping/zones/'.$zone['id'], array_merge($zone, ['enabled' => false]))->assertOk();
        }
        $this->quote([])->assertOk()->assertJsonPath('data.totals.shipping_total', '25.00')->assertJsonPath('data.shipping.mode', 'flat');
        $this->getJson($this->base.'/checkout/fields')->assertOk()->assertJsonPath('shipping.zones_enabled', false)->assertJsonPath('shipping.flat_rate', '25.00');
        $this->quote(['shipping_rate_id' => $egypt['rates'][0]['id']])->assertUnprocessable()->assertJsonValidationErrors('shipping_rate_id');
    }

    private function owner(): static
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
    }
}
