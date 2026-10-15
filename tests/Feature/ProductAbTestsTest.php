<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\User;
use App\Services\Commerce\ProductAbTests;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductAbTestsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $first;

    private Product $second;

    private array $auth;

    private string $public = 'http://ab-tests.sellchase.com/api/v1/storefront';

    private string $admin = '/api/v1/my-store/ab-tests';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Named A/B fixture', 'slug' => 'ab-tests', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'ab-tests.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true, 'credentials' => [], 'test_mode' => false, 'sort_order' => 0]);
        $this->first = Product::create(['store_id' => $this->store->id, 'name' => 'Named product A_10%', 'slug' => 'product-a', 'price' => 100, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 20]);
        $this->second = Product::create(['store_id' => $this->store->id, 'name' => 'Named product B', 'slug' => 'product-b', 'price' => 200, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 20]);
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['name' => 'Named test', 'slug' => 'summer-sale', 'active' => true,
            'targets' => [['product_id' => $this->first->id, 'weight' => 10000], ['product_id' => $this->second->id, 'weight' => 0]]];
    }

    private function create(array $extra = []): int
    {
        return $this->postJson($this->admin, $this->payload($extra), $this->auth)->assertCreated()->json('data.id');
    }

    private function resolve(?string $visitor = null)
    {
        return $this->postJson($this->public.'/ab-tests/summer-sale/resolve', ['visitor' => $visitor ?? (string) Str::uuid()]);
    }

    private function checkout(string $token, ?int $product = null, array $headers = [])
    {
        return $this->postJson($this->public.'/checkout', ['ab_token' => $token, 'cart_mode' => 'direct', 'payment_method' => 'cod',
            'customer_name' => 'Named A/B buyer', 'customer_email' => 'ab-fixture@example.test', 'customer_phone' => '+201001234567',
            'shipping_address' => ['line1' => 'Named fixture address', 'city' => 'Cairo', 'country' => 'EG'],
            'items' => [['product_id' => $product ?? $this->first->id, 'quantity' => 1]]], $headers + ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_traffic_boundaries_and_zero_weight_are_exact(): void
    {
        $targets = [['product_id' => 11, 'weight' => 0], ['product_id' => 12, 'weight' => 3333], ['product_id' => 13, 'weight' => 6667]];
        foreach ([0 => 12, 3332 => 12, 3333 => 13, 9999 => 13] as $bucket => $id) {
            $this->assertSame($id, ProductAbTests::select($targets, $bucket));
        }
    }

    public function test_management_validates_weights_products_slug_and_optimistic_version(): void
    {
        $this->postJson($this->admin, $this->payload(['targets' => [['product_id' => $this->first->id, 'weight' => 9999]]]), $this->auth)->assertUnprocessable();
        $this->postJson($this->admin, $this->payload(['targets' => [['product_id' => $this->first->id, 'weight' => 5000], ['product_id' => $this->first->id, 'weight' => 5000]]]), $this->auth)->assertUnprocessable();
        $this->postJson($this->admin, $this->payload(['targets' => [['product_id' => $this->first->id, 'weight' => 10000.1]]]), $this->auth)->assertUnprocessable();
        $this->postJson($this->admin, $this->payload(['slug' => '../bad']), $this->auth)->assertUnprocessable();
        $this->second->update(['is_active' => false]);
        $this->postJson($this->admin, $this->payload(), $this->auth)->assertUnprocessable();
        $this->second->update(['is_active' => true]);
        $id = $this->create();
        $this->postJson($this->admin, $this->payload(), $this->auth)->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->putJson($this->admin.'/'.$id, $this->payload(['version' => 1, 'active' => false]), $this->auth)->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($this->admin.'/'.$id, $this->payload(['version' => 1]), $this->auth)->assertConflict();
        $this->assertDatabaseCount('store_ab_revisions', 2);
    }

    public function test_public_assignment_is_sticky_private_and_revision_scoped(): void
    {
        $id = $this->create();
        $visitor = (string) Str::uuid();
        $response = $this->resolve($visitor)->assertOk()->assertJsonPath('data.product_id', $this->first->id)->assertJsonMissingPath('data.visitor_hash');
        $token = $response->json('data.token');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->resolve($visitor)->assertOk()->assertJsonPath('data.token', $token);
        $this->assertDatabaseCount('store_ab_visits', 1);
        $this->assertDatabaseMissing('store_ab_visits', ['token_hash' => $token]);
        $this->putJson($this->admin.'/'.$id, $this->payload(['version' => 1, 'targets' => [['product_id' => $this->second->id, 'weight' => 10000]]]), $this->auth)->assertOk();
        $next = $this->resolve($visitor)->assertOk()->assertJsonPath('data.product_id', $this->second->id)->json('data.token');
        $this->assertNotSame($token, $next);
        $this->getJson($this->admin.'/'.$id, $this->auth)->assertOk()->assertJsonCount(2, 'revisions')->assertJsonPath('revisions.0.targets.0.visits', 1)->assertJsonPath('revisions.1.targets.0.visits', 1);
    }

    public function test_pausing_removal_expiry_and_soft_delete_stop_new_assignments(): void
    {
        $id = $this->create();
        $visitor = (string) Str::uuid();
        $this->resolve($visitor)->assertOk();
        $this->first->update(['is_active' => false]);
        $this->resolve()->assertNotFound();
        $this->first->update(['is_active' => true]);
        $this->travel(31)->days();
        $this->resolve($visitor)->assertStatus(410);
        $this->travelBack();
        $this->putJson($this->admin.'/'.$id, $this->payload(['version' => 1, 'active' => false]), $this->auth)->assertOk();
        $this->resolve()->assertNotFound();
        $this->deleteJson($this->admin.'/'.$id, ['version' => 1], $this->auth)->assertConflict();
        $this->deleteJson($this->admin.'/'.$id, ['version' => 2], $this->auth)->assertNoContent();
        $this->getJson($this->admin.'/'.$id, $this->auth)->assertNotFound();
        $this->getJson($this->admin, $this->auth)->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('store_ab_visits', 1);
    }

    public function test_checkout_attributes_once_preserves_inventory_and_reports_paid_separately(): void
    {
        $id = $this->create();
        $token = $this->resolve()->assertOk()->json('data.token');
        $key = ['Idempotency-Key' => (string) Str::uuid()];
        $this->checkout($token, null, $key)->assertCreated()->assertJsonPath('data.grand_total', '100.00')->assertJsonMissingPath('data.ab_token');
        $this->checkout($token, null, $key)->assertSuccessful();
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseCount('store_ab_conversions', 1);
        $this->assertSame(20, $this->first->fresh()->stock_quantity);
        $this->assertSame(1, $this->first->fresh()->reserved_quantity);
        $this->checkout($token)->assertCreated(); // Another purchase is not another visitor conversion.
        $this->assertDatabaseCount('store_ab_conversions', 1);
        $result = $this->getJson($this->admin.'/'.$id, $this->auth)->assertOk()->assertJsonPath('revisions.0.targets.0.orders', 1)->assertJsonCount(0, 'revisions.0.targets.0.paid');
        $order = StoreOrder::withoutGlobalScopes()->oldest('id')->firstOrFail();
        $order->update(['payment_status' => 'paid']);
        $this->getJson($this->admin.'/'.$id, $this->auth)->assertOk()->assertJsonPath('revisions.0.targets.0.paid.0.currency', 'EGP');
        $order->update(['status' => 'cancelled']);
        $this->getJson($this->admin.'/'.$id, $this->auth)->assertOk()->assertJsonPath('revisions.0.targets.0.cancelled_orders', 1)->assertJsonCount(0, 'revisions.0.targets.0.paid');
        Http::assertNothingSent();
    }

    public function test_wrong_product_forged_expired_and_foreign_capabilities_cannot_convert(): void
    {
        $this->create();
        $token = $this->resolve()->assertOk()->json('data.token');
        $this->checkout($token, $this->second->id)->assertCreated();
        $this->checkout(str_repeat('a', 64))->assertCreated();
        DB::table('store_ab_visits')->update(['expires_at' => now()->subMinute()]);
        $this->checkout($token)->assertCreated();
        $this->assertDatabaseCount('store_ab_conversions', 0);
        $this->getJson($this->admin)->assertUnauthorized();
        app(CurrentStore::class)->forget();
        $otherOwner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $otherOwner->assignRole('Merchant');
        $otherAuth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($otherOwner)];
        $other = Store::create(['owner_user_id' => $otherOwner->id, 'owner_type' => 'merchant', 'name' => 'Other fixture', 'slug' => 'other-ab', 'status' => 'active']);
        $foreign = Product::create(['store_id' => $other->id, 'name' => 'Foreign', 'slug' => 'foreign', 'price' => 1, 'is_active' => true]);
        StoreDomain::create(['store_id' => $other->id, 'host' => 'other-ab.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->postJson('http://other-ab.sellchase.com/api/v1/storefront/ab-tests/summer-sale/resolve', ['visitor' => (string) Str::uuid()])->assertNotFound();
        $this->postJson($this->admin, $this->payload(['slug' => 'foreign', 'targets' => [['product_id' => $foreign->id, 'weight' => 10000]]]), $this->auth)->assertUnprocessable();
        $this->getJson('/api/v1/stores/'.$other->id.'/ab-tests', $this->auth)->assertNotFound();
        $foreignId = $this->postJson($this->admin, $this->payload(['targets' => [['product_id' => $foreign->id, 'weight' => 10000]]]), $otherAuth)->assertCreated()->json('data.id');
        $this->getJson($this->admin.'/'.$foreignId, $this->auth)->assertNotFound();
        $foreignToken = $this->postJson('http://other-ab.sellchase.com/api/v1/storefront/ab-tests/summer-sale/resolve', ['visitor' => (string) Str::uuid()])->assertOk()->json('data.token');
        $this->checkout($foreignToken)->assertCreated();
        $this->assertDatabaseCount('store_ab_conversions', 0);
    }

    public function test_previous_revision_capability_still_converts_after_pause_without_mixing_results(): void
    {
        $id = $this->create();
        $token = $this->resolve()->assertOk()->json('data.token');
        $this->putJson($this->admin.'/'.$id, $this->payload(['version' => 1, 'active' => false]), $this->auth)->assertOk();
        $this->checkout($token)->assertCreated();
        $this->getJson($this->admin.'/'.$id, $this->auth)->assertOk()->assertJsonPath('revisions.0.targets.0.orders', 0)->assertJsonPath('revisions.1.targets.0.orders', 1);
    }

    public function test_product_search_is_literal_paginated_and_does_not_leak_private_fields(): void
    {
        $this->getJson($this->admin.'/products?'.http_build_query(['q' => 'A_10%']), $this->auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.cost');
        for ($i = 0; $i < 21; $i++) {
            Product::create(['store_id' => $this->store->id, 'name' => 'Page '.$i, 'slug' => 'page-'.$i, 'price' => 1, 'is_active' => true]);
        }
        $this->getJson($this->admin.'/products', $this->auth)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 2);
    }

    public function test_revision_history_is_bounded_and_populated_rollback_preserves_data(): void
    {
        $migration = require database_path('migrations/2026_10_15_080000_create_store_ab_tests.php');
        $migration->down();
        $migration->up();
        $id = $this->create();
        for ($version = 1; $version <= 10; $version++) {
            $this->putJson($this->admin.'/'.$id, $this->payload(['version' => $version]), $this->auth)->assertOk();
        }
        $this->getJson($this->admin.'/'.$id, $this->auth)->assertOk()->assertJsonCount(10, 'revisions')->assertJsonPath('revisions.0.version', 11)->assertJsonPath('meta.last_page', 2);
        $this->getJson($this->admin.'/'.$id.'?revisions_page=2', $this->auth)->assertOk()->assertJsonCount(1, 'revisions')->assertJsonPath('revisions.0.version', 1);
        try {
            $migration->down();
            $this->fail('Populated A/B history must not be dropped.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Saved A/B tests and attribution history must be preserved.', $exception->getMessage());
        }
        $this->assertDatabaseCount('store_ab_revisions', 11);
    }
}
