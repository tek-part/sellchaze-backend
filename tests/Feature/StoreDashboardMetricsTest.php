<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductDigitalCode;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreCheckoutLead;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use App\Services\Commerce\StoreDashboardMetrics;
use App\Services\Commerce\StorefrontEvents;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreDashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private array $auth;

    private string $public = 'http://metrics.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Metrics', 'slug' => 'metrics', 'status' => 'active', 'currency' => 'EGP', 'shipping_enabled' => true, 'shipping_flat_rate' => 25]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'metrics.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 250, 'cost' => 100, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 20]);
    }

    private function event(string $session, string $kind, array $extra = []): array
    {
        return $extra + ['event_id' => (string) Str::uuid(), 'session_id' => $session, 'kind' => $kind]
            + ($kind === 'visit' ? [] : ['product_id' => $this->product->id]) + ($kind === 'add_to_cart' ? ['quantity' => 1] : []);
    }

    private function metrics(string $from = '2026-10-12', string $to = '2026-10-12')
    {
        return $this->getJson('/api/v1/my-store/analytics/dashboard?from='.$from.'&to='.$to, $this->auth);
    }

    private function checkout(array $extra = []): array
    {
        return $extra + ['customer_name' => 'Metrics reader', 'customer_phone' => '01001234567', 'customer_email' => 'metrics@example.test',
            'shipping_address' => ['line1' => 'Review address', 'city' => 'Cairo', 'country' => 'EG'], 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]];
    }

    public function test_events_are_idempotent_store_scoped_and_do_not_store_contacts_or_reserve_inventory(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->startOfDay());
        $session = (string) Str::uuid();
        $data = $this->event($session, 'add_to_cart', ['source' => 'cross_sell', 'customer_phone' => '01001234567', 'url' => '/?private=secret']);
        $this->postJson($this->public.'/events', $data)->assertStatus(202)->assertHeader('Cache-Control', 'no-store, private');
        $first = DB::table('storefront_events')->first();
        $this->travel(1)->hours();
        $this->postJson($this->public.'/events', $data + ['quantity' => 5])->assertStatus(202);
        $this->assertEquals($first, DB::table('storefront_events')->first());
        $this->assertNotSame($session, $first->session_key);
        $this->assertNotSame($data['event_id'], $first->event_key);
        $this->assertStringNotContainsString('01001234567', json_encode($first));
        $this->assertStringNotContainsString('secret', json_encode($first));
        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertSame(0, $this->product->fresh()->reserved_quantity);
        $this->product->update(['is_active' => false]);
        $this->postJson($this->public.'/events', $data)->assertStatus(202);
        $this->postJson($this->public.'/events', $this->event($session, 'add_to_cart'))->assertUnprocessable();
        $this->assertDatabaseCount('storefront_events', 1);
    }

    public function test_event_contract_rejects_foreign_products_unknown_hosts_and_arbitrary_event_names(): void
    {
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-metrics', 'status' => 'active']);
        $foreign = Product::create(['store_id' => $other->id, 'name' => 'Foreign', 'price' => 1, 'is_active' => true]);
        $session = (string) Str::uuid();
        $this->postJson($this->public.'/events', $this->event($session, 'add_to_cart', ['product_id' => $foreign->id]))->assertUnprocessable();
        $this->postJson($this->public.'/events', $this->event($session, 'purchase'))->assertUnprocessable();
        $this->postJson($this->public.'/events', $this->event($session, 'add_to_cart', ['quantity' => 1000]))->assertUnprocessable();
        $this->postJson($this->public.'/events', $this->event($session, 'visit', ['product_id' => $this->product->id]))->assertUnprocessable();
        $this->postJson('http://unknown.sellchase.com/api/v1/storefront/events', $this->event($session, 'visit'))->assertNotFound();
        $this->assertDatabaseCount('storefront_events', 0);
    }

    public function test_dashboard_uses_inclusive_dates_fills_daily_bins_and_keeps_traffic_sessions_distinct(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->startOfDay());
        $session = (string) Str::uuid();
        foreach (['visit', 'visit', 'add_to_cart', 'add_to_cart', 'checkout_started'] as $kind) {
            $this->postJson($this->public.'/events', $this->event($session, $kind))->assertStatus(202);
        }
        $this->travelTo(now()->addDay()->startOfDay());
        $this->postJson($this->public.'/events', $this->event((string) Str::uuid(), 'visit'))->assertStatus(202);
        $response = $this->metrics()->assertOk();
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertJsonPath('data.summary.visits', 1)
            ->assertJsonPath('data.summary.add_to_cart', 2)->assertJsonPath('data.summary.checkout_started', 1)
            ->assertJsonPath('data.summary.orders', 0)->assertJsonPath('data.funnel.start_rate', '100.00')->assertJsonCount(1, 'data.daily');
        $this->metrics('2026-10-11', '2026-10-13')->assertOk()->assertJsonCount(3, 'data.daily')->assertJsonPath('data.daily.0.visits', null)
            ->assertJsonPath('data.daily.0.checkout_started', null)->assertJsonPath('data.daily.0.orders', 0)->assertJsonPath('data.daily.2.visits', 1);
        $this->metrics('2026-10-13', '2026-10-12')->assertUnprocessable();
        $this->metrics('2025-01-01', '2026-10-12')->assertUnprocessable();
        $this->metrics('2026-02-30', '2026-03-01')->assertUnprocessable();
    }

    public function test_real_checkout_snapshots_cost_and_verified_session_without_exposing_them_or_changing_totals(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->startOfDay());
        $session = (string) Str::uuid();
        foreach (['visit', 'checkout_started', 'add_to_cart'] as $kind) {
            $this->postJson($this->public.'/events', $this->event($session, $kind, ['source' => 'cross_sell']))->assertStatus(202);
        }
        Coupon::create(['store_id' => $this->store->id, 'code' => 'METRICS50', 'type' => 'fixed', 'value' => 50, 'is_active' => true]);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $body = $this->checkout(['analytics_session' => $session, 'coupon_code' => 'METRICS50']);
        $created = $this->postJson($this->public.'/checkout', $body, $headers)->assertCreated()->assertJsonPath('data.grand_total', '225.00');
        $this->assertArrayNotHasKey('analytics_session_hash', $created->json('data'));
        $this->assertArrayNotHasKey('unit_cost', $created->json('data.items.0'));
        $order = StoreOrder::withoutGlobalScopes()->findOrFail($created->json('data.id'));
        $this->assertSame(app(StorefrontEvents::class)->sessionKey($this->store, $session), $order->getAttribute('analytics_session_hash'));
        $this->assertSame('100.00', $order->items()->firstOrFail()->unit_cost);
        $order->update(['status' => 'delivered']);
        $this->product->update(['cost' => 200]);
        $this->postJson($this->public.'/checkout', $body, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseCount('store_orders', 1);
        $this->metrics()->assertOk()->assertJsonPath('data.summary.total_sales', '225.00')->assertJsonPath('data.summary.net_profit', '100.00')
            ->assertJsonPath('data.summary.conversion_rate', '100.00')->assertJsonPath('data.funnel.purchased', 1)->assertJsonPath('data.summary.cross_sell', 1);
    }

    public function test_variant_cost_overrides_product_cost_and_unknown_session_does_not_block_checkout(): void
    {
        $variant = ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $this->product->id, 'name' => 'Blue', 'sku' => 'METRICS-BLUE', 'cost' => 75, 'is_active' => true, 'stock_quantity' => 10]);
        $created = $this->postJson($this->public.'/checkout', $this->checkout(['analytics_session' => (string) Str::uuid(), 'items' => [['product_id' => $this->product->id, 'variant_id' => $variant->id, 'quantity' => 1]]]))->assertCreated();
        $order = StoreOrder::withoutGlobalScopes()->findOrFail($created->json('data.id'));
        $this->assertNull($order->getAttribute('analytics_session_hash'));
        $this->assertSame('75.00', $order->items()->firstOrFail()->unit_cost);
        $this->assertArrayNotHasKey('unit_cost', $order->items()->firstOrFail()->toArray());
    }

    public function test_legacy_cost_is_unknown_and_only_inactive_unconverted_contact_leads_count_as_missed(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->startOfDay()->addHours(12));
        $order = StoreOrder::create(['store_id' => $this->store->id, 'order_number' => 'METRICS-OLD', 'status' => 'delivered', 'subtotal' => 250, 'grand_total' => 250, 'currency' => 'EGP', 'customer_name' => 'Old reader', 'placed_at' => now()]);
        StoreOrderItem::create(['store_id' => $this->store->id, 'store_order_id' => $order->id, 'store_product_id' => $this->product->id, 'name' => 'Old bag', 'unit_price' => 250, 'quantity' => 1, 'line_total' => 250]);
        foreach (['old', 'recent', 'converted', 'cleared'] as $kind) {
            StoreCheckoutLead::create(['store_id' => $this->store->id, 'key_hash' => hash('sha256', $kind), 'currency' => 'EGP',
                'customer_phone' => $kind === 'cleared' ? null : '01001234567', 'last_activity_at' => $kind === 'recent' ? now() : now()->subMinutes(6),
                'converted_at' => $kind === 'converted' ? now() : null]);
        }
        $this->metrics()->assertOk()->assertJsonPath('data.summary.missed_orders', 1)->assertJsonPath('data.summary.net_profit', null)
            ->assertJsonPath('data.profit_complete', false)->assertJsonPath('data.summary.conversion_with_missed_rate', '50.00');
        $this->assertNull(StoreOrderItem::firstOrFail()->unit_cost);
    }

    public function test_low_stock_respects_reserved_units_active_variants_and_the_shared_unused_code_pool(): void
    {
        $low = Product::create(['store_id' => $this->store->id, 'name' => 'Low', 'price' => 10, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 7, 'reserved_quantity' => 3]);
        $digital = Product::create(['store_id' => $this->store->id, 'name' => 'Codes', 'price' => 10, 'digital_type' => 'codes', 'is_active' => true, 'track_inventory' => false]);
        ProductDigitalCode::create(['store_id' => $this->store->id, 'product_id' => $digital->id, 'value' => 'PRIVATE-CODE', 'digest' => hash('sha256', 'PRIVATE-CODE')]);
        ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $this->product->id, 'name' => 'Low option', 'sku' => 'METRICS-LOW', 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 4, 'reserved_quantity' => 1]);
        $result = app(StoreDashboardMetrics::class)->build($this->store, '2026-10-12', '2026-10-12');
        $this->assertSame([1, 3, 4], array_column($result['low_stock'], 'available'));
        $this->assertContains($low->id, array_column($result['low_stock'], 'product_id'));
        $this->assertStringNotContainsString('PRIVATE-CODE', json_encode($result));
        $this->assertSame(3, $low->fresh()->reserved_quantity);
    }

    public function test_private_metrics_require_access_and_populated_event_or_cost_schema_cannot_be_dropped(): void
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($user)];
        $this->getJson('/api/v1/stores/'.$this->store->id.'/analytics/dashboard', $headers)->assertForbidden();
        $this->getJson('/api/v1/my-store/analytics/dashboard')->assertUnauthorized();
        $this->postJson($this->public.'/events', $this->event((string) Str::uuid(), 'visit'))->assertStatus(202);
        $migration = require database_path('migrations/2026_10_12_040000_create_storefront_events_and_order_cost_snapshots.php');
        try {
            $migration->down();
            $this->fail('Recorded metrics must survive rollback');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Preserve', $error->getMessage());
        }
        $this->assertTrue(Schema::hasTable('storefront_events'));
        $this->assertTrue(Schema::hasColumn('store_order_items', 'unit_cost'));
    }

    public function test_private_dashboard_isolates_another_merchants_sales_traffic_leads_and_stock(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->startOfDay()->addHours(12));
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $other = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-metrics', 'status' => 'active']);
        StoreDomain::create(['store_id' => $other->id, 'host' => 'other-metrics.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $product = Product::create(['store_id' => $other->id, 'name' => 'Private other product', 'price' => 1350, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 0]);
        $session = (string) Str::uuid();
        $this->postJson('http://other-metrics.sellchase.com/api/v1/storefront/events', $this->event($session, 'visit'))->assertStatus(202);
        $order = StoreOrder::create(['store_id' => $other->id, 'order_number' => 'OTHER-METRICS', 'status' => 'delivered', 'subtotal' => 1350, 'grand_total' => 1350, 'currency' => 'USD', 'customer_name' => 'Other reader', 'placed_at' => now(), 'analytics_session_hash' => app(StorefrontEvents::class)->sessionKey($other, $session)]);
        StoreOrderItem::create(['store_id' => $other->id, 'store_order_id' => $order->id, 'store_product_id' => $product->id, 'name' => $product->name, 'unit_price' => 675, 'unit_cost' => 600, 'quantity' => 2, 'line_total' => 1350]);
        StoreCheckoutLead::create(['store_id' => $other->id, 'key_hash' => hash('sha256', 'foreign-metrics'), 'currency' => 'USD', 'customer_phone' => '01001234567', 'last_activity_at' => now()->subMinutes(6)]);
        $response = $this->metrics()->assertOk()->assertJsonPath('data.summary.orders', 0)->assertJsonPath('data.summary.total_sales', '0.00')
            ->assertJsonPath('data.summary.net_profit', '0.00')->assertJsonPath('data.summary.visits', 0)->assertJsonPath('data.summary.missed_orders', 0)
            ->assertJsonPath('data.tracking_started_at', null)->assertJsonCount(0, 'data.low_stock')->assertJsonCount(0, 'data.top_products');
        $this->assertStringNotContainsString($product->name, $response->getContent());
        $otherAuth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->getJson('/api/v1/my-store/analytics/dashboard?from=2026-10-12&to=2026-10-12', $otherAuth)->assertOk()
            ->assertJsonPath('data.summary.orders', 1)->assertJsonPath('data.summary.total_sales', '1350.00')->assertJsonPath('data.summary.net_profit', '150.00')
            ->assertJsonPath('data.summary.visits', 1)->assertJsonPath('data.summary.missed_orders', 1)->assertJsonCount(1, 'data.low_stock')->assertJsonCount(1, 'data.top_products');
    }
}
