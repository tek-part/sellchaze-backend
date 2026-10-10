<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CheckoutFields;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\OrderLimits;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StoreOrderLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private array $auth;

    private string $base = 'http://limits.sellchase.com/api/v1/storefront';

    private string $settings = '/api/v1/my-store/order-limits';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Limits', 'slug' => 'limits', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'limits.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 20]);
    }

    private function config(array $changes = []): array
    {
        return array_replace(['max_product_quantity' => 0, 'max_orders_per_phone_24h' => 0, 'phone_country' => 'EG'], $changes);
    }

    private function configure(array $changes): void
    {
        $this->store->update(['order_limits' => $this->config($changes)]);
    }

    private function payload(?string $phone = '01001234567', array $changes = []): array
    {
        return array_replace(['customer_name' => 'Local review', 'customer_email' => 'limits@example.test', 'customer_phone' => $phone,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]]], $changes);
    }

    public function test_settings_validate_save_reload_tenant_and_employee_permissions(): void
    {
        $this->getJson($this->settings, $this->auth)->assertOk()->assertJsonPath('data.max_product_quantity', 0)->assertJsonPath('data.phone_country', 'EG');
        $config = $this->config(['max_product_quantity' => 3, 'max_orders_per_phone_24h' => 2, 'phone_country' => 'SA']);
        $this->putJson($this->settings, $config, $this->auth)->assertOk()->assertJsonPath('data', $config);
        $this->getJson($this->settings, $this->auth)->assertOk()->assertJsonPath('data', $config);
        foreach ([['max_product_quantity' => -1], ['max_orders_per_phone_24h' => 1.5], ['phone_country' => 'XX'], ['max_product_quantity' => 100001]] as $change) {
            $this->putJson($this->settings, array_replace($config, $change), $this->auth)->assertUnprocessable();
        }
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->getJson('/api/v1/stores/'.$this->store->id.'/order-limits', ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($other)])->assertNotFound();
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee)];
        $this->getJson($this->settings, $headers)->assertForbidden();
        $this->putJson($this->settings, $config, $headers)->assertForbidden();
        $employee->givePermissionTo('store.settings.manage');
        $this->travel(31)->seconds();
        $this->putJson($this->settings, $config, $headers)->assertOk();
    }

    public function test_cart_quote_and_checkout_aggregate_variants_with_atomic_rejection(): void
    {
        $this->configure(['max_product_quantity' => 3]);
        $variants = [];
        foreach (['A', 'B'] as $name) {
            $variants[] = ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $this->product->id, 'name' => $name, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 10]);
        }
        $first = $this->postJson($this->base.'/cart/items', ['store_product_id' => $this->product->id, 'variant_id' => $variants[0]->id, 'quantity' => 2])->assertCreated();
        $headers = ['X-Cart-Token' => $first->json('data.token')];
        $this->postJson($this->base.'/cart/items', ['store_product_id' => $this->product->id, 'variant_id' => $variants[1]->id, 'quantity' => 2], $headers)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $added = $this->postJson($this->base.'/cart/items', ['store_product_id' => $this->product->id, 'variant_id' => $variants[1]->id, 'quantity' => 1], $headers)->assertCreated();
        $this->patchJson($this->base.'/cart/items/'.$added->json('data.items.1.id'), ['quantity' => 2], $headers)->assertUnprocessable();
        $items = [['product_id' => $this->product->id, 'variant_id' => $variants[0]->id, 'quantity' => 2], ['product_id' => $this->product->id, 'variant_id' => $variants[1]->id, 'quantity' => 2]];
        $this->postJson($this->base.'/checkout/quote', ['items' => $items])->assertUnprocessable();
        $this->postJson($this->base.'/checkout', $this->payload(changes: ['items' => $items]), $headers)->assertUnprocessable();
        $this->getJson($this->base.'/cart', $headers)->assertOk()->assertJsonPath('data.items.0.quantity', 2)->assertJsonPath('data.items.1.quantity', 1);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('store_inventory_movements', 0);
        $this->assertDatabaseCount('store_payment_transactions', 0);
        $this->configure(['max_product_quantity' => 0]);
        $this->postJson($this->base.'/checkout', $this->payload(changes: ['items' => $items]), $headers)->assertCreated()->assertJsonCount(2, 'data.items');
    }

    public function test_authoritative_service_rejects_stale_cart_and_combines_customization_lines(): void
    {
        $this->product->update(['personalization_fields' => [['key' => 'message', 'type' => 'text', 'label' => 'Message', 'labels' => ['ar' => 'رسالة', 'en' => 'Message'], 'required' => false, 'max_length' => 50, 'position' => 0]]]);
        $cart = app(CartService::class)->create($this->store, null);
        app(CartService::class)->addItem($cart, $this->product->id, 2, null, ['message' => 'Alice']);
        app(CartService::class)->addItem($cart, $this->product->id, 2, null, ['message' => 'Bob']);
        $this->configure(['max_product_quantity' => 3]);
        try {
            app(CheckoutService::class)->place($this->store, $cart, null, ['name' => 'Review', 'email' => 'limits@example.test'], null, 'cod');
            $this->fail('Stale cart exceeded the aggregate product limit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }
        $this->assertSame('active', $cart->fresh()->status);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertSame(0, $this->product->fresh()->reserved_quantity);
    }

    public function test_phone_formats_countries_and_extensions_share_canonical_identity(): void
    {
        $limits = app(OrderLimits::class);
        foreach (['01001234567', '+20 100 123 4567', '00201001234567', '٠١٠٠١٢٣٤٥٦٧', '+201001234567 ext. 12'] as $raw) {
            $this->assertSame('+201001234567', $limits->normalizePhone($raw, 'EG'));
        }
        $this->assertSame('+442070313000', $limits->normalizePhone('020 7031 3000', 'GB'));
        $this->assertSame('+16502530000', $limits->normalizePhone('(650) 253-0000', 'US'));
        $this->assertSame('+442070313000', $limits->normalizePhone('+442070313000', 'EG'));
        foreach ([null, '', '123', 'invalid'] as $raw) {
            $this->assertNull($limits->normalizePhone($raw, 'EG'));
        }
    }

    public function test_phone_quota_exact_replay_and_rejected_retry_do_not_create_or_reserve_again(): void
    {
        $this->configure(['max_orders_per_phone_24h' => 1]);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $payload = $this->payload();
        $first = $this->postJson($this->base.'/checkout', $payload, $headers)->assertCreated();
        $this->postJson($this->base.'/checkout', $payload, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
        $retry = ['Idempotency-Key' => (string) Str::uuid()];
        $this->postJson($this->base.'/checkout', $this->payload('+20 100 123 4567'), $retry)->assertUnprocessable()->assertJsonValidationErrors('customer_phone');
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseCount('store_payment_transactions', 1);
        $this->assertSame(1, $this->product->fresh()->reserved_quantity);
        $this->assertSame('+201001234567', StoreOrder::withoutGlobalScopes()->first()->customer_phone_normalized);
        $this->assertArrayNotHasKey('customer_phone_normalized', StoreOrder::withoutGlobalScopes()->first()->toArray());
        $this->postJson($this->base.'/checkout', $this->payload('01001234568'), $retry)->assertCreated();
        $this->assertDatabaseCount('store_orders', 2);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_rolling_window_legacy_cancelled_orders_and_store_isolation(): void
    {
        $this->freezeTime();
        $this->configure(['max_orders_per_phone_24h' => 1]);
        $order = StoreOrder::forStore($this->store)->create(['store_id' => $this->store->id, 'order_number' => 'LEGACY', 'status' => 'cancelled', 'customer_name' => 'Legacy review', 'customer_phone' => '٠١٠٠١٢٣٤٥٦٧', 'currency' => 'EGP', 'grand_total' => 100, 'placed_at' => now()->subHours(24)->addSecond()]);
        $this->postJson($this->base.'/checkout', $this->payload('+201001234567'))->assertUnprocessable()->assertJsonValidationErrors('customer_phone');
        $order->update(['placed_at' => now()->subHours(24)]);
        $foreign = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-limits']);
        StoreOrder::withoutGlobalScopes()->create(['store_id' => $foreign->id, 'order_number' => 'FOREIGN', 'status' => 'pending', 'customer_name' => 'Foreign review', 'customer_phone_normalized' => '+201001234567', 'currency' => 'EGP', 'grand_total' => 100, 'placed_at' => now()]);
        $this->postJson($this->base.'/checkout', $this->payload())->assertCreated();
        $this->assertDatabaseCount('store_orders', 3);
    }

    public function test_enabled_phone_limit_overrides_hidden_phone_for_digital_orders(): void
    {
        $fields = app(CheckoutFields::class)->defaults();
        foreach ($fields as &$field) {
            if ($field['key'] === 'phone') {
                $field['enabled'] = $field['required'] = false;
            }
        }
        $this->store->update(['checkout_fields' => $fields]);
        $this->product->update(['digital_type' => 'link', 'digital_url' => 'https://example.test/guide', 'track_inventory' => false]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true, 'credentials' => []]);
        $this->configure(['max_orders_per_phone_24h' => 1]);
        $response = $this->getJson($this->base.'/checkout/fields?product_ids[]='.$this->product->id.'&payment_method=bank_transfer')->assertOk();
        $phone = collect($response->json('data'))->firstWhere('key', 'phone');
        $this->assertTrue($phone['enabled']);
        $this->assertTrue($phone['required']);
        $this->assertTrue($phone['order_limit_required']);
        foreach ([null, '123'] as $raw) {
            $this->postJson($this->base.'/checkout', $this->payload($raw, ['payment_method' => 'bank_transfer']))->assertUnprocessable()->assertJsonValidationErrors('customer_phone');
        }
        $this->assertDatabaseCount('store_orders', 0);
        $this->postJson($this->base.'/checkout', $this->payload(changes: ['payment_method' => 'bank_transfer']))->assertCreated();
        $this->configure(['max_orders_per_phone_24h' => 0]);
        $this->postJson($this->base.'/checkout', $this->payload(null, ['payment_method' => 'bank_transfer']))->assertCreated();
    }

    public function test_public_catalog_exposes_current_caps_and_invalidates_cached_homepage(): void
    {
        $this->getJson($this->base.'/')->assertOk();
        $this->putJson($this->settings, $this->config(['max_product_quantity' => 2]), $this->auth)->assertOk();
        $this->getJson($this->base.'/')->assertOk()->assertJsonPath('homepage.featured_products.0.order_quantity_limit', 2);
        $this->getJson($this->base.'/products/bag')->assertOk()->assertJsonPath('data.order_quantity_limit', 2);
        $this->postJson($this->base.'/cart/catalog', ['product_ids' => [$this->product->id]])->assertOk()->assertJsonPath('data.0.order_quantity_limit', 2);
        $this->putJson($this->settings, $this->config(), $this->auth)->assertOk();
        $this->getJson($this->base.'/')->assertOk()->assertJsonPath('homepage.featured_products.0.order_quantity_limit', null);
    }

    public function test_failed_purchase_and_legacy_orders_without_placement_date_obey_phone_quota(): void
    {
        $this->configure(['max_orders_per_phone_24h' => 1]);
        $this->postJson($this->base.'/checkout', $this->payload(changes: ['items' => [['product_id' => $this->product->id, 'quantity' => 21]]]))->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
        $legacy = StoreOrder::forStore($this->store)->create(['store_id' => $this->store->id, 'order_number' => 'LEGACY-NO-DATE', 'status' => 'pending', 'customer_name' => 'Legacy review', 'customer_phone' => '01001234567', 'currency' => 'EGP', 'grand_total' => 100, 'placed_at' => null]);
        $this->postJson($this->base.'/checkout', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('customer_phone');
        $legacy->created_at = now()->subHours(25);
        $legacy->save();
        $this->postJson($this->base.'/checkout', $this->payload())->assertCreated();
    }
}
