<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreFunnel;
use App\Models\StorePage;
use App\Models\StorePaymentGateway;
use App\Models\StoreTrackingDelivery;
use App\Models\StoreTrackingEvent;
use App\Models\User;
use App\Services\Commerce\StoreTrackingDispatch;
use App\Services\Commerce\StoreTrackingSettings;
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

class StoreTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private array $auth;

    private string $public = 'http://tracking.sellchase.com/api/v1/storefront';

    private const SNAP = '12345678-1234-4234-8234-123456789abc';

    private const TIKTOK = 'C7TEST0123456789ABCDE0';

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
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Tracking', 'slug' => 'tracking', 'status' => 'active', 'currency' => 'EGP', 'shipping_enabled' => true, 'shipping_flat_rate' => 25]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'tracking.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true, 'test_mode' => true, 'credentials' => []]);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Tracking bag', 'slug' => 'tracking-bag', 'price' => 250, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 20]);
    }

    private function conversion(string $provider = 'facebook', string $pixel = '1234567890', string $token = 'TEST-TOKEN-PRIVATE'): array
    {
        $version = app(StoreTrackingSettings::class)->configuration($this->store->fresh())['version'];

        return $this->postJson('/api/v1/my-store/tracking/conversions', ['version' => $version, 'provider' => $provider, 'pixel_id' => $pixel, 'token' => $token, 'note' => 'Own review', 'enabled' => true], $this->auth)->assertOk()->json('data.rows');
    }

    private function event(array $extra = []): array
    {
        return $extra + ['event_id' => (string) Str::uuid(), 'event' => 'add_to_cart', 'advertising_allowed' => true, 'page_path' => '/products/tracking-bag',
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]]];
    }

    private function pixelFunnel(string $slug = 'pixel-funnel', string $status = 'published'): StoreFunnel
    {
        $page = StorePage::create(['store_id' => $this->store->id, 'title' => 'Pixel fixture', 'slug' => $slug, 'locale' => 'en', 'template' => 'landing', 'status' => $status]);

        return StoreFunnel::create(['store_id' => $this->store->id, 'store_page_id' => $page->id, 'product_id' => $this->product->id, 'template_key' => 'spotlight', 'browser_pixels' => ['facebook' => ['2345678901'], 'tiktok' => [self::TIKTOK], 'snapchat' => []]]);
    }

    public function test_funnel_only_pixels_resolve_published_paths_and_products_without_leaking_to_other_surfaces(): void
    {
        $funnel = $this->pixelFunnel();
        $draft = $this->pixelFunnel('draft-pixels', 'draft');
        $future = $this->pixelFunnel('future-pixels', 'scheduled');
        StorePage::withoutGlobalScopes()->whereKey($future->store_page_id)->update(['publish_at' => now()->addDay()]);
        $other = Product::create(['store_id' => $this->store->id, 'name' => 'Different bag', 'slug' => 'different-bag', 'price' => 20, 'is_active' => true]);
        foreach (['/products/tracking-bag', '/funnels/missing', '/funnels/draft-pixels', '/funnels/future-pixels', '/funnels/pixel-funnel?receipt=bad'] as $path) {
            $response = $this->postJson($this->public.'/tracking/events', $this->event(['page_path' => $path]));
            str_contains($path, '?') ? $response->assertUnprocessable() : $response->assertStatus(202)->assertJsonPath('data', null);
        }
        $this->postJson($this->public.'/tracking/events', $this->event(['page_path' => '/funnels/pixel-funnel', 'items' => [['product_id' => $other->id, 'quantity' => 1]]]))->assertStatus(202)->assertJsonPath('data', null);
        $this->assertDatabaseCount('store_tracking_events', 0);
        foreach (['/funnels/pixel-funnel', '/pages/pixel-funnel'] as $path) {
            $data = $this->event(['event' => 'begin_checkout', 'page_path' => $path]);
            $response = $this->postJson($this->public.'/tracking/events', $data)->assertStatus(202)->assertJsonPath('data.configuration.facebook', ['2345678901'])->assertJsonPath('data.event.value', '500.00');
            $this->assertArrayNotHasKey('funnel_pixels', $response->json('data.event'));
            $this->assertArrayNotHasKey('user_data', $response->json('data.event'));
            $this->postJson($this->public.'/tracking/events', $data)->assertStatus(202)->assertJsonPath('data.event.event_id', $response->json('data.event.event_id'));
        }
        $this->assertDatabaseCount('store_tracking_events', 2);
        foreach ([['DNT' => '1'], ['Sec-GPC' => '1']] as $headers) {
            $this->postJson($this->public.'/tracking/events', $this->event(['page_path' => '/funnels/pixel-funnel']), $headers)->assertStatus(202)->assertJsonPath('data', null);
        }
        $this->postJson($this->public.'/tracking/events', $this->event(['page_path' => '/funnels/pixel-funnel', 'advertising_allowed' => false]))->assertStatus(202)->assertJsonPath('data', null);
        $this->assertDatabaseCount('store_tracking_events', 2);
        $this->assertDatabaseCount('store_tracking_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_funnel_purchase_keeps_totals_and_shared_event_identity_and_removals_stop_receipt_replays(): void
    {
        $funnel = $this->pixelFunnel();
        $this->store->update(['tracking_configuration' => ['facebook' => ['2345678901', '3456789012']]]);
        $this->conversion();
        $body = ['customer_name' => 'Synthetic funnel buyer', 'customer_phone' => '01001234567', 'customer_email' => 'funnel@example.test', 'advertising_allowed' => true, 'shipping_address' => ['line1' => 'Synthetic address', 'city' => 'Cairo', 'country' => 'EG'], 'items' => [['product_id' => $this->product->id, 'quantity' => 2]], 'payment_method' => 'cod', 'cart_mode' => 'direct', 'checkout_surface' => 'funnel', 'funnel_path' => '/funnels/pixel-funnel'];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $first = $this->postJson($this->public.'/checkout', $body, $headers)->assertCreated()->assertJsonPath('data.grand_total', '525.00')->assertJsonPath('data.tracking.event.gross_value', '525.00')
            ->assertJsonPath('data.tracking.configuration.facebook', ['2345678901', '3456789012'])->assertJsonPath('data.tracking.configuration.tiktok', [self::TIKTOK]);
        $id = $first->json('data.tracking.event.event_id');
        $this->postJson($this->public.'/checkout', $body, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.tracking.event.event_id', $id);
        $funnel->update(['browser_pixels' => ['facebook' => ['4567890123'], 'tiktok' => [], 'snapchat' => []]]);
        $this->postJson($this->public.'/checkout/receipt', ['token' => $first->json('receipt.token'), 'advertising_allowed' => true])->assertOk()
            ->assertJsonPath('data.tracking.event.event_id', $id)->assertJsonPath('data.tracking.configuration.facebook', ['2345678901', '3456789012'])->assertJsonPath('data.tracking.configuration.tiktok', []);
        $this->store->fresh()->update(['tracking_configuration' => null, 'tracking_credentials' => null]);
        $this->postJson($this->public.'/checkout/receipt', ['token' => $first->json('receipt.token'), 'advertising_allowed' => true])->assertOk()->assertJsonPath('data.tracking.configuration.facebook', []);
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseCount('store_tracking_events', 1);
        $this->assertDatabaseCount('store_tracking_deliveries', 1);
        $this->assertSame($funnel->id, StoreTrackingEvent::firstOrFail()->payload['funnel_id']);
        Http::assertNothingSent();
    }

    public function test_default_empty_settings_do_not_record_or_send_and_pixels_can_be_saved_deduplicated_and_cleared(): void
    {
        $this->getJson($this->public.'/tracking')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonCount(0, 'data.facebook');
        $this->postJson($this->public.'/tracking/events', $this->event())->assertStatus(202)->assertJsonPath('data', null);
        $this->assertDatabaseCount('store_tracking_events', 0);
        Http::assertNothingSent();
        $this->putJson('/api/v1/my-store/tracking/pixels', ['version' => 1, 'facebook' => ['1234567890', ' 1234567890 ', '2345678901'], 'tiktok' => [self::TIKTOK], 'snapchat' => [self::SNAP]], $this->auth)
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonCount(2, 'data.facebook');
        $this->putJson('/api/v1/my-store/tracking/google-tag', ['version' => 1, 'google' => ['G-Y1F28T2SY8']], $this->auth)->assertConflict();
        $this->putJson('/api/v1/my-store/tracking/google-tag', ['version' => 2, 'google' => ['G-Y1F28T2SY8']], $this->auth)->assertOk()->assertJsonPath('data.version', 3);
        $this->putJson('/api/v1/my-store/tracking/pixels', ['version' => 3, 'facebook' => [], 'tiktok' => [], 'snapchat' => []], $this->auth)->assertOk();
        $this->putJson('/api/v1/my-store/tracking/google-tag', ['version' => 4, 'google' => []], $this->auth)->assertOk();
        $this->getJson($this->public.'/tracking')->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertSame('25.00', $this->store->fresh()->shipping_flat_rate);
    }

    public function test_conversion_crud_encrypts_tokens_and_every_public_and_private_response_keeps_them_hidden(): void
    {
        $row = $this->conversion()[0];
        $raw = DB::table('stores')->where('id', $this->store->id)->value('tracking_credentials');
        $this->assertStringNotContainsString('TEST-TOKEN-PRIVATE', $raw);
        $this->assertSame('TEST-TOKEN-PRIVATE', $this->store->fresh()->tracking_credentials[$row['id']]);
        foreach (['/api/v1/my-store', '/api/v1/my-store/tracking/conversions', $this->public, $this->public.'/tracking'] as $url) {
            $response = $this->getJson($url, str_contains($url, 'my-store') ? $this->auth : [])->assertOk();
            $this->assertStringNotContainsString('TEST-TOKEN-PRIVATE', $response->getContent());
        }
        $this->putJson('/api/v1/my-store/tracking/conversions/'.$row['id'], ['version' => 2, 'provider' => 'facebook', 'pixel_id' => '1234567890', 'token' => '', 'note' => 'Changed note', 'enabled' => false], $this->auth)
            ->assertOk()->assertJsonPath('data.rows.0.enabled', false)->assertJsonPath('data.rows.0.token_configured', true);
        $this->assertSame('TEST-TOKEN-PRIVATE', $this->store->fresh()->tracking_credentials[$row['id']]);
        $this->deleteJson('/api/v1/my-store/tracking/conversions/'.$row['id'], ['version' => 3], $this->auth)->assertOk()->assertJsonCount(0, 'data.rows');
        $this->assertNull($this->store->fresh()->tracking_credentials);
        Http::assertNothingSent();
    }

    public function test_tracking_ids_raw_scripts_foreign_access_and_untrusted_purchase_events_are_rejected(): void
    {
        $this->putJson('/api/v1/my-store/tracking/pixels', ['version' => 1, 'facebook' => ['<script>alert(1)</script>'], 'tiktok' => [], 'snapchat' => []], $this->auth)->assertUnprocessable();
        $this->putJson('/api/v1/my-store/tracking/google-tag', ['version' => 1, 'google' => ['GTM-ABC1234']], $this->auth)->assertUnprocessable();
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->getJson('/api/v1/stores/'.$this->store->id.'/tracking/conversions', ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($user)])->assertForbidden();
        $this->getJson('/api/v1/my-store/tracking/pixels')->assertUnauthorized();
        $this->conversion();
        $this->postJson($this->public.'/tracking/events', $this->event(['event' => 'purchase']))->assertUnprocessable();
        $this->postJson($this->public.'/tracking/events', $this->event(['page_path' => '/checkout?receipt=private']))->assertUnprocessable();
        $other = Store::create(['owner_user_id' => $user->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-tracking', 'status' => 'active']);
        $product = Product::create(['store_id' => $other->id, 'name' => 'Other', 'price' => 10, 'is_active' => true]);
        $this->postJson($this->public.'/tracking/events', $this->event(['items' => [['product_id' => $product->id, 'quantity' => 1]]]))->assertUnprocessable();
        $this->postJson('http://unknown.sellchase.com/api/v1/storefront/tracking/events', $this->event())->assertNotFound();
        $this->assertDatabaseCount('store_tracking_events', 0);
    }

    public function test_catalog_events_are_server_priced_immutable_idempotent_and_have_no_business_or_outbound_effects(): void
    {
        $this->conversion();
        $data = $this->event(['value' => '0.01', 'customer_email' => 'PRIVATE@example.test']);
        $first = $this->postJson($this->public.'/tracking/events', $data)->assertStatus(202)->assertJsonPath('data.event.value', '500.00')->assertJsonPath('data.event.items.0.price', '250.00');
        $event = StoreTrackingEvent::firstOrFail();
        $this->assertSame($event->event_key, $first->json('data.event.event_id'));
        $this->assertArrayNotHasKey('user_data', $first->json('data.event'));
        $this->assertStringNotContainsString('PRIVATE@example.test', DB::table('store_tracking_events')->value('payload'));
        $this->product->update(['price' => 300, 'is_active' => false]);
        $this->postJson($this->public.'/tracking/events', $data)->assertStatus(202)->assertJsonPath('data.event.value', '500.00');
        $this->assertDatabaseCount('store_tracking_events', 1);
        $this->assertDatabaseCount('store_tracking_deliveries', 1);
        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertSame(0, $this->product->fresh()->reserved_quantity);
        Http::assertNothingSent();
    }

    public function test_opt_out_headers_and_body_prevent_both_browser_events_and_server_delivery_creation(): void
    {
        $this->conversion();
        foreach ([['DNT' => '1'], ['Sec-GPC' => '1']] as $headers) {
            $this->postJson($this->public.'/tracking/events', $this->event(), $headers)->assertStatus(202)->assertJsonPath('data', null);
        }
        $this->postJson($this->public.'/tracking/events', $this->event(['advertising_allowed' => false]))->assertStatus(202)->assertJsonPath('data', null);
        $this->assertDatabaseCount('store_tracking_events', 0);
        $this->assertDatabaseCount('store_tracking_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_real_order_records_one_private_purchase_with_shared_event_id_and_authoritative_totals_on_replay(): void
    {
        $this->conversion();
        $this->conversion('tiktok', self::TIKTOK);
        $this->conversion('snapchat', self::SNAP);
        $body = ['customer_name' => 'Tracking buyer', 'customer_phone' => '01001234567', 'customer_email' => 'Tracking@Example.test', 'advertising_allowed' => true,
            'shipping_address' => ['line1' => 'Private address', 'city' => 'Cairo', 'country' => 'EG'], 'items' => [['product_id' => $this->product->id, 'quantity' => 2]], 'payment_method' => 'cod'];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $created = $this->postJson($this->public.'/checkout', $body, $headers)->assertCreated()->assertJsonPath('data.grand_total', '525.00')
            ->assertJsonPath('data.tracking.event.gross_value', '525.00')->assertJsonPath('data.tracking.event.value', '500.00');
        $event = StoreTrackingEvent::firstOrFail();
        $this->assertSame(hash('sha256', 'tracking@example.test'), $event->payload['user_data']['em']);
        $this->assertStringNotContainsString('Private address', json_encode($event->payload));
        $this->assertArrayNotHasKey('user_data', $created->json('data.tracking.event'));
        $this->assertSame($event->event_key, $created->json('data.tracking.event.event_id'));
        $this->postJson($this->public.'/checkout', $body, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.tracking.event.event_id', $event->event_key);
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseCount('store_tracking_events', 1);
        $this->assertDatabaseCount('store_tracking_deliveries', 3);
        Http::assertNothingSent();
        $this->store->fresh()->update(['tracking_configuration' => null, 'tracking_credentials' => null]);
        $this->postJson($this->public.'/checkout', $body, $headers)->assertCreated()->assertJsonPath('data.tracking', null);
        app(StoreTrackingDispatch::class)->run(50);
        Http::assertNothingSent();
        $this->assertSame(3, StoreTrackingDelivery::where('status', 'cancelled')->count());
    }

    public function test_product_pixel_override_changes_only_supported_advertising_events_and_never_order_totals(): void
    {
        $this->conversion();
        $this->product->update(['product_preferences' => ['pixel_price' => '40.00', 'is_one_page_checkout' => true]]);
        foreach ([['add_to_cart', null, '80.00'], ['view_item', null, '500.00'], ['begin_checkout', null, '500.00'], ['begin_checkout', 'product', '80.00']] as [$name, $surface, $amount]) {
            $event = $this->event(['event' => $name] + ($surface ? ['surface' => $surface] : []));
            $this->postJson($this->public.'/tracking/events', $event)->assertStatus(202)->assertJsonPath('data.event.value', $amount);
        }
        $body = ['customer_name' => 'Pixel buyer', 'customer_email' => 'pixel@example.test', 'customer_phone' => '01001234567', 'advertising_allowed' => true, 'shipping_address' => ['line1' => 'Review address', 'city' => 'Cairo', 'country' => 'EG'], 'items' => [['product_id' => $this->product->id, 'quantity' => 2]], 'payment_method' => 'cod', 'cart_mode' => 'direct'];
        $this->store->update(['tax_enabled' => true, 'tax_rate' => 10, 'tax_prices_include' => true]);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->postJson($this->public.'/checkout', $body + ['checkout_surface' => 'product'], $headers)->assertCreated()->assertJsonPath('data.grand_total', '525.00')->assertJsonPath('data.tracking.event.value', '80.00')->assertJsonPath('data.tracking.event.gross_value', '105.00');
        $this->product->update(['product_preferences' => ['pixel_price' => '1.00', 'is_one_page_checkout' => true]]);
        $this->postJson($this->public.'/checkout', $body + ['checkout_surface' => 'product'], $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.tracking.event.value', '80.00');
        $this->postJson($this->public.'/checkout', $body, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.tracking.event.value', '500.00')->assertJsonPath('data.tracking.event.gross_value', '525.00');
        $this->store->update(['tax_prices_include' => false]);
        $this->postJson($this->public.'/checkout', $body + ['checkout_surface' => 'product'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.grand_total', '575.00')->assertJsonPath('data.tracking.event.value', '2.00')->assertJsonPath('data.tracking.event.gross_value', '77.00');
        $this->assertDatabaseCount('store_orders', 3);
        $this->postJson($this->public.'/tracking/events', $this->event(), ['DNT' => '1'])->assertStatus(202)->assertJsonPath('data', null);
        Http::assertNothingSent();
    }

    public function test_active_landing_checkout_enables_product_advertising_value_without_changing_the_invoice(): void
    {
        $this->conversion();
        $this->product->update(['product_preferences' => ['pixel_price' => '40.00', 'is_one_page_checkout' => false]]);
        $body = ['customer_name' => 'Landing buyer', 'customer_email' => 'landing@example.test', 'customer_phone' => '01001234567', 'advertising_allowed' => true, 'shipping_address' => ['line1' => 'Review address', 'city' => 'Cairo', 'country' => 'EG'], 'items' => [['product_id' => $this->product->id, 'quantity' => 2]], 'payment_method' => 'cod', 'cart_mode' => 'direct', 'checkout_surface' => 'product'];
        foreach ([['standalone', 'checkout', '80.00'], ['below', 'checkout', '80.00'], ['standard', 'checkout', '500.00'], ['standalone', 'heading', '500.00']] as [$mode, $type, $value]) {
            $this->product->update(['landing_page' => ['schema' => 1, 'mode' => $mode, 'sections' => [['id' => (string) Str::uuid(), 'type' => $type, 'settings' => []]]]]);
            $this->postJson($this->public.'/tracking/events', $this->event(['event' => 'begin_checkout', 'surface' => 'product']))->assertStatus(202)->assertJsonPath('data.event.value', $value);
            $this->postJson($this->public.'/checkout', $body, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.grand_total', '525.00')->assertJsonPath('data.tracking.event.value', $value);
        }
        Http::assertNothingSent();
    }

    public function test_all_three_fixed_provider_contracts_preserve_same_event_id_and_scheduler_never_sends_twice(): void
    {
        $this->conversion();
        $this->conversion('tiktok', self::TIKTOK);
        $this->conversion('snapchat', self::SNAP);
        $this->postJson($this->public.'/tracking/events', $this->event(), ['User-Agent' => 'Own tracking review'])->assertStatus(202);
        $key = StoreTrackingEvent::firstOrFail()->event_key;
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1]), 'business-api.tiktok.com/*' => Http::response(['code' => 0]), 'tr.snapchat.com/*' => Http::response(['status' => 'VALID'])]);
        $this->artisan('store:tracking-deliver --limit=10')->assertSuccessful();
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v26.0/1234567890/events' && $r['data'][0]['event_id'] === $key && $r['data'][0]['event_name'] === 'AddToCart' && $r['data'][0]['custom_data']['value'] === 500.0);
        Http::assertSent(fn ($r) => $r->url() === 'https://business-api.tiktok.com/open_api/v1.3/event/track/' && $r->hasHeader('Access-Token', 'TEST-TOKEN-PRIVATE') && $r['data'][0]['event_id'] === $key && $r['event_source_id'] === self::TIKTOK);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://tr.snapchat.com/v3/'.self::SNAP.'/events?') && $r['data'][0]['event_id'] === $key && $r['data'][0]['action_source'] === 'WEB');
        $this->artisan('store:tracking-deliver --limit=10')->assertSuccessful();
        Http::assertSentCount(3);
        $this->assertSame(3, StoreTrackingDelivery::where('status', 'delivered')->count());
    }

    public function test_retry_lease_budget_and_token_rotation_preserve_identity_and_cancel_old_destination_generations(): void
    {
        $this->conversion();
        $this->postJson($this->public.'/tracking/events', $this->event())->assertStatus(202);
        $delivery = StoreTrackingDelivery::firstOrFail();
        $firstTime = StoreTrackingEvent::firstOrFail()->occurred_at->timestamp;
        Http::fake(function () use ($delivery) {
            $this->assertSame('skipped', app(StoreTrackingDispatch::class)->deliver($delivery->id));

            return Http::response(['private' => 'TEST-TOKEN-PRIVATE'], 503);
        });
        for ($i = 1; $i <= 5; $i++) {
            app(StoreTrackingDispatch::class)->deliver($delivery->id);
            $this->travel(2 ** $i)->minutes();
        }
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame(5, $delivery->fresh()->attempts);
        Http::assertSentCount(5);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame($firstTime, $request['data'][0]['event_time']);
        }
        $this->assertStringNotContainsString('TEST-TOKEN-PRIVATE', json_encode($delivery->fresh()->toArray()));
        $this->postJson($this->public.'/tracking/events', $this->event())->assertStatus(202);
        $new = StoreTrackingDelivery::latest('id')->firstOrFail();
        $row = $this->getJson('/api/v1/my-store/tracking/conversions', $this->auth)->assertOk()->json('data.rows.0');
        $this->putJson('/api/v1/my-store/tracking/conversions/'.$row['id'], ['version' => 2, 'provider' => 'facebook', 'pixel_id' => '1234567890', 'token' => 'NEW-TOKEN-PRIVATE', 'note' => '', 'enabled' => true], $this->auth)->assertOk();
        app(StoreTrackingDispatch::class)->deliver($new->id);
        $this->assertSame('cancelled', $new->fresh()->status);
        Http::assertSentCount(5);
    }

    public function test_populated_tracking_schema_cannot_be_dropped_and_prune_keeps_pending_delivery_payloads(): void
    {
        $this->conversion();
        $this->postJson($this->public.'/tracking/events', $this->event())->assertStatus(202);
        $migration = require database_path('migrations/2026_10_12_050000_add_store_tracking_configuration_and_deliveries.php');
        try {
            $migration->down();
            $this->fail('Preserve tracking data');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Preserve', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('store_tracking_events'));
        $this->travel(31)->days();
        $this->artisan('store:tracking-deliver --prune')->assertSuccessful();
        $this->assertDatabaseCount('store_tracking_events', 1);
        app(StoreTrackingDispatch::class)->run(50);
        $this->artisan('store:tracking-deliver --prune')->assertSuccessful();
        $this->assertDatabaseCount('store_tracking_events', 0);
        Http::assertNothingSent();
    }
}
