<?php

namespace Tests\Feature;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Commerce\StorefrontOrderBridge;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StorefrontVariantCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private ProductVariant $red;

    private ProductVariant $blue;

    private string $base = 'http://variants.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([BridgeStorefrontOrderJob::class]);
        $owner = User::factory()->create();
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Variants', 'slug' => 'variants', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'variants.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true]);
        $this->red = $this->variant('Red', '125.00');
        $this->blue = $this->variant('Blue', null);
    }

    private function variant(string $name, ?string $price): ProductVariant
    {
        return ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $this->product->id, 'name' => $name, 'sku' => 'BAG-'.$name, 'options' => ['color' => $name], 'price_override' => $price, 'is_active' => true]);
    }

    private function contact(array $extra = []): array
    {
        return array_merge(['customer_name' => 'Local buyer', 'customer_email' => 'buyer@example.test'], $extra);
    }

    public function test_cart_keeps_variants_separate_and_merges_only_identical_selections(): void
    {
        $token = $this->postJson($this->base.'/cart/items', ['store_product_id' => $this->product->id, 'variant_id' => $this->red->id, 'quantity' => 2])
            ->assertCreated()->assertJsonPath('data.subtotal', '250.00')->json('data.token');
        $headers = ['X-Cart-Token' => $token];
        $this->postJson($this->base.'/cart/items', ['store_product_id' => $this->product->id, 'variant_id' => $this->blue->id, 'quantity' => 1], $headers)->assertCreated();
        $this->postJson($this->base.'/cart/items', ['store_product_id' => $this->product->id, 'variant_id' => $this->red->id, 'quantity' => 1], $headers)
            ->assertCreated()->assertJsonCount(2, 'data.items')->assertJsonPath('data.subtotal', '475.00')->assertJsonPath('data.items.0.variant_id', $this->red->id);
    }

    public function test_checkout_prices_and_snapshots_each_selection_and_bridge_preserves_it(): void
    {
        $orderId = $this->postJson($this->base.'/checkout', $this->contact(['items' => [
            ['product_id' => $this->product->id, 'variant_id' => $this->red->id, 'quantity' => 2, 'unit_price' => 1],
            ['product_id' => $this->product->id, 'variant_id' => $this->blue->id, 'quantity' => 1],
        ]]))->assertCreated()->assertJsonPath('data.subtotal', '350.00')->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.variant_name', 'Red')->assertJsonPath('data.items.0.variant_options.color', 'Red')
            ->assertJsonPath('data.items.0.sku', 'BAG-Red')->assertJsonPath('data.items.0.unit_price', '125.00')->json('data.id');
        $this->red->update(['name' => 'Renamed', 'price_override' => 900, 'options' => ['color' => 'Changed']]);
        app(CurrentStore::class)->set($this->store);
        $order = StoreOrder::query()->with('items')->findOrFail($orderId);
        $bridge = app(StorefrontOrderBridge::class)->bridge($order, $this->store);
        $this->assertSame('Red', $bridge->storefront_items[0]['variant_name']);
        $this->assertSame(['color' => 'Red'], $bridge->storefront_items[0]['variant_options']);
        $this->assertSame('125.00', $bridge->storefront_items[0]['unit_price']);
    }

    public function test_missing_inactive_wrong_product_and_wrong_store_options_are_rejected(): void
    {
        $this->postJson($this->base.'/checkout', $this->contact(['items' => [['product_id' => $this->product->id, 'quantity' => 1]]]))->assertUnprocessable();
        $this->red->update(['is_active' => false]);
        $this->postJson($this->base.'/checkout', $this->contact(['items' => [['product_id' => $this->product->id, 'variant_id' => $this->red->id, 'quantity' => 1]]]))->assertUnprocessable();
        $otherProduct = Product::create(['store_id' => $this->store->id, 'user_id' => $this->store->owner_user_id, 'name' => 'Other', 'slug' => 'other', 'price' => 50, 'is_active' => true]);
        $this->postJson($this->base.'/checkout', $this->contact(['items' => [['product_id' => $otherProduct->id, 'variant_id' => $this->blue->id, 'quantity' => 1]]]))->assertUnprocessable();
        $otherStore = Store::create(['owner_user_id' => $this->store->owner_user_id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        // Even inconsistent imported records cannot attach a foreign store's variant.
        $this->blue->update(['store_id' => $otherStore->id]);
        $this->postJson($this->base.'/checkout', $this->contact(['items' => [['product_id' => $this->product->id, 'variant_id' => $this->blue->id, 'quantity' => 1]]]))->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_deleted_or_repriced_variant_does_not_fall_back_to_parent_price(): void
    {
        $token = $this->postJson($this->base.'/cart/items', ['store_product_id' => $this->product->id, 'variant_id' => $this->red->id, 'quantity' => 1])->assertCreated()->json('data.token');
        $this->red->update(['price_override' => 150]);
        $this->postJson($this->base.'/checkout', $this->contact(), ['X-Cart-Token' => $token])->assertUnprocessable();
        $this->red->delete();
        $this->postJson($this->base.'/checkout', $this->contact(), ['X-Cart-Token' => $token])->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseHas('cart_items', ['variant_id' => $this->red->id, 'unit_price' => 125]);
    }

    public function test_failed_submitted_selection_preserves_previous_cart_atomically(): void
    {
        $token = $this->postJson($this->base.'/cart/items', ['store_product_id' => $this->product->id, 'variant_id' => $this->blue->id, 'quantity' => 3])->assertCreated()->json('data.token');
        $headers = ['X-Cart-Token' => $token];
        $this->postJson($this->base.'/checkout', $this->contact(['items' => [
            ['product_id' => $this->product->id, 'variant_id' => $this->red->id, 'quantity' => 1],
            ['product_id' => $this->product->id, 'variant_id' => 999999, 'quantity' => 1],
        ]]), $headers)->assertUnprocessable();
        $this->getJson($this->base.'/cart', $headers)->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.variant_id', $this->blue->id)->assertJsonPath('data.items.0.quantity', 3);
    }

    public function test_signing_in_merges_matching_variants_without_losing_other_options(): void
    {
        $auth = $this->postJson($this->base.'/auth/register', ['name' => 'Buyer', 'email' => 'merge@example.test', 'password' => 'password123'])->assertCreated()->json('token');
        $this->postJson($this->base.'/cart/items', ['store_product_id' => $this->product->id, 'variant_id' => $this->red->id, 'quantity' => 1], ['Authorization' => 'Bearer '.$auth])->assertCreated();
        $guest = $this->postJson($this->base.'/cart/items', ['store_product_id' => $this->product->id, 'variant_id' => $this->red->id, 'quantity' => 2])->assertCreated()->json('data.token');
        $this->postJson($this->base.'/cart/items', ['store_product_id' => $this->product->id, 'variant_id' => $this->blue->id, 'quantity' => 1], ['X-Cart-Token' => $guest])->assertCreated();
        $this->getJson($this->base.'/cart', ['Authorization' => 'Bearer '.$auth, 'X-Cart-Token' => $guest])->assertOk()
            ->assertJsonCount(2, 'data.items')->assertJsonPath('data.subtotal', '475.00');
    }

    public function test_quote_and_order_use_identical_live_totals_without_quote_side_effects(): void
    {
        $this->store->update(['shipping_enabled' => true, 'shipping_flat_rate' => 30, 'tax_enabled' => true, 'tax_rate' => 10, 'tax_prices_include' => false]);
        Coupon::create(['store_id' => $this->store->id, 'code' => 'SAVE25', 'type' => 'fixed', 'value' => 25, 'is_active' => true]);
        $selection = ['items' => [['product_id' => $this->product->id, 'variant_id' => $this->red->id, 'quantity' => 2, 'unit_price' => 1]], 'coupon_code' => 'save25'];
        $quote = $this->postJson($this->base.'/checkout/quote', $selection)->assertOk()
            ->assertJsonPath('data.currency', 'EGP')->assertJsonPath('data.totals.subtotal', '250.00')
            ->assertJsonPath('data.totals.discount_total', '25.00')->assertJsonPath('data.totals.shipping_total', '30.00')
            ->assertJsonPath('data.totals.tax_total', '22.50')->assertJsonPath('data.totals.grand_total', '277.50')->json('data.totals');
        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('coupon_usages', 0);
        $order = $this->postJson($this->base.'/checkout', $this->contact($selection))->assertCreated();
        foreach ($quote as $key => $value) {
            $order->assertJsonPath('data.'.$key, $value);
        }
        $this->assertDatabaseCount('coupon_usages', 1);
    }

    public function test_direct_funnel_order_preserves_signed_in_customer_cart(): void
    {
        $token = $this->postJson($this->base.'/auth/register', ['name' => 'Buyer', 'email' => 'direct@example.test', 'password' => 'password123'])->assertCreated()->json('token');
        $auth = ['Authorization' => 'Bearer '.$token];
        $this->postJson($this->base.'/cart/items', ['store_product_id' => $this->product->id, 'variant_id' => $this->blue->id, 'quantity' => 3], $auth)->assertCreated();
        $this->postJson($this->base.'/checkout', $this->contact(['cart_mode' => 'direct', 'items' => [
            ['product_id' => $this->product->id, 'variant_id' => $this->red->id, 'quantity' => 1],
        ]]), $auth)->assertCreated()->assertJsonPath('data.subtotal', '125.00');
        $this->getJson($this->base.'/cart', $auth)->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.variant_id', $this->blue->id)->assertJsonPath('data.items.0.quantity', 3);
        $this->getJson($this->base.'/orders', $auth)->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson($this->base.'/checkout', $this->contact(['cart_mode' => 'direct']), $auth)->assertUnprocessable();
    }

    public function test_quote_rejects_invalid_options_and_expired_coupons_without_modifying_cart(): void
    {
        $items = [['product_id' => $this->product->id, 'variant_id' => $this->red->id, 'quantity' => 1]];
        $this->postJson($this->base.'/checkout/quote', ['items' => $items, 'coupon_code' => 'UNKNOWN'])->assertUnprocessable();
        $coupon = Coupon::create(['store_id' => $this->store->id, 'code' => 'TODAY', 'type' => 'fixed', 'value' => 10, 'is_active' => true]);
        $this->postJson($this->base.'/checkout/quote', ['items' => $items, 'coupon_code' => 'TODAY'])->assertOk();
        $coupon->update(['expires_at' => now()->subMinute()]);
        $this->postJson($this->base.'/checkout', $this->contact(['items' => $items, 'coupon_code' => 'TODAY']))->assertUnprocessable();
        $this->red->update(['is_active' => false]);
        $this->postJson($this->base.'/checkout/quote', ['items' => $items])->assertUnprocessable();
        $this->postJson($this->base.'/checkout/quote', ['items' => [['product_id' => $this->product->id, 'quantity' => 0]]])->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('cart_items', 0);
    }
}
