<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreApiKey;
use App\Models\StoreOrder;
use App\Models\StoreOrderWebhook;
use App\Models\StoreOrderWebhookDelivery;
use App\Models\User;
use App\Services\Commerce\StoreOrderWebhooks;
use App\Services\JwtTokenService;
use App\Services\PlatformWallet;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\StorePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class StorePublicApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private string $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class, StorePermissionsSeeder::class]);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        RateLimiter::clear('store-external-ip:127.0.0.1');
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'API fixture', 'slug' => 'api-fixture', 'status' => 'active', 'currency' => 'EGP']);
        $this->jwt = JwtTokenService::fromConfig()->issueAccessToken($this->owner);
        $this->manager();
        app(CurrentStore::class)->forget();
    }

    private function manager(): void
    {
        $this->withHeaders(['Api-Key' => '', 'Authorization' => 'Bearer '.$this->jwt]);
    }

    private function machine(string $token): void
    {
        $this->withHeaders(['Api-Key' => $token, 'Authorization' => '']);
        app(CurrentStore::class)->forget();
    }

    private function body(array $changes = []): array
    {
        return $changes + ['description' => 'Local API fixture', 'permissions' => ['products:read'], 'enabled' => true, 'request_key' => (string) Str::uuid()];
    }

    private function key(array $permissions = [], array $changes = []): array
    {
        $token = 'sc_'.Str::random(64);
        $row = StoreApiKey::create($changes + ['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'request_key' => Str::uuid(), 'token_hash' => hash('sha256', $token), 'token_prefix' => substr($token, 0, 11), 'permissions' => $permissions, 'enabled' => true]);

        return [$row, $token];
    }

    private function order(): StoreOrder
    {
        return StoreOrder::create(['store_id' => $this->store->id, 'order_number' => 'API-'.Str::uuid(), 'status' => 'pending', 'currency' => 'EGP', 'customer_name' => 'Local buyer', 'customer_email' => 'local@example.test', 'customer_phone' => '01001234567', 'shipping_address' => ['line1' => 'Local address', 'city' => 'Cairo'], 'subtotal' => 100, 'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => 100, 'payment_method' => 'cod', 'payment_status' => 'pending', 'placed_at' => now()]);
    }

    public function test_creation_replay_hash_receipt_expiry_and_private_gets(): void
    {
        $body = $this->body(['permissions' => ['products:read', 'orders:read']]);
        $first = $this->postJson('/api/v1/my-store/public-api', $body)->assertCreated();
        $secret = $first->json('secret');
        $id = $first->json('data.id');
        $this->assertSame(67, strlen($secret));
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $this->assertSame(hash('sha256', $secret), DB::table('store_api_keys')->value('token_hash'));
        $this->assertStringNotContainsString($secret, DB::table('store_api_keys')->value('creation_receipt'));
        $this->postJson('/api/v1/my-store/public-api', $body)->assertCreated()->assertJsonPath('secret', $secret)->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/my-store/public-api', array_replace($body, ['permissions' => []]))->assertConflict();
        foreach (['/api/v1/my-store/public-api', '/api/v1/my-store/public-api/'.$id] as $url) {
            $response = $this->getJson($url)->assertOk();
            foreach ([$secret, 'token_hash', 'creation_receipt', 'request_key'] as $hidden) {
                $this->assertStringNotContainsString($hidden, $response->getContent());
            }
        }
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/my-store/public-api', $body)->assertCreated()->assertJsonPath('secret', null);
        $this->artisan('store-api-keys:expire-receipts')->assertSuccessful();
        $this->assertDatabaseHas('store_api_keys', ['id' => $id, 'creation_receipt' => null, 'receipt_expires_at' => null, 'token_hash' => hash('sha256', $secret)]);
        $this->machine($secret);
        $this->getJson('/api/v1/external-apps/products')->assertOk();
        $this->assertDatabaseCount('store_api_keys', 1);
    }

    public function test_management_owner_admin_employee_and_numeric_store_bindings(): void
    {
        $first = $this->postJson('/api/v1/my-store/public-api', $this->body())->assertCreated()->json('data');
        $url = '/api/v1/stores/'.$this->store->id.'/public-api/'.$first['id'];
        $this->getJson($url)->assertOk();
        $this->putJson($url, $this->body(['permissions' => [], 'version' => 1]))->assertOk()->assertJsonPath('data.version', 2);
        $this->deleteJson($url, ['version' => 1])->assertConflict();
        $admin = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $admin->assignRole('Admin');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($admin));
        $this->getJson($url)->assertOk();
        $employee = User::factory()->create(['parent_user_id' => $this->owner->id, 'is_active' => true, 'pending_approval' => false]);
        $employee->givePermissionTo(['store.view', 'store.settings.manage']);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($employee));
        $this->getJson($url)->assertForbidden();
        $this->manager();
        $this->deleteJson($url, ['version' => 2])->assertOk();
        $this->getJson($url)->assertNotFound();
        $this->assertDatabaseHas('store_api_keys', ['id' => $first['id'], 'enabled' => false, 'creation_receipt' => null]);
    }

    public function test_validation_cas_revoke_and_replayed_creation_after_edit(): void
    {
        foreach ([['permissions' => ['orders:create']], ['permissions' => ['products:read', 'products:read']], ['request_key' => 'wrong'], ['description' => str_repeat('x', 201)]] as $change) {
            $this->postJson('/api/v1/my-store/public-api', $this->body($change))->assertUnprocessable();
        }
        $body = $this->body();
        $first = $this->postJson('/api/v1/my-store/public-api', $body)->assertCreated();
        $url = '/api/v1/my-store/public-api/'.$first->json('data.id');
        $this->putJson($url, $body + ['version' => 1])->assertOk();
        $this->putJson($url, $body + ['version' => 1])->assertConflict();
        $this->postJson('/api/v1/my-store/public-api', $body)->assertConflict();
        $this->deleteJson($url, ['version' => 2])->assertOk();
        $this->machine($first->json('secret'));
        $this->getJson('/api/v1/external-apps/products')->assertUnauthorized();
    }

    public function test_each_machine_endpoint_enforces_its_exact_permission(): void
    {
        [$key, $token] = $this->key();
        $this->machine($token);
        $matrix = [['GET', 'products'], ['POST', 'products'], ['GET', 'products/1'], ['PATCH', 'products/1'], ['DELETE', 'products/1'], ['GET', 'categories'], ['POST', 'categories'], ['GET', 'categories/1'], ['PATCH', 'categories/1'], ['DELETE', 'categories/1'], ['GET', 'orders'], ['GET', 'orders/1'], ['GET', 'orders/short-id/LOCAL'], ['PATCH', 'orders/1/status'], ['POST', 'orders/1/notes'], ['DELETE', 'orders/1'], ['GET', 'shipping-areas'], ['PUT', 'shipping-areas'], ['DELETE', 'webhooks/delete-by-url']];
        foreach ($matrix as [$method, $path]) {
            $this->json($method, '/api/v1/external-apps/'.$path, [])->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_machine_credentials_reject_jwt_profile_global_and_conflicting_headers(): void
    {
        foreach (['', $this->jwt, 'legacy-profile-key'] as $token) {
            $this->machine($token);
            $this->getJson('/api/v1/external-apps/products')->assertUnauthorized();
        }
        [$key, $token] = $this->key(['products:read']);
        $this->withHeaders(['Api-Key' => $token, 'Authorization' => 'Bearer wrong']);
        $this->getJson('/api/v1/external-apps/products')->assertUnauthorized();
        $this->withHeaders(['Api-Key' => '', 'Authorization' => 'Bearer '.$token]);
        $response = $this->getJson('/api/v1/external-apps/products')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNotNull($key->fresh()->last_used_at);
        $this->assertNull(app(CurrentStore::class)->get());
    }

    public function test_disabled_account_store_transfer_and_revocation_stop_new_requests(): void
    {
        [$key, $token] = $this->key(['products:read']);
        $this->machine($token);
        $key->update(['enabled' => false]);
        $this->getJson('/api/v1/external-apps/products')->assertUnauthorized();
        $key->update(['enabled' => true]);
        $this->owner->update(['is_active' => false]);
        $this->getJson('/api/v1/external-apps/products')->assertUnauthorized();
        $this->owner->update(['is_active' => true]);
        $this->owner->update(['pending_approval' => true]);
        $this->getJson('/api/v1/external-apps/products')->assertUnauthorized();
        $this->owner->update(['pending_approval' => false]);
        $this->store->update(['status' => 'suspended']);
        $this->getJson('/api/v1/external-apps/products')->assertUnauthorized();
        $this->store->update(['status' => 'active']);
        $this->store->update(['owner_user_id' => User::factory()->create()->id]);
        $this->getJson('/api/v1/external-apps/products')->assertUnauthorized();
    }

    public function test_forty_request_limit_is_per_key_and_resets(): void
    {
        [$key, $token] = $this->key(['products:read']);
        [$other, $otherToken] = $this->key(['products:read']);
        $this->machine($token);
        for ($i = 0; $i < 40; $i++) {
            $this->getJson('/api/v1/external-apps/products')->assertOk();
        }
        $response = $this->getJson('/api/v1/external-apps/products')->assertTooManyRequests();
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->machine($otherToken);
        $this->getJson('/api/v1/external-apps/products')->assertOk();
        $this->travel(61)->seconds();
        $this->machine($token);
        $this->getJson('/api/v1/external-apps/products')->assertOk();
    }

    public function test_catalog_crud_reuses_validation_and_never_crosses_stores(): void
    {
        [$key, $token] = $this->key(StoreApiKey::PERMISSIONS);
        $this->machine($token);
        $category = $this->postJson('/api/v1/external-apps/categories', ['name' => 'API category'])->assertCreated()->json('data.id');
        $product = $this->postJson('/api/v1/external-apps/products', ['name' => 'API product', 'price' => 20, 'category_id' => $category, 'is_active' => true])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/external-apps/products/'.$product)->assertOk()->assertJsonPath('data.name', 'API product');
        $this->patchJson('/api/v1/external-apps/products/'.$product, ['price' => 25])->assertOk();
        $this->patchJson('/api/v1/external-apps/categories/'.$category, ['name' => 'Edited API category'])->assertOk();
        $this->getJson('/api/v1/external-apps/categories/'.$category)->assertOk()->assertJsonPath('data.name', 'Edited API category');
        $foreignOwner = User::factory()->create();
        $foreign = Store::create(['owner_user_id' => $foreignOwner->id, 'owner_type' => 'merchant', 'name' => 'Foreign', 'slug' => 'foreign-api', 'status' => 'active']);
        $foreignCategory = Category::create(['store_id' => $foreign->id, 'name' => 'Hidden category', 'slug' => 'hidden-category']);
        $foreignProduct = Product::create(['store_id' => $foreign->id, 'user_id' => $foreignOwner->id, 'name' => 'Hidden product', 'slug' => 'hidden-product']);
        foreach (['products/'.$foreignProduct->id, 'categories/'.$foreignCategory->id] as $path) {
            $this->getJson('/api/v1/external-apps/'.$path)->assertNotFound();
            $this->deleteJson('/api/v1/external-apps/'.$path)->assertNotFound();
        }
        $this->patchJson('/api/v1/external-apps/products/'.$product, ['category_id' => $foreignCategory->id])->assertUnprocessable();
        $this->getJson('/api/v1/external-apps/products')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson('/api/v1/external-apps/products/'.$product)->assertOk();
        $this->getJson('/api/v1/external-apps/products/'.$product)->assertNotFound();
        $this->deleteJson('/api/v1/external-apps/categories/'.$category)->assertOk();
        $this->assertDatabaseHas('products', ['id' => $foreignProduct->id]);
        Http::assertNothingSent();
    }

    public function test_order_reads_status_notes_archive_preserve_items_and_ledger(): void
    {
        $order = $this->order();
        $order->items()->create(['store_id' => $this->store->id, 'name' => 'Snapshot', 'unit_price' => 100, 'quantity' => 1, 'line_total' => 100]);
        [$key, $token] = $this->key(StoreApiKey::PERMISSIONS);
        $this->machine($token);
        $this->getJson('/api/v1/external-apps/orders/'.$order->id)->assertOk()->assertJsonPath('data.customer.name', 'Local buyer');
        $this->getJson('/api/v1/external-apps/orders/short-id/'.$order->order_number)->assertOk()->assertJsonPath('data.id', $order->id);
        $this->patchJson('/api/v1/external-apps/orders/'.$order->id.'/status', ['status' => 'delivered'])->assertUnprocessable();
        $this->patchJson('/api/v1/external-apps/orders/'.$order->id.'/status', ['status' => 'confirmed', 'note' => 'Local confirmation'])->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->postJson('/api/v1/external-apps/orders/'.$order->id.'/notes', ['note' => 'Local note'])->assertOk();
        $this->deleteJson('/api/v1/external-apps/orders/'.$order->id)->assertOk();
        $this->getJson('/api/v1/external-apps/orders/'.$order->id)->assertNotFound();
        $this->getJson('/api/v1/external-apps/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->assertNotNull(StoreOrder::withoutGlobalScopes()->findOrFail($order->id)->deleted_at);
        $this->assertDatabaseHas('store_order_items', ['store_order_id' => $order->id]);
        $this->assertDatabaseCount('platform_wallet_entries', 0);
        Http::assertNothingSent();
    }

    public function test_wallet_debt_hides_charged_orders_from_all_machine_access(): void
    {
        $legacy = $this->order();
        $wallet = app(PlatformWallet::class);
        config(['platform_wallet.fees_enabled' => true]);
        $wallet->activateOrderFees();
        for ($i = 0; $i < 5; $i++) {
            $charged = $this->order();
            $wallet->orderFee($charged);
        }
        [$key, $token] = $this->key(StoreApiKey::PERMISSIONS);
        $this->machine($token);
        $response = $this->getJson('/api/v1/external-apps/orders')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($legacy->id, $response->json('data.0.id'));
        $this->getJson('/api/v1/external-apps/orders/'.$charged->id)->assertStatus(402);
        $this->getJson('/api/v1/external-apps/orders/short-id/'.$charged->order_number)->assertStatus(402);
        $this->patchJson('/api/v1/external-apps/orders/'.$charged->id.'/status', ['status' => 'confirmed'])->assertStatus(402);
        $this->deleteJson('/api/v1/external-apps/orders/'.$charged->id)->assertStatus(402);
        $this->assertDatabaseCount('platform_wallet_entries', 5);
    }

    public function test_shipping_save_and_webhook_delete_are_store_scoped(): void
    {
        $hook = StoreOrderWebhook::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'request_key' => Str::uuid(), 'url' => 'https://hooks.example.test/local', 'event_type' => 'orders', 'enabled' => true, 'secret' => Str::random(64)]);
        $order = $this->order();
        app(StoreOrderWebhooks::class)->created($order);
        [$key, $token] = $this->key(StoreApiKey::PERMISSIONS);
        $this->machine($token);
        $this->getJson('/api/v1/external-apps/shipping-areas')->assertOk();
        $data = ['shipping_enabled' => false, 'shipping_flat_rate' => 0, 'shipping_free_over' => null, 'tax_enabled' => false, 'tax_rate' => 0, 'tax_prices_include' => false, 'regions_enabled' => false, 'auto_select_region' => false, 'regions' => [], 'options' => []];
        $this->putJson('/api/v1/external-apps/shipping-areas', $data)->assertOk();
        $this->deleteJson('/api/v1/external-apps/webhooks/delete-by-url', ['url' => $hook->url])->assertOk()->assertJsonPath('deleted', 1);
        $this->assertTrue($hook->fresh()->trashed());
        $this->assertDatabaseHas('store_order_webhook_deliveries', ['webhook_id' => $hook->id, 'status' => 'skipped', 'payload' => null]);
        Http::assertNothingSent();
    }

    public function test_populated_migration_reversal_is_refused_before_dropping_either_surface(): void
    {
        $this->key(['products:read']);
        $migration = require database_path('migrations/2026_10_13_070000_create_store_api_keys.php');
        try {
            $migration->down();
            $this->fail('Populated key migration was reversed.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Refusing', $error->getMessage());
        }
        $this->assertDatabaseCount('store_api_keys', 1);
        $this->assertTrue(Schema::hasColumn('store_orders', 'deleted_at'));
    }

    public function test_archived_order_alone_prevents_destructive_schema_rollback(): void
    {
        $order = $this->order();
        $order->delete();
        $migration = require database_path('migrations/2026_10_13_070000_create_store_api_keys.php');
        try {
            $migration->down();
            $this->fail('Archived order protection was discarded.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Refusing', $error->getMessage());
        }
        $this->assertDatabaseCount('store_api_keys', 0);
        $this->assertNotNull($order->fresh()->deleted_at);
    }

    public function test_foreign_orders_and_webhook_destinations_cannot_be_read_changed_or_deleted(): void
    {
        $other = User::factory()->create();
        $foreign = Store::create(['owner_user_id' => $other->id, 'owner_type' => 'merchant', 'name' => 'Foreign', 'slug' => 'foreign-orders-api', 'status' => 'active']);
        $order = $this->order();
        $order->update(['store_id' => $foreign->id]);
        $hook = StoreOrderWebhook::create(['store_id' => $foreign->id, 'user_id' => $other->id, 'request_key' => Str::uuid(), 'url' => 'https://hooks.example.test/shared', 'event_type' => 'orders', 'enabled' => true, 'secret' => Str::random(64)]);
        [$key, $token] = $this->key(StoreApiKey::PERMISSIONS);
        $this->machine($token);
        $this->getJson('/api/v1/external-apps/orders/'.$order->id)->assertNotFound();
        $this->getJson('/api/v1/external-apps/orders/short-id/'.$order->order_number)->assertNotFound();
        $this->patchJson('/api/v1/external-apps/orders/'.$order->id.'/status', ['status' => 'confirmed'])->assertNotFound();
        $this->deleteJson('/api/v1/external-apps/orders/'.$order->id)->assertNotFound();
        $this->deleteJson('/api/v1/external-apps/webhooks/delete-by-url', ['url' => $hook->url])->assertOk()->assertJsonPath('deleted', 0);
        $this->assertFalse($hook->fresh()->trashed());
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->deleted_at);
    }

    public function test_key_quota_does_not_count_former_owner_credentials(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->key();
        }
        $this->postJson('/api/v1/my-store/public-api', $this->body())->assertUnprocessable();
        $newOwner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $newOwner->assignRole('Merchant');
        $this->store->update(['owner_user_id' => $newOwner->id]);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($newOwner));
        $this->postJson('/api/v1/my-store/public-api', $this->body())->assertCreated();
        $this->getJson('/api/v1/my-store/public-api')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('store_api_keys', 51);
    }

    public function test_archived_order_stops_crashed_webhook_retry_and_new_events(): void
    {
        StoreOrderWebhook::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'request_key' => Str::uuid(), 'url' => 'https://hooks.example.test/local', 'event_type' => 'orders', 'enabled' => true, 'secret' => Str::random(64)]);
        $order = $this->order();
        $webhooks = app(StoreOrderWebhooks::class);
        $webhooks->created($order);
        $delivery = StoreOrderWebhookDelivery::firstOrFail();
        $delivery->update(['status' => 'processing', 'lease_token' => Str::uuid(), 'lease_until' => now()->subMinute()]);
        $order->delete();
        $webhooks->deliver();
        $webhooks->created($order);
        $this->assertSame('skipped', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->payload);
        $this->assertDatabaseCount('store_order_webhook_deliveries', 1);
        Http::assertNothingSent();
    }
}
