<?php

namespace Tests\Feature;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreDomain;
use App\Models\StorePaymentGateway;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CheckoutFields;
use App\Services\Commerce\CustomerAuthService;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DigitalCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $digital;

    private Product $physical;

    private string $base = 'http://digital-checkout.sellchase.com/api/v1/storefront';

    private string $region;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([BridgeStorefrontOrderJob::class]);
        $owner = User::factory()->create();
        $this->region = (string) Str::uuid();
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Digital checkout', 'slug' => 'digital-checkout', 'currency' => 'EGP', 'status' => 'active', 'default_locale' => 'en',
            'checkout_fields' => app(CheckoutFields::class)->defaults(), 'shipping_enabled' => true, 'shipping_flat_rate' => 25,
            'tax_enabled' => true, 'tax_rate' => 10, 'tax_prices_include' => false,
            'shipping_configuration' => ['regions_enabled' => true, 'auto_select_region' => false, 'options' => [],
                'regions' => [['id' => $this->region, 'name' => ['ar' => 'القاهرة', 'en' => 'Cairo'], 'country' => 'EG', 'rate' => '40.00', 'enabled' => true, 'position' => 0]]]]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'digital-checkout.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true, 'credentials' => []]);
        $this->digital = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Guide', 'slug' => 'guide', 'price' => 100, 'is_active' => true, 'digital_type' => 'link', 'digital_url' => 'https://example.test/private-guide']);
        $this->physical = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 50, 'is_active' => true, 'digital_type' => 'physical']);
    }

    private function items(bool $mixed = false): array
    {
        return array_merge([['product_id' => $this->digital->id, 'quantity' => 1]], $mixed ? [['product_id' => $this->physical->id, 'quantity' => 1]] : []);
    }

    private function contact(): array
    {
        return ['customer_name' => 'Local buyer', 'customer_phone' => '01000000000', 'customer_email' => 'buyer@example.test'];
    }

    public function test_digital_fields_require_email_but_no_shipping_even_with_regions_and_required_address(): void
    {
        $response = $this->getJson($this->base.'/checkout/fields?'.http_build_query(['product_ids' => [$this->digital->id], 'payment_method' => 'bank_transfer']))
            ->assertOk()->assertJsonPath('requires_shipping', false)->assertJsonPath('has_digital', true)->assertJsonPath('shipping.enabled', false)->assertJsonCount(0, 'shipping.regions');
        $fields = collect($response->json('data'))->keyBy('key');
        $this->assertTrue($fields['email']['enabled']);
        $this->assertTrue($fields['email']['required']);
        $this->assertTrue($fields['email']['digital_required']);
        foreach (['address', 'city', 'country', 'postal_code', 'national_address', 'phone_alt'] as $key) {
            $this->assertFalse($fields[$key]['enabled']);
            $this->assertFalse($fields[$key]['required']);
        }
        $this->assertDatabaseCount('carts', 0);
        $this->getJson($this->base.'/checkout/fields?'.http_build_query(['product_ids' => [$this->digital->id, $this->physical->id]]))->assertOk()->assertJsonPath('requires_shipping', true)->assertJsonPath('shipping.regions_enabled', true);
    }

    public function test_digital_quote_and_order_ignore_shipping_and_preserve_discount_and_tax(): void
    {
        Coupon::create(['store_id' => $this->store->id, 'code' => 'LESS20', 'type' => 'fixed', 'value' => 20, 'is_active' => true]);
        $data = ['items' => $this->items(), 'coupon_code' => 'LESS20', 'shipping_region_id' => (string) Str::uuid(), 'shipping_total' => 999];
        $this->postJson($this->base.'/checkout/quote', $data)->assertOk()->assertJsonPath('data.requires_shipping', false)->assertJsonPath('data.totals.shipping_total', '0.00')->assertJsonPath('data.totals.tax_total', '8.00')->assertJsonPath('data.totals.grand_total', '88.00');
        $this->assertDatabaseCount('carts', 0);
        $this->postJson($this->base.'/checkout', $data + $this->contact() + ['shipping_address' => ['line1' => 'Stale address', 'city' => 'Old city', 'country' => 'EG']])
            ->assertCreated()->assertJsonPath('data.shipping_address', null)->assertJsonPath('data.shipping_total', '0.00')->assertJsonPath('data.tax_total', '8.00')->assertJsonPath('data.grand_total', '88.00')
            ->assertJsonPath('data.items.0.digital_delivery.status', 'awaiting_payment')->assertJsonPath('data.items.0.digital_delivery.values', []);
        $this->store->update(['tax_prices_include' => true]);
        $this->postJson($this->base.'/checkout/quote', ['items' => $this->items()])->assertOk()->assertJsonPath('data.totals.shipping_total', '0.00')->assertJsonPath('data.totals.tax_total', '9.09')->assertJsonPath('data.totals.grand_total', '100.00');
    }

    public function test_digital_checkout_rejects_missing_email_before_cart_or_order_mutation(): void
    {
        $contact = $this->contact();
        unset($contact['customer_email']);
        $this->postJson($this->base.'/checkout', ['items' => $this->items()] + $contact)->assertUnprocessable()->assertJsonValidationErrors('customer_email');
        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_mixed_cart_requires_address_and_region_and_ignores_client_shipping_flag(): void
    {
        $data = ['items' => $this->items(true), 'requires_shipping' => false] + $this->contact();
        $this->postJson($this->base.'/checkout/quote', $data)->assertUnprocessable()->assertJsonValidationErrors('shipping_region_id');
        $this->postJson($this->base.'/checkout', $data)->assertUnprocessable()->assertJsonValidationErrors('shipping_address.line1');
        $this->assertDatabaseCount('carts', 0);
        $data['shipping_address'] = ['line1' => 'Local address'];
        $data['shipping_region_id'] = $this->region;
        $this->postJson($this->base.'/checkout/quote', $data)->assertOk()->assertJsonPath('data.requires_shipping', true)->assertJsonPath('data.totals.shipping_total', '40.00')->assertJsonPath('data.totals.tax_total', '15.00')->assertJsonPath('data.totals.grand_total', '205.00');
        $this->postJson($this->base.'/checkout', $data)->assertCreated()->assertJsonPath('data.shipping_total', '40.00')->assertJsonPath('data.shipping_address.city', 'Cairo')->assertJsonPath('data.grand_total', '205.00');
    }

    public function test_guest_server_cart_fields_coupon_and_checkout_are_shippingless(): void
    {
        $cart = app(CartService::class)->create($this->store, null);
        app(CartService::class)->addItem($cart, $this->digital->id, 1);
        $headers = ['X-Cart-Token' => $cart->token];
        $this->getJson($this->base.'/checkout/fields', $headers)->assertOk()->assertJsonPath('requires_shipping', false);
        Coupon::create(['store_id' => $this->store->id, 'code' => 'LESS20', 'type' => 'fixed', 'value' => 20, 'is_active' => true]);
        $this->postJson($this->base.'/checkout/coupon/apply', ['code' => 'LESS20'], $headers)->assertOk()->assertJsonPath('totals.shipping_total', '0.00')->assertJsonPath('totals.grand_total', '88.00');
        $this->deleteJson($this->base.'/checkout/coupon', [], $headers)->assertOk()->assertJsonPath('totals.shipping_total', '0.00')->assertJsonPath('totals.grand_total', '110.00');
        $this->postJson($this->base.'/checkout', $this->contact(), $headers)->assertCreated()->assertJsonPath('data.shipping_address', null)->assertJsonPath('data.grand_total', '110.00');
        $this->assertDatabaseCount('carts', 1);
    }

    public function test_customer_and_guest_carts_are_inspected_together_without_merging(): void
    {
        $customer = StoreCustomer::create(['name' => 'Buyer', 'email' => 'account@example.test', 'password' => bcrypt('local-review'), 'is_active' => true]);
        $token = app(CustomerAuthService::class)->issueToken($customer);
        $own = app(CartService::class)->create($this->store, $customer);
        app(CartService::class)->addItem($own, $this->digital->id, 1);
        $guest = app(CartService::class)->create($this->store, null);
        app(CartService::class)->addItem($guest, $this->physical->id, 1);
        $headers = ['Authorization' => 'Bearer '.$token, 'X-Cart-Token' => $guest->token];
        $this->getJson($this->base.'/checkout/fields', $headers)->assertOk()->assertJsonPath('requires_shipping', true)->assertJsonPath('has_digital', true);
        $this->assertSame('active', $guest->fresh()->status);
        $this->assertSame(1, $own->items()->count());
        $this->assertSame(1, $guest->items()->count());
        $this->postJson($this->base.'/checkout', $this->contact(), $headers)->assertUnprocessable()->assertJsonValidationErrors('shipping_address.line1');
        $this->assertSame('active', $guest->fresh()->status);
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_unknown_and_foreign_products_cannot_make_the_form_shippingless(): void
    {
        $this->getJson($this->base.'/checkout/fields?'.http_build_query(['product_ids' => [$this->digital->id, 999999]]))->assertUnprocessable()->assertJsonValidationErrors('product_ids');
        $other = Store::create(['owner_user_id' => $this->store->owner_user_id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-digital-checkout', 'status' => 'active']);
        $foreign = Product::create(['store_id' => $other->id, 'user_id' => $other->owner_user_id, 'name' => 'Other guide', 'slug' => 'other-guide', 'price' => 100, 'is_active' => true, 'digital_type' => 'link', 'digital_url' => 'https://example.test/other-private']);
        $this->getJson($this->base.'/checkout/fields?'.http_build_query(['product_ids' => [$foreign->id]]))->assertUnprocessable();
        $this->postJson($this->base.'/checkout/quote', ['items' => [['product_id' => $foreign->id, 'quantity' => 1]], 'requires_shipping' => false])->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertSame(0, Cart::count());
    }

    public function test_digital_only_payment_methods_exclude_cod_and_checkout_rejects_forged_cod(): void
    {
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true, 'credentials' => [], 'sort_order' => -1]);
        $digitalQuery = http_build_query(['product_ids' => [$this->digital->id]]);
        $this->getJson($this->base.'/payment-methods?'.$digitalQuery)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'bank_transfer');
        $this->postJson($this->base.'/checkout', ['items' => $this->items(), 'payment_method' => 'cod'] + $this->contact())->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertDatabaseCount('carts', 0);
        StorePaymentGateway::where('gateway', 'bank_transfer')->update(['enabled' => false]);
        $this->getJson($this->base.'/payment-methods?'.$digitalQuery)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->base.'/checkout', ['items' => $this->items()] + $this->contact())->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->getJson($this->base.'/payment-methods?'.http_build_query(['product_ids' => [$this->digital->id, $this->physical->id]]))->assertOk()->assertJsonPath('data.0.slug', 'cod');
        $this->assertDatabaseCount('store_orders', 0);
    }
}
