<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5 — Storefront Commerce. Exercises cart, checkout, orders, and customer
 * accounts end-to-end through the host-resolved public API, with an explicit
 * focus on multi-tenant isolation (Store A must never reach Store B).
 */
class StorefrontCommerceTest extends TestCase
{
    use RefreshDatabase;

    private Store $alpha;

    private Store $beta;

    private Product $p1;

    private Product $p2;

    private Product $betaProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeStore('alpha');
        $this->beta = $this->makeStore('beta');

        $this->p1 = $this->makeProduct($this->alpha, 'tee', '20.00');
        $this->p2 = $this->makeProduct($this->alpha, 'cap', '15.50');
        $this->betaProduct = $this->makeProduct($this->beta, 'mug', '9.00');
    }

    private function makeStore(string $slug): Store
    {
        $user = User::factory()->create();
        $store = Store::create([
            'owner_user_id' => $user->id,
            'owner_type' => 'merchant',
            'name' => ucfirst($slug),
            'slug' => $slug,
            'currency' => 'USD',
            'status' => 'active',
        ]);
        StoreDomain::create([
            'store_id' => $store->id,
            'host' => "{$slug}.sellchase.com",
            'type' => 'subdomain',
            'is_primary' => true,
        ]);

        return $store;
    }

    private function makeProduct(Store $store, string $slug, string $price): Product
    {
        return Product::create([
            'user_id' => $store->owner_user_id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'price' => $price,
            'is_active' => true,
        ]);
    }

    private function host(Store $store): string
    {
        return "http://{$store->slug}.sellchase.com";
    }

    // ---------------------------------------------------------------- cart

    public function test_guest_can_build_a_cart_and_get_totals(): void
    {
        $res = $this->postJson($this->host($this->alpha).'/api/v1/storefront/cart/items', [
            'store_product_id' => $this->p1->id,
            'quantity' => 2,
        ])->assertCreated();

        $token = $res->json('data.token');
        $this->assertNotEmpty($token);

        $this->postJson($this->host($this->alpha).'/api/v1/storefront/cart/items', [
            'store_product_id' => $this->p2->id,
            'quantity' => 1,
        ], ['X-Cart-Token' => $token])->assertCreated();

        $this->getJson($this->host($this->alpha).'/api/v1/storefront/cart', ['X-Cart-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.item_count', 3)
            ->assertJsonPath('data.subtotal', '55.50');
    }

    public function test_cart_token_cannot_cross_stores(): void
    {
        $token = $this->postJson($this->host($this->alpha).'/api/v1/storefront/cart/items', [
            'store_product_id' => $this->p1->id, 'quantity' => 2,
        ])->assertCreated()->json('data.token');

        // Same token presented to Store B must NOT expose Store A's cart.
        $this->getJson($this->host($this->beta).'/api/v1/storefront/cart', ['X-Cart-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.item_count', 0);
    }

    public function test_cannot_add_a_foreign_stores_product(): void
    {
        $this->postJson($this->host($this->alpha).'/api/v1/storefront/cart/items', [
            'store_product_id' => $this->betaProduct->id, // belongs to Store B
            'quantity' => 1,
        ])->assertStatus(422);
    }

    public function test_update_and_remove_cart_items(): void
    {
        $add = $this->postJson($this->host($this->alpha).'/api/v1/storefront/cart/items', [
            'store_product_id' => $this->p1->id, 'quantity' => 1,
        ])->assertCreated();
        $token = $add->json('data.token');
        $itemId = $add->json('data.items.0.id');

        $this->patchJson($this->host($this->alpha)."/api/v1/storefront/cart/items/{$itemId}", [
            'quantity' => 5,
        ], ['X-Cart-Token' => $token])->assertOk()->assertJsonPath('data.item_count', 5);

        $this->deleteJson($this->host($this->alpha)."/api/v1/storefront/cart/items/{$itemId}", [], ['X-Cart-Token' => $token])
            ->assertOk()->assertJsonPath('data.item_count', 0);
    }

    // ------------------------------------------------------------ customers

    public function test_customer_register_login_and_token_is_store_scoped(): void
    {
        $token = $this->postJson($this->host($this->alpha).'/api/v1/storefront/auth/register', [
            'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'password123',
        ])->assertCreated()->json('token');

        // Token authenticates on its own store...
        $this->getJson($this->host($this->alpha).'/api/v1/storefront/account', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()->assertJsonPath('data.email', 'ada@example.com');

        // ...but NEVER on another store.
        $this->getJson($this->host($this->beta).'/api/v1/storefront/account', [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus(401);
    }

    public function test_same_email_can_exist_in_two_stores_independently(): void
    {
        $this->postJson($this->host($this->alpha).'/api/v1/storefront/auth/register', [
            'name' => 'A', 'email' => 'dup@example.com', 'password' => 'password123',
        ])->assertCreated();

        // Same email on a different store is allowed (unique per store).
        $this->postJson($this->host($this->beta).'/api/v1/storefront/auth/register', [
            'name' => 'B', 'email' => 'dup@example.com', 'password' => 'password123',
        ])->assertCreated();

        // Duplicate on the SAME store is rejected.
        $this->postJson($this->host($this->alpha).'/api/v1/storefront/auth/register', [
            'name' => 'A2', 'email' => 'dup@example.com', 'password' => 'password123',
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------- checkout

    public function test_checkout_creates_an_order_visible_only_to_its_customer(): void
    {
        $token = $this->postJson($this->host($this->alpha).'/api/v1/storefront/auth/register', [
            'name' => 'Grace', 'email' => 'grace@example.com', 'password' => 'password123',
        ])->assertCreated()->json('token');
        $auth = ['Authorization' => "Bearer {$token}"];

        $this->postJson($this->host($this->alpha).'/api/v1/storefront/cart/items', [
            'store_product_id' => $this->p1->id, 'quantity' => 2,
        ], $auth)->assertCreated();
        $this->postJson($this->host($this->alpha).'/api/v1/storefront/cart/items', [
            'store_product_id' => $this->p2->id, 'quantity' => 1,
        ], $auth)->assertCreated();

        $order = $this->postJson($this->host($this->alpha).'/api/v1/storefront/checkout', [
            'customer_name' => 'Grace Hopper',
            'customer_email' => 'grace@example.com',
            'shipping_address' => ['name' => 'Grace', 'line1' => '1 Navy Way', 'city' => 'Arlington'],
        ], $auth)->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subtotal', '55.50')
            ->assertJsonPath('data.grand_total', '55.50');

        $number = $order->json('data.order_number');
        $this->assertNotEmpty($number);
        $order->assertJsonCount(2, 'data.items');

        // Customer sees their order.
        $this->getJson($this->host($this->alpha).'/api/v1/storefront/orders', $auth)
            ->assertOk()->assertJsonPath('meta.total', 1);

        // A customer on Store B cannot see Store A's order number.
        $betaToken = $this->postJson($this->host($this->beta).'/api/v1/storefront/auth/register', [
            'name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'password123',
        ])->assertCreated()->json('token');

        $this->getJson($this->host($this->beta)."/api/v1/storefront/orders/{$number}", [
            'Authorization' => "Bearer {$betaToken}",
        ])->assertStatus(404);
    }

    public function test_checkout_fails_on_empty_cart(): void
    {
        $token = $this->postJson($this->host($this->alpha).'/api/v1/storefront/auth/register', [
            'name' => 'Zed', 'email' => 'zed@example.com', 'password' => 'password123',
        ])->assertCreated()->json('token');

        $this->postJson($this->host($this->alpha).'/api/v1/storefront/checkout', [
            'customer_name' => 'Zed', 'customer_email' => 'zed@example.com',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(422);
    }

    public function test_customer_can_cancel_a_pending_order(): void
    {
        $token = $this->postJson($this->host($this->alpha).'/api/v1/storefront/auth/register', [
            'name' => 'Nia', 'email' => 'nia@example.com', 'password' => 'password123',
        ])->assertCreated()->json('token');
        $auth = ['Authorization' => "Bearer {$token}"];

        $this->postJson($this->host($this->alpha).'/api/v1/storefront/cart/items', [
            'store_product_id' => $this->p1->id, 'quantity' => 1,
        ], $auth)->assertCreated();

        $number = $this->postJson($this->host($this->alpha).'/api/v1/storefront/checkout', [
            'customer_name' => 'Nia', 'customer_email' => 'nia@example.com',
        ], $auth)->assertCreated()->json('data.order_number');

        $this->postJson($this->host($this->alpha)."/api/v1/storefront/orders/{$number}/cancel", [], $auth)
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_customer_address_book_is_scoped_to_the_customer(): void
    {
        $token = $this->postJson($this->host($this->alpha).'/api/v1/storefront/auth/register', [
            'name' => 'Ivy', 'email' => 'ivy@example.com', 'password' => 'password123',
        ])->assertCreated()->json('token');
        $auth = ['Authorization' => "Bearer {$token}"];

        $this->postJson($this->host($this->alpha).'/api/v1/storefront/account/addresses', [
            'name' => 'Ivy', 'line1' => '5 Elm St', 'city' => 'Boston', 'country' => 'US', 'is_default' => true,
        ], $auth)->assertCreated()->assertJsonPath('data.is_default', true);

        $this->getJson($this->host($this->alpha).'/api/v1/storefront/account/addresses', $auth)
            ->assertOk()->assertJsonCount(1, 'data');

        // A different customer (Store B) sees none of Ivy's addresses.
        $betaToken = $this->postJson($this->host($this->beta).'/api/v1/storefront/auth/register', [
            'name' => 'Bo', 'email' => 'bo@example.com', 'password' => 'password123',
        ])->assertCreated()->json('token');
        $this->getJson($this->host($this->beta).'/api/v1/storefront/account/addresses', [
            'Authorization' => "Bearer {$betaToken}",
        ])->assertOk()->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------ auth & host guards

    public function test_protected_routes_require_a_customer_token(): void
    {
        // No Authorization header -> 401 (store.customer middleware).
        $this->getJson($this->host($this->alpha).'/api/v1/storefront/account')->assertStatus(401);
        $this->getJson($this->host($this->alpha).'/api/v1/storefront/orders')->assertStatus(401);
        $this->getJson($this->host($this->alpha).'/api/v1/storefront/account/addresses')->assertStatus(401);
    }

    public function test_commerce_endpoints_404_on_an_unknown_host(): void
    {
        // No store resolves for this host -> currentStore() aborts 404 (fail-closed).
        $this->getJson('http://ghost.sellchase.com/api/v1/storefront/cart')->assertNotFound();
        $this->postJson('http://ghost.sellchase.com/api/v1/storefront/checkout', [
            'customer_name' => 'X', 'customer_email' => 'x@example.com',
        ])->assertNotFound();
    }

    // --------------------------------------------------------- no regression

    public function test_existing_storefront_context_still_works(): void
    {
        $this->getJson($this->host($this->alpha).'/api/v1/storefront/context')
            ->assertOk()
            ->assertJsonPath('store.slug', 'alpha');
    }
}
