<?php

namespace Tests\Feature;

use App\Http\Resources\OrderApiResource;
use App\Jobs\BridgeStorefrontOrderJob;
use App\Models\Order;
use App\Models\OrderSuppliers;
use App\Models\OutboxMessage;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\User;
use App\Notifications\OrderAssignedToSupplierNotification;
use App\Services\Commerce\StorefrontOrderBridge;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Workstream D: storefront checkout -> B2B order bridge (source = storefront),
 * routing rules, idempotency, cancel mirroring, the `source` filter and the
 * bridge fields exposed by OrderApiResource / MerchantOrderResource.
 */
class StorefrontOrderBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);

        // Deterministic routing: no config-driven fallback supplier in tests.
        config()->set('services.sellchase_fallback_supplier_user_id', null);
        config()->set('services.sellchase_sync_use_first_supplier_when_empty', false);
    }

    /** @return array{0:User,1:Store,2:Product} */
    private function makeStore(string $slug, string $ownerRole = 'Merchant'): array
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole($ownerRole);
        $store = Store::create([
            'owner_user_id' => $user->id, 'owner_type' => strtolower($ownerRole),
            'name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'USD', 'status' => 'active',
        ]);
        StoreDomain::create(['store_id' => $store->id, 'host' => "{$slug}.sellchase.com", 'type' => 'subdomain', 'is_primary' => true]);
        $product = Product::create([
            'store_id' => $store->id, 'user_id' => $user->id,
            'name' => ucfirst($slug).' Tee', 'slug' => $slug.'-tee', 'price' => '50.00', 'is_active' => true,
            'image' => 'https://cdn.example.com/tee.jpg',
        ]);

        return [$user, $store, $product];
    }

    private function makeSupplier(): User
    {
        $supplier = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $supplier->assignRole('Supplier');

        return $supplier;
    }

    private function as(User $user): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    /** @return array{id:int, number:string, auth:array<string,string>, host:string} */
    private function placeStorefrontOrder(Store $store, Product $product, int $quantity = 2, string $email = 'c@example.com'): array
    {
        $host = "http://{$store->slug}.sellchase.com";
        $token = $this->postJson($host.'/api/v1/storefront/auth/register', [
            'name' => 'C', 'email' => $email, 'password' => 'password123',
        ])->assertCreated()->json('token');
        $auth = ['Authorization' => "Bearer {$token}"];

        $this->postJson($host.'/api/v1/storefront/cart/items', ['store_product_id' => $product->id, 'quantity' => $quantity], $auth)->assertCreated();
        $order = $this->postJson($host.'/api/v1/storefront/checkout', [
            'customer_name' => 'C', 'customer_email' => $email, 'customer_phone' => '+100',
            'shipping_address' => ['name' => 'C', 'line1' => '1 Main St', 'city' => 'Dubai', 'country' => 'AE'],
        ], $auth)->assertCreated();

        return ['id' => $order->json('data.id'), 'number' => $order->json('data.order_number'), 'auth' => $auth, 'host' => $host];
    }

    private function makeDirectOrder(User $owner, Product $product): Order
    {
        return Order::create([
            'code' => 'ORD-'.$owner->id.'-DIRECT', 'quantity' => 1, 'product_id' => $product->id, 'user_id' => $owner->id,
            'attributes' => 'a:0:{}', 'notes' => '', 'status' => 'pending', 'source' => Order::SOURCE_MERCHANT_DIRECT,
        ]);
    }

    // ------------------------------------------------------------ 1. merchant store, accepted partner

    public function test_merchant_store_order_is_bridged_and_routed_to_accepted_supplier(): void
    {
        Notification::fake();
        [$merchant, $store, $tee] = $this->makeStore('alpha');
        $supplier = $this->makeSupplier();
        $merchant->suppliersAsMerchant()->attach($supplier->id, ['status' => 'accepted']);

        $placed = $this->placeStorefrontOrder($store, $tee, 3);

        $b2b = Order::query()->where('store_order_id', $placed['id'])->first();
        $this->assertNotNull($b2b);
        $this->assertSame(Order::SOURCE_STOREFRONT, $b2b->source);
        $this->assertSame('SF-'.$placed['number'], $b2b->code);
        $this->assertSame($placed['number'], $b2b->ref_number);
        $this->assertSame($store->id, (int) $b2b->store_id);
        $this->assertSame($merchant->id, (int) $b2b->user_id);
        $this->assertSame($tee->id, (int) $b2b->product_id);
        $this->assertSame(3, (int) $b2b->quantity);
        $this->assertSame('a:0:{}', $b2b->attributes);
        $this->assertSame('C', $b2b->customer_name);
        $this->assertSame('USD', $b2b->currency);
        $this->assertSame('cod', $b2b->payment_method);
        $this->assertCount(1, $b2b->storefront_items);
        $this->assertSame($tee->name, $b2b->storefront_items[0]['name']);
        $this->assertSame('150.00', $b2b->storefront_items[0]['line_total']);
        $this->assertStringContainsString('Dubai', (string) $b2b->shipping_address);

        $this->assertDatabaseHas('order_suppliers', ['order_id' => $b2b->id, 'customer' => $merchant->id, 'supplier' => $supplier->id]);
        Notification::assertSentTo($supplier, OrderAssignedToSupplierNotification::class);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'StorefrontOrderBridged', 'aggregate_id' => (string) $b2b->id]);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'StorefrontOrderPlaced', 'aggregate_id' => (string) $placed['id']]);

        // Visible to the supplier under orders-out filtered by source.
        $this->as($supplier)->getJson('/api/v1/orders?direction=out&source=storefront')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $b2b->code)
            ->assertJsonPath('data.0.source', 'storefront')
            ->assertJsonPath('data.0.store_order_id', $placed['id'])
            ->assertJsonPath('data.0.product.name', $tee->name);

        // And for the merchant it is routed (out), not pending routing (in).
        $this->as($merchant)->getJson('/api/v1/orders?direction=out&source=storefront')->assertOk()->assertJsonCount(1, 'data');
        $this->as($merchant)->getJson('/api/v1/orders?direction=in&source=storefront')->assertOk()->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------ 2. merchant store, no partners

    public function test_merchant_store_without_partners_stays_unrouted_in_orders_in(): void
    {
        Notification::fake();
        [$merchant, $store, $tee] = $this->makeStore('beta');

        $placed = $this->placeStorefrontOrder($store, $tee);

        $b2b = Order::query()->where('store_order_id', $placed['id'])->firstOrFail();
        $this->assertSame(0, OrderSuppliers::query()->where('order_id', $b2b->id)->count());
        $this->assertNull($b2b->assigned_supplier_id);
        Notification::assertNothingSent();

        $this->as($merchant)->getJson('/api/v1/orders?direction=in&source=storefront')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', $b2b->code);
        $this->as($merchant)->getJson('/api/v1/orders?direction=out&source=storefront')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------ 3. supplier-owned store self-routes

    public function test_supplier_owned_store_self_routes_and_shows_in_supplier_orders_out(): void
    {
        Notification::fake();
        [$supplier, $store, $tee] = $this->makeStore('gamma', 'Supplier');

        $placed = $this->placeStorefrontOrder($store, $tee);

        $b2b = Order::query()->where('store_order_id', $placed['id'])->firstOrFail();
        $this->assertDatabaseHas('order_suppliers', ['order_id' => $b2b->id, 'customer' => $supplier->id, 'supplier' => $supplier->id]);
        $this->assertSame(1, OrderSuppliers::query()->where('order_id', $b2b->id)->count());
        Notification::assertNothingSent(); // self-routed owners are not notified about their own order

        $this->as($supplier)->getJson('/api/v1/orders?direction=out&source=storefront')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', $b2b->code);
    }

    // ------------------------------------------------------------ 4. idempotency

    public function test_bridge_is_idempotent_when_job_runs_again(): void
    {
        [$merchant, $store, $tee] = $this->makeStore('delta');
        $placed = $this->placeStorefrontOrder($store, $tee);

        BridgeStorefrontOrderJob::dispatchSync($placed['id'], $store->id);
        BridgeStorefrontOrderJob::dispatchSync($placed['id'], $store->id);

        $this->assertSame(1, Order::query()->where('store_order_id', $placed['id'])->count());

        // Direct service call returns the same row too.
        $storeOrder = StoreOrder::query()->forStore($store)->with('items')->findOrFail($placed['id']);
        $again = app(StorefrontOrderBridge::class)->bridge($storeOrder, $store);
        $this->assertSame(Order::query()->where('store_order_id', $placed['id'])->value('id'), $again?->id);
        $this->assertSame(1, OutboxMessage::query()->where('event_type', 'StorefrontOrderBridged')->count());
    }

    // ------------------------------------------------------------ 5. cancel mirrors

    public function test_storefront_cancel_mirrors_to_b2b_order_but_other_statuses_do_not(): void
    {
        [$merchant, $store, $tee] = $this->makeStore('epsilon');
        $placed = $this->placeStorefrontOrder($store, $tee);
        $b2b = Order::query()->where('store_order_id', $placed['id'])->firstOrFail();

        // Owner confirms: fulfilment statuses are NOT mirrored (supplier drives the B2B side).
        $this->as($merchant)->patchJson("/api/v1/stores/{$store->id}/orders/{$placed['id']}/status", ['status' => 'confirmed'])->assertOk();
        $this->assertSame('pending', $b2b->fresh()->status);

        // Customer cancels from the storefront: the B2B row is cancelled.
        $this->postJson($placed['host']."/api/v1/storefront/orders/{$placed['number']}/cancel", [], $placed['auth'])->assertOk();
        $this->assertSame('cancelled', $b2b->fresh()->status);
    }

    // ------------------------------------------------------------ 6. source filter + validation

    public function test_source_filter_applies_and_rejects_unknown_values(): void
    {
        [$merchant, $store, $tee] = $this->makeStore('zeta');
        $placed = $this->placeStorefrontOrder($store, $tee);
        $direct = $this->makeDirectOrder($merchant, $tee);

        $this->as($merchant)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(2, 'data');
        $this->as($merchant)->getJson('/api/v1/orders?source=storefront')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.store_order_id', $placed['id']);
        $this->as($merchant)->getJson('/api/v1/orders?source=merchant_direct')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', $direct->code);
        $this->as($merchant)->getJson('/api/v1/orders?source=external_store')->assertOk()->assertJsonCount(0, 'data');
        $this->as($merchant)->getJson('/api/v1/orders?direction=in&source=merchant_direct')->assertOk()->assertJsonCount(1, 'data');

        $this->as($merchant)->getJson('/api/v1/orders?source=bogus')->assertStatus(422)->assertJsonValidationErrors(['source']);
        $this->as($merchant)->getJson('/api/v1/orders?direction=out&source=bogus')->assertStatus(422);
    }

    // ------------------------------------------------------------ 7. resource fields

    public function test_resources_expose_bridge_fields(): void
    {
        [$merchant, $store, $tee] = $this->makeStore('eta');
        $placed = $this->placeStorefrontOrder($store, $tee, 2);
        $b2b = Order::query()->where('store_order_id', $placed['id'])->firstOrFail();

        // B2B detail: source/store fields + storefront_items snapshot + product brief.
        $this->as($merchant)->getJson("/api/v1/orders/{$b2b->code}")
            ->assertOk()
            ->assertJsonPath('data.source', 'storefront')
            ->assertJsonPath('data.store_id', $store->id)
            ->assertJsonPath('data.store_order_id', $placed['id'])
            ->assertJsonPath('data.store_order_number', $placed['number'])
            ->assertJsonPath('data.customer_name', 'C')
            ->assertJsonCount(1, 'data.storefront_items')
            ->assertJsonPath('data.storefront_items.0.name', $tee->name)
            ->assertJsonPath('data.storefront_items.0.quantity', 2)
            ->assertJsonPath('data.storefront_items.0.image_thumb_url', 'https://cdn.example.com/tee.jpg')
            ->assertJsonPath('data.product.id', $tee->id)
            ->assertJsonPath('data.product.name', $tee->name);

        // A direct order reports its source and null bridge fields.
        $direct = $this->makeDirectOrder($merchant, $tee);
        $this->as($merchant)->getJson("/api/v1/orders/{$direct->code}")
            ->assertOk()
            ->assertJsonPath('data.source', 'merchant_direct')
            ->assertJsonPath('data.store_order_id', null)
            ->assertJsonPath('data.store_order_number', null);

        // Product brief is null-safe: falls back to the storefront snapshot when the product is gone.
        $b2b->setRelation('product', null);
        $payload = (new OrderApiResource($b2b))->toArray(Request::create('/api/v1/orders'));
        $this->assertSame($tee->name, $payload['product']['name']);
        $this->assertSame($tee->id, $payload['product']['id']);

        // Merchant store-order view links to the bridged B2B row.
        $this->as($merchant)->getJson("/api/v1/stores/{$store->id}/orders/{$placed['id']}")
            ->assertOk()
            ->assertJsonPath('data.b2b_order.code', $b2b->code)
            ->assertJsonPath('data.b2b_order.status', 'pending');
        $this->as($merchant)->getJson("/api/v1/stores/{$store->id}/orders")
            ->assertOk()
            ->assertJsonPath('data.0.b2b_order.id', $b2b->id);
    }
}
