<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use App\Services\Commerce\ProductAnalytics;
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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private Product $other;

    private array $auth;

    private string $public = 'http://funnel.sellchase.com/api/v1/storefront';

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
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Funnel', 'slug' => 'funnel', 'status' => 'active', 'currency' => 'EGP', 'timezone' => 'Africa/Cairo']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'funnel.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Linen shirt', 'slug' => 'linen-shirt', 'price' => 300, 'is_active' => true]);
        $this->other = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Canvas bag', 'slug' => 'canvas-bag', 'price' => 100, 'is_active' => true]);
    }

    private function event(string $session, string $kind, array $extra = []): array
    {
        return $extra + ['event_id' => (string) Str::uuid(), 'session_id' => $session, 'kind' => $kind]
            + ($kind === 'visit' ? [] : ['product_id' => $this->product->id]) + ($kind === 'add_to_cart' ? ['quantity' => 1] : []);
    }

    private function send(string $session, string $kind, array $extra = [], array $headers = [])
    {
        return $this->postJson($this->public.'/events', $this->event($session, $kind, $extra), $headers + ['User-Agent' => 'Mozilla/5.0 (iPhone) Safari/604.1']);
    }

    private function order(string $number, string $status, array $lines, array $extra = []): StoreOrder
    {
        $order = StoreOrder::create($extra + ['store_id' => $this->store->id, 'order_number' => $number, 'status' => $status, 'subtotal' => 0, 'grand_total' => 0,
            'currency' => 'EGP', 'customer_name' => 'Reader', 'customer_email' => 'reader@example.test', 'placed_at' => now()]);
        foreach ($lines as $line) {
            StoreOrderItem::create($line + ['store_id' => $this->store->id, 'store_order_id' => $order->id, 'store_product_id' => $this->product->id, 'name' => 'Linen shirt', 'unit_price' => 300, 'quantity' => 1, 'line_total' => 300]);
        }

        return $order;
    }

    private function funnel(string $query = '')
    {
        return $this->getJson('/api/v1/my-store/analytics/product-funnel?from=2026-10-12&to=2026-10-12'.$query, $this->auth);
    }

    public function test_product_views_are_deduplicated_per_session_exclude_bots_and_roll_up_by_store_day_and_campaign(): void
    {
        // 23:30 UTC on 11 Oct is 12 Oct in Cairo: the rollup uses the store's calendar day.
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(23, 30));
        $campaign = ['utm_source' => 'facebook', 'utm_medium' => 'paid', 'utm_campaign' => 'autumn'];
        $first = (string) Str::uuid();
        $this->send($first, 'visit', $campaign)->assertStatus(202);
        $this->send($first, 'product_view', $campaign)->assertStatus(202);
        $this->send($first, 'product_view', $campaign)->assertStatus(202);
        $this->send($first, 'add_to_cart', $campaign + ['quantity' => 2])->assertStatus(202);
        $this->send($first, 'add_to_cart', $campaign + ['quantity' => 1])->assertStatus(202);
        $this->send($first, 'checkout_started', $campaign)->assertStatus(202);
        $second = (string) Str::uuid();
        $this->send($second, 'product_view')->assertStatus(202);
        $this->send((string) Str::uuid(), 'product_view', [], ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])->assertStatus(202);

        $this->assertSame(2, DB::table('storefront_events')->where('kind', 'product_view')->count());
        $row = DB::table('store_product_daily_metrics')->where('utm_source', 'facebook')->first();
        $this->assertSame('2026-10-12', substr((string) $row->day, 0, 10));
        $this->assertSame([1, 1, 3, 1], [(int) $row->views, (int) $row->carts, (int) $row->cart_units, (int) $row->checkouts]);
        $this->assertSame('paid', DB::table('storefront_session_campaigns')->value('utm_medium'));
        $this->assertSame('autumn', DB::table('storefront_events')->where('kind', 'visit')->value('utm_campaign'));

        $this->funnel()->assertOk()->assertJsonPath('data.totals.views', 2)->assertJsonPath('data.products.0.product_id', $this->product->id)
            ->assertJsonPath('data.products.0.carts', 1)->assertJsonPath('data.products.0.cart_rate', '50.00')
            ->assertJsonPath('data.options.utm_source', ['facebook'])->assertJsonPath('data.period.timezone', 'Africa/Cairo');
        $this->funnel('&utm_source=facebook&utm_medium=paid')->assertOk()->assertJsonPath('data.totals.views', 1)->assertJsonPath('data.totals.checkout_rate', '100.00');
        $this->funnel('&utm_campaign=missing')->assertOk()->assertJsonPath('data.totals.views', 0)->assertJsonPath('data.totals.conversion_rate', null);
    }

    public function test_funnel_counts_real_orders_units_and_revenue_by_campaign_and_excludes_cancelled_and_deleted_orders(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(10, 0));
        $session = (string) Str::uuid();
        $this->send($session, 'visit', ['utm_source' => 'tiktok', 'utm_medium' => 'video', 'utm_campaign' => 'launch'])->assertStatus(202);
        $this->send($session, 'product_view', ['utm_source' => 'tiktok', 'utm_medium' => 'video', 'utm_campaign' => 'launch'])->assertStatus(202);
        $hash = app(StorefrontEvents::class)->sessionKey($this->store, $session);
        $this->order('F-1', 'pending', [['quantity' => 2, 'line_total' => 600], ['store_product_id' => $this->other->id, 'name' => 'Canvas bag', 'quantity' => 1, 'line_total' => 100]], ['analytics_session_hash' => $hash]);
        // Order labels keep the visitor's casing; filters match them case-insensitively.
        $this->order('F-2', 'delivered', [['quantity' => 1, 'line_total' => 300]], ['marketing_attribution' => ['source' => 'Google', 'campaign' => 'Search']]);
        $this->order('F-3', 'cancelled', [['quantity' => 5, 'line_total' => 1500]]);
        $this->order('F-4', 'pending', [['quantity' => 7, 'line_total' => 2100]])->delete();
        $this->order('F-5', 'pending', [['quantity' => 9, 'line_total' => 2700, 'superseded_at' => now()]]);

        $this->funnel()->assertOk()->assertJsonPath('data.products.0.product_id', $this->product->id)->assertJsonPath('data.products.0.orders', 2)
            ->assertJsonPath('data.products.0.units', 3)->assertJsonPath('data.products.0.revenue', '900.00')->assertJsonPath('data.products.0.conversion_rate', '200.00')
            ->assertJsonPath('data.totals.orders', 3)->assertJsonPath('data.totals.revenue', '1000.00');
        $this->funnel('&utm_source=tiktok&utm_medium=video')->assertOk()->assertJsonPath('data.totals.orders', 1)->assertJsonPath('data.products.0.units', 2);
        // Orders from before session campaigns existed keep their own source/campaign labels.
        $this->funnel('&utm_source=GOOGLE&utm_campaign=search')->assertOk()->assertJsonPath('data.totals.orders', 1)->assertJsonPath('data.products.0.revenue', '300.00');
        $this->funnel('&utm_source=google&utm_medium=cpc')->assertOk()->assertJsonPath('data.totals.orders', 0);
    }

    public function test_products_ordered_today_lists_variants_in_the_store_timezone_and_exports_csv(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(21, 30)); // 00:30 on 13 Oct in Cairo
        $this->order('D-1', 'pending', [['variant_id' => 11, 'variant_name' => 'Red / M', 'variant_options' => ['color' => 'Red', 'size' => 'M'], 'sku' => 'LS-R-M', 'quantity' => 2],
            ['variant_id' => 12, 'variant_name' => 'Blue / L', 'variant_options' => ['color' => 'Blue', 'size' => 'L'], 'sku' => 'LS-B-L', 'quantity' => 1]]);
        $this->order('D-2', 'confirmed', [['variant_id' => 11, 'variant_name' => 'Red / M', 'variant_options' => ['color' => 'Red', 'size' => 'M'], 'sku' => 'LS-R-M', 'quantity' => 3]]);
        $this->order('D-3', 'cancelled', [['variant_id' => 11, 'variant_name' => 'Red / M', 'sku' => 'LS-R-M', 'quantity' => 40]]);
        $this->order('D-4', 'pending', [['quantity' => 1]], ['placed_at' => now()->subDay()]);

        $response = $this->getJson('/api/v1/my-store/analytics/ordered-products', $this->auth)->assertOk()
            ->assertJsonPath('data.date', '2026-10-13')->assertJsonCount(2, 'data.items')->assertJsonPath('data.totals.quantity', 6)->assertJsonPath('data.totals.orders', 2);
        $red = collect($response->json('data.items'))->firstWhere('variant_id', 11);
        $this->assertSame(5, $red['quantity']);
        $this->assertSame(2, $red['orders']);
        $this->assertSame([['name' => 'color', 'value' => 'Red'], ['name' => 'size', 'value' => 'M']], $red['options']);
        $this->getJson('/api/v1/my-store/analytics/ordered-products?date=2026-10-12', $this->auth)->assertOk()->assertJsonPath('data.totals.quantity', 1);

        $csv = $this->get('/api/v1/my-store/analytics/ordered-products/export?locale=ar', $this->auth);
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $body = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('المنتج', $body);
        $this->assertStringContainsString('"Linen shirt","Red / M","color: Red · size: M",LS-R-M,5,2', $body);
        $this->assertStringNotContainsString(',40,', $body);
    }

    public function test_reports_are_store_scoped_and_need_the_analytics_permission(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(10, 0));
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $foreign = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Foreign', 'slug' => 'foreign-funnel', 'status' => 'active']);
        StoreDomain::create(['store_id' => $foreign->id, 'host' => 'foreign-funnel.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $product = Product::create(['store_id' => $foreign->id, 'name' => 'Private foreign product', 'price' => 10, 'is_active' => true]);
        $this->postJson('http://foreign-funnel.sellchase.com/api/v1/storefront/events', ['event_id' => (string) Str::uuid(), 'session_id' => (string) Str::uuid(), 'kind' => 'product_view', 'product_id' => $product->id])->assertStatus(202);
        // A product from another store is never accepted by this store's event endpoint.
        $this->send((string) Str::uuid(), 'product_view', ['product_id' => $product->id])->assertUnprocessable();
        $order = StoreOrder::create(['store_id' => $foreign->id, 'order_number' => 'FOREIGN-1', 'status' => 'pending', 'subtotal' => 10, 'grand_total' => 10, 'currency' => 'EGP', 'customer_name' => 'X', 'customer_email' => 'x@example.test', 'placed_at' => now()]);
        StoreOrderItem::create(['store_id' => $foreign->id, 'store_order_id' => $order->id, 'store_product_id' => $product->id, 'name' => $product->name, 'unit_price' => 10, 'quantity' => 4, 'line_total' => 40]);

        $funnel = $this->funnel()->assertOk()->assertJsonPath('data.totals.views', 0)->assertJsonPath('data.totals.orders', 0)->assertJsonCount(0, 'data.products');
        $this->assertStringNotContainsString('Private foreign product', $funnel->getContent());
        $this->getJson('/api/v1/my-store/analytics/ordered-products?date=2026-10-12', $this->auth)->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/v1/stores/'.$foreign->id.'/analytics/product-funnel', $this->auth)->assertForbidden();

        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $employee->givePermissionTo('store.orders.manage');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee)];
        $this->getJson('/api/v1/my-store/analytics/product-funnel', $headers)->assertForbidden();
        $this->getJson('/api/v1/my-store/analytics/ordered-products', $headers)->assertForbidden();
        $this->get('/api/v1/my-store/analytics/ordered-products/export', $headers + ['Accept' => 'application/json'])->assertForbidden();
        $analyst = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $analyst->assignRole('Employee');
        $analyst->givePermissionTo('store.analytics.view');
        $this->getJson('/api/v1/my-store/analytics/product-funnel', ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($analyst)])->assertOk();
        $this->getJson('/api/v1/my-store/analytics/product-funnel')->assertUnauthorized();
    }

    public function test_event_contract_rejects_bad_campaign_labels_and_long_ranges_and_rollback_preserves_data(): void
    {
        $session = (string) Str::uuid();
        $this->send($session, 'product_view', ['utm_source' => '<script>'])->assertUnprocessable();
        $this->send($session, 'product_view', ['utm_campaign' => str_repeat('a', 201)])->assertUnprocessable();
        $this->send($session, 'product_view', ['quantity' => 2])->assertUnprocessable();
        $this->assertDatabaseCount('storefront_events', 0);
        $this->getJson('/api/v1/my-store/analytics/product-funnel?from=2025-01-01&to=2026-10-12', $this->auth)->assertUnprocessable();
        $this->getJson('/api/v1/my-store/analytics/ordered-products?date=12-10-2026', $this->auth)->assertUnprocessable();

        $this->send($session, 'product_view', ['utm_source' => 'newsletter', 'utm_campaign' => 'weekly'])->assertStatus(202);
        $migration = require database_path('migrations/2026_10_30_040001_create_product_analytics_tables.php');
        try {
            $migration->down();
            $this->fail('Recorded product analytics must survive rollback');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Preserve', $error->getMessage());
        }
        $this->assertTrue(Schema::hasTable('store_product_daily_metrics'));
    }

    public function test_campaign_labels_are_normalised_and_each_store_day_keeps_a_bounded_number_of_segments(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(10, 0));
        $this->send((string) Str::uuid(), 'product_view', ['utm_source' => '  FaceBook   Ads!!', 'utm_medium' => 'CPC', 'utm_campaign' => str_repeat('x', 150)])->assertStatus(202);
        $row = DB::table('store_product_daily_metrics')->first();
        $this->assertSame(['facebook ads', 'cpc', str_repeat('x', 64)], [$row->utm_source, $row->utm_medium, $row->utm_campaign]);
        $this->funnel('&utm_source=Facebook%20Ads')->assertOk()->assertJsonPath('data.totals.views', 1);

        $events = app(StorefrontEvents::class);
        for ($index = 2; $index <= ProductAnalytics::MAX_SEGMENTS + 5; $index++) {
            $events->capture($this->store, ['event_id' => (string) Str::uuid(), 'session_id' => (string) Str::uuid(), 'kind' => 'product_view', 'product_id' => $this->product->id, 'utm_source' => 'ad', 'utm_campaign' => 'c'.$index]);
        }
        $this->assertSame(ProductAnalytics::MAX_SEGMENTS + 1, DB::table('store_product_daily_metrics')->distinct()->count('segment'));
        // The cap is decided from a small registry, not the store's daily rollup rows.
        $this->assertSame(ProductAnalytics::MAX_SEGMENTS, DB::table('store_campaign_segments')->where('store_id', $this->store->id)->count());
        $this->assertSame(0, DB::table('store_campaign_segments')->where('segment', sha1("(other)\n\n"))->count());
        $this->assertSame(5, (int) DB::table('store_product_daily_metrics')->where('utm_source', '(other)')->value('views'));
        $this->funnel('&utm_source=(other)')->assertOk()->assertJsonPath('data.totals.views', 5);
        // A known campaign keeps counting under its own segment after the cap is reached.
        $events->capture($this->store, ['event_id' => (string) Str::uuid(), 'session_id' => (string) Str::uuid(), 'kind' => 'product_view', 'product_id' => $this->product->id, 'utm_source' => 'ad', 'utm_campaign' => 'c2']);
        $this->assertSame(2, (int) DB::table('store_product_daily_metrics')->where('utm_campaign', 'c2')->value('views'));
        $this->assertSame(ProductAnalytics::MAX_SEGMENTS + 1, DB::table('store_product_daily_metrics')->distinct()->count('segment'));
    }

    public function test_product_views_have_a_per_ip_budget_and_privacy_signals_record_nothing(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(10, 0));
        for ($index = 0; $index < 35; $index++) {
            $this->send((string) Str::uuid(), 'product_view')->assertStatus(202);
        }
        $this->assertSame(30, DB::table('storefront_events')->where('kind', 'product_view')->count());
        // Another address still counts, and other event kinds are not limited by the view budget.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->send((string) Str::uuid(), 'product_view')->assertStatus(202);
        $this->send((string) Str::uuid(), 'add_to_cart')->assertStatus(202);
        $this->assertSame(31, DB::table('storefront_events')->where('kind', 'product_view')->count());
        $this->assertSame(1, DB::table('storefront_events')->where('kind', 'add_to_cart')->count());
        $this->travel(61)->seconds();
        $this->send((string) Str::uuid(), 'product_view')->assertStatus(202);
        $this->assertSame(32, DB::table('storefront_events')->where('kind', 'product_view')->count());
        RateLimiter::clear('sf-events:product_view:'.$this->store->id.':'.hash('sha256', '127.0.0.1').':day');

        $before = DB::table('storefront_events')->count();
        $this->send((string) Str::uuid(), 'visit', [], ['DNT' => '1'])->assertStatus(202);
        $this->send((string) Str::uuid(), 'add_to_cart', [], ['Sec-GPC' => '1'])->assertStatus(202);
        $this->assertSame($before, DB::table('storefront_events')->count());
    }

    public function test_cart_sessions_are_counted_once_by_a_unique_key_and_a_failed_rollup_rolls_the_event_back(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(10, 0));
        $session = (string) Str::uuid();
        $this->send($session, 'add_to_cart', ['quantity' => 2])->assertStatus(202);
        $this->send($session, 'add_to_cart', ['quantity' => 3])->assertStatus(202);
        $row = DB::table('store_product_daily_metrics')->first();
        $this->assertSame([1, 5], [(int) $row->carts, (int) $row->cart_units]);
        $this->assertSame(1, DB::table('storefront_session_products')->where('kind', 'add_to_cart')->count());

        $real = app(ProductAnalytics::class);
        $this->app->instance(ProductAnalytics::class, new class extends ProductAnalytics
        {
            public function increment(Store $store, int $productId, string $session, string $kind, int $quantity, array $campaign): void
            {
                throw new \RuntimeException('Rollup unavailable');
            }
        });
        $retry = $this->event((string) Str::uuid(), 'checkout_started');
        $this->withoutExceptionHandling();
        try {
            app(StorefrontEvents::class)->capture($this->store, $retry);
            $this->fail('The rollup failure must surface');
        } catch (\RuntimeException $error) {
            $this->assertSame('Rollup unavailable', $error->getMessage());
        }
        $this->assertSame(0, DB::table('storefront_events')->where('kind', 'checkout_started')->count());
        $this->assertSame(0, DB::table('storefront_session_products')->where('kind', 'checkout_started')->count());
        $this->app->instance(ProductAnalytics::class, $real);
        app(StorefrontEvents::class)->capture($this->store, $retry);
        $this->assertSame(1, (int) DB::table('store_product_daily_metrics')->value('checkouts'));
    }

    public function test_retention_prunes_unconverted_session_campaigns_and_old_session_markers_but_keeps_order_attribution(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 1)->setTime(10, 0));
        $converted = (string) Str::uuid();
        $abandoned = (string) Str::uuid();
        foreach ([$converted, $abandoned] as $session) {
            $this->send($session, 'add_to_cart', ['utm_source' => 'meta', 'utm_campaign' => 'sept'])->assertStatus(202);
        }
        $this->order('R-1', 'pending', [['quantity' => 1]], ['analytics_session_hash' => app(StorefrontEvents::class)->sessionKey($this->store, $converted)]);
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(10, 0));
        $recent = (string) Str::uuid();
        $this->send($recent, 'add_to_cart', ['utm_source' => 'meta', 'utm_campaign' => 'oct'])->assertStatus(202);

        $this->artisan('operations:retention')->expectsOutputToContain('storefront_session_campaigns: 1 deleted')->expectsOutputToContain('storefront_session_products: 2 deleted')->assertSuccessful();
        $keys = DB::table('storefront_session_campaigns')->pluck('session_key')->all();
        $events = app(StorefrontEvents::class);
        $this->assertEqualsCanonicalizing([$events->sessionKey($this->store, $converted), $events->sessionKey($this->store, $recent)], $keys);
        $this->assertSame(1, DB::table('storefront_session_products')->count());
        // The order placed in September keeps its campaign in reports.
        $this->getJson('/api/v1/my-store/analytics/product-funnel?from=2026-09-01&to=2026-09-01&utm_source=meta', $this->auth)->assertOk()->assertJsonPath('data.totals.orders', 1);
    }

    public function test_cart_and_checkout_events_also_have_per_ip_budgets(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(10, 0));
        for ($index = 0; $index < 33; $index++) {
            $this->send((string) Str::uuid(), 'add_to_cart')->assertStatus(202);
        }
        for ($index = 0; $index < 23; $index++) {
            $this->send((string) Str::uuid(), 'checkout_started')->assertStatus(202);
        }
        $this->assertSame(30, DB::table('storefront_events')->where('kind', 'add_to_cart')->count());
        $this->assertSame(20, DB::table('storefront_events')->where('kind', 'checkout_started')->count());
        $row = DB::table('store_product_daily_metrics')->first();
        $this->assertSame([30, 20], [(int) $row->carts, (int) $row->checkouts]);
        // Visits keep only the route throttle, so ordinary browsing is unaffected.
        $this->send((string) Str::uuid(), 'visit')->assertStatus(202);
        $this->assertSame(1, DB::table('storefront_events')->where('kind', 'visit')->count());
        // The daily budget still applies once the minute window has reset: 20 used, 79 more fill it to 99.
        $this->travel(61)->seconds();
        $day = 'sf-events:checkout_started:'.$this->store->id.':'.hash('sha256', '127.0.0.1').':day';
        for ($index = 0; $index < 79; $index++) {
            RateLimiter::hit($day, 86400);
        }
        $this->send((string) Str::uuid(), 'checkout_started')->assertStatus(202);
        $this->send((string) Str::uuid(), 'checkout_started')->assertStatus(202);
        $this->assertSame(21, DB::table('storefront_events')->where('kind', 'checkout_started')->count());
    }
}
