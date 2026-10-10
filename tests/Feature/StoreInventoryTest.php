<?php

namespace Tests\Feature;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\StoreOrderService;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StoreInventoryTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private User $owner;

    private string $public = 'http://stock.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([BridgeStorefrontOrderJob::class]);
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Stock', 'slug' => 'stock', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'stock.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 3]);
    }

    private function payload(int $quantity = 1, ?int $variant = null): array
    {
        return ['customer_name' => 'Stock buyer', 'customer_email' => 'stock@example.test', 'items' => [
            ['product_id' => $this->product->id, 'variant_id' => $variant, 'quantity' => $quantity],
        ]];
    }

    private function place(int $quantity = 1, ?int $variant = null): StoreOrder
    {
        $id = $this->postJson($this->public.'/checkout', $this->payload($quantity, $variant))->assertCreated()->json('data.id');
        app(CurrentStore::class)->set($this->store);

        return StoreOrder::findOrFail($id);
    }

    public function test_checkout_reserves_last_units_and_rejects_a_second_cart_without_side_effects(): void
    {
        $order = $this->place(3);
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'stock_quantity' => 3, 'reserved_quantity' => 3]);
        $this->assertDatabaseHas('store_order_items', ['store_order_id' => $order->id, 'inventory_status' => 'reserved']);
        $this->postJson($this->public.'/checkout', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson($this->public.'/checkout/quote', ['items' => $this->payload()['items']])->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseCount('carts', 1);
        $this->assertDatabaseCount('store_inventory_movements', 1);
        $this->getJson($this->public.'/products/bag')->assertOk()->assertJsonPath('data.stock', 0);
    }

    public function test_cancellation_releases_once_even_when_another_caller_holds_a_stale_order(): void
    {
        $order = $this->place(2);
        $stale = StoreOrder::findOrFail($order->id);
        app(StoreOrderService::class)->transition($order, 'cancelled');
        try {
            app(StoreOrderService::class)->transition($stale, 'cancelled');
            $this->fail('A stale repeat cannot release twice.');
        } catch (ValidationException) {
        }
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'stock_quantity' => 3, 'reserved_quantity' => 0]);
        $this->assertDatabaseCount('store_inventory_movements', 2);
        $this->assertDatabaseHas('store_order_items', ['store_order_id' => $order->id, 'inventory_status' => 'released']);
        $this->place(3);
    }

    public function test_shipping_consumes_reserved_stock_once_and_delivery_does_not_deduct_again(): void
    {
        $order = $this->place(2);
        $orders = app(StoreOrderService::class);
        foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $state) {
            $orders->transition($order, $state);
        }
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'stock_quantity' => 1, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('store_order_items', ['store_order_id' => $order->id, 'inventory_status' => 'committed']);
        $this->assertDatabaseCount('store_inventory_movements', 2);
        $this->assertSame('pending', $order->payment_status);
    }

    public function test_variant_stock_is_independent_and_untracked_options_remain_buyable(): void
    {
        $variant = ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $this->product->id, 'name' => 'Red', 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 1]);
        $other = ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $this->product->id, 'name' => 'Blue', 'is_active' => true]);
        $this->place(1, $variant->id);
        $this->postJson($this->public.'/checkout', $this->payload(1, $variant->id))->assertUnprocessable();
        $this->place(8, $other->id);
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'stock_quantity' => 3, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('store_product_variants', ['id' => $variant->id, 'reserved_quantity' => 1]);
        $this->assertDatabaseHas('store_product_variants', ['id' => $other->id, 'reserved_quantity' => 0]);
        $response = $this->getJson($this->public.'/products/bag')->assertOk();
        $response->assertJsonPath('data.variants.0.stock', 0)->assertJsonPath('data.variants.1.stock', null);
        $this->getJson($this->public.'/products')->assertOk()->assertJsonPath('data.0.variants.0.stock', 0)->assertJsonPath('data.0.variants.1.stock', null);
    }

    public function test_duplicate_lines_are_aggregated_and_failed_checkout_retains_the_cart(): void
    {
        $token = $this->postJson($this->public.'/cart/items', ['store_product_id' => $this->product->id, 'quantity' => 1])->assertCreated()->json('data.token');
        $data = $this->payload(2);
        $data['items'][] = $data['items'][0];
        $this->postJson($this->public.'/checkout', $data, ['X-Cart-Token' => $token])->assertUnprocessable();
        $this->getJson($this->public.'/cart', ['X-Cart-Token' => $token])->assertOk()->assertJsonPath('data.items.0.quantity', 1);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('store_inventory_movements', 0);
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'reserved_quantity' => 0]);
    }

    public function test_checkout_reloads_cart_under_lock_and_converted_cart_cannot_be_reused_or_edited(): void
    {
        app(CurrentStore::class)->set($this->store);
        $carts = app(CartService::class);
        $cart = $carts->create($this->store, null);
        $item = $carts->addItem($cart, $this->product->id, 1);
        $cart->load('items');
        $carts->updateItem($cart, $item, 2);
        $checkout = app(CheckoutService::class);
        $order = $checkout->place($this->store, $cart, null, ['name' => 'Buyer', 'email' => null], null, 'cod');
        $this->assertSame(2, $order->items->first()->quantity);
        foreach ([fn () => $checkout->place($this->store, $cart, null, ['name' => 'Buyer', 'email' => null], null, 'cod'), fn () => $carts->clear($cart), fn () => $carts->updateItem($cart, $item, 1)] as $action) {
            try {
                $action();
                $this->fail('Converted cart must be immutable.');
            } catch (ValidationException) {
            }
        }
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'reserved_quantity' => 2]);
    }

    public function test_stock_editor_rejects_stale_edits_and_reservation_undercuts_with_tenant_isolation(): void
    {
        $this->place(2);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
        $base = '/api/v1/my-store/catalog/inventory';
        $input = ['track_inventory' => true, 'stock_quantity' => 5, 'expected_stock' => 3, 'expected_reserved' => 2, 'expected_tracking' => true, 'note' => 'Restocked'];
        $this->getJson($base)->assertOk()->assertJsonPath('data.0.reserved_quantity', 2);
        $this->putJson($base.'/'.$this->product->id, array_merge($input, ['stock_quantity' => 1]))->assertUnprocessable();
        $this->putJson($base.'/'.$this->product->id, array_merge($input, ['track_inventory' => false]))->assertUnprocessable();
        $this->putJson($base.'/'.$this->product->id, $input)->assertOk();
        $this->putJson($base.'/'.$this->product->id, $input)->assertConflict();
        $this->getJson($base.'/'.$this->product->id.'/history')->assertOk()->assertJsonPath('data.0.reason', 'adjusted')->assertJsonPath('data.0.stock_after', 5);
        app(CurrentStore::class)->forget();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-stock', 'status' => 'active']);
        $foreign = Product::create(['store_id' => $other->id, 'name' => 'Foreign', 'slug' => 'foreign', 'price' => 1, 'is_active' => true]);
        $this->putJson($base.'/'.$foreign->id, $input)->assertNotFound();
        $this->getJson($base.'/'.$foreign->id.'/history')->assertNotFound();
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'stock_quantity' => 5, 'reserved_quantity' => 2]);
    }

    public function test_catalog_cannot_delete_reserved_product_or_option(): void
    {
        $variant = ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $this->product->id, 'name' => 'Red', 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 1]);
        $this->place(1, $variant->id);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
        $path = '/api/v1/my-store/catalog/products/'.$this->product->id;
        $this->deleteJson($path)->assertUnprocessable();
        $this->deleteJson($path.'/variants/'.$variant->id)->assertUnprocessable();
        $this->assertDatabaseHas('store_product_variants', ['id' => $variant->id, 'reserved_quantity' => 1]);
    }

    public function test_variant_routes_manage_only_options_of_the_authorized_product(): void
    {
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
        $path = '/api/v1/my-store/catalog/products/'.$this->product->id.'/variants';
        $this->putJson('/api/v1/my-store/catalog/inventory/'.$this->product->id, ['track_inventory' => true, 'stock_quantity' => 0, 'expected_stock' => 3, 'expected_reserved' => 0, 'expected_tracking' => true, 'note' => 'Allocate stock to variants instead'])->assertOk();
        $id = $this->postJson($path, ['name' => 'Green', 'price_override' => 110, 'options' => ['color' => 'green'], 'is_active' => true])->assertCreated()->json('data.id');
        $this->getJson($path)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->putJson($path.'/'.$id, ['name' => 'Green large', 'price_override' => 120])->assertOk()->assertJsonPath('data.price_override', '120.00');
        $this->putJson('/api/v1/my-store/catalog/products/999999/variants/'.$id, ['name' => 'Wrong parent'])->assertNotFound();
        $this->deleteJson($path.'/'.$id)->assertOk();
        $this->assertDatabaseMissing('store_product_variants', ['id' => $id]);
    }

    public function test_customer_with_stale_confirmed_order_cannot_cancel_after_processing_started(): void
    {
        $order = $this->place();
        $service = app(StoreOrderService::class);
        $service->transition($order, 'confirmed');
        $stale = StoreOrder::findOrFail($order->id);
        $service->transition($order, 'processing');
        try {
            $service->cancelByCustomer($stale);
            $this->fail('Customer cancellation cannot race processing.');
        } catch (ValidationException) {
        }
        $this->assertDatabaseHas('store_orders', ['id' => $order->id, 'status' => 'processing']);
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'reserved_quantity' => 1]);
        // A fresh non-cancellable order must produce validation, never an HTTP 500.
        $this->expectException(ValidationException::class);
        $service->cancelByCustomer($order);
    }

    public function test_untracked_zero_stock_is_purchasable_without_a_reservation(): void
    {
        $this->product->update(['track_inventory' => false, 'stock_quantity' => 0]);
        $order = $this->place(5);
        app(StoreOrderService::class)->transition($order, 'cancelled');
        $this->assertDatabaseCount('store_inventory_movements', 0);
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'stock_quantity' => 0, 'reserved_quantity' => 0]);
        $this->getJson($this->public.'/products/bag')->assertOk()->assertJsonPath('data.stock', null);
    }
}
