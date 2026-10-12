<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StorePaymentGateway;
use App\Models\User;
use App\Services\Commerce\CatalogItemId;
use App\Services\Commerce\ProductFeeds;
use App\Services\Commerce\StoreTrackingProvider;
use App\Services\Commerce\StoreTrackingSettings;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private array $auth;

    private string $public = 'http://catalog.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Catalog', 'slug' => 'catalog', 'status' => 'active', 'currency' => 'EGP', 'default_locale' => 'en']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'catalog.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Catalog bag', 'slug' => 'catalog-bag', 'price' => 250, 'compare_price' => 300, 'manufacturer' => 'Example brand', 'image' => 'https://images.example.com/bag.png', 'description' => '<p>Bag &amp; luggage</p>', 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 10, 'reserved_quantity' => 2, 'barcode' => '4006381333931']);
    }

    private function settings(array $replace = []): array
    {
        return array_replace(['version' => 1, 'enabled' => true, 'email' => 'business@example.test', 'phone' => '+201001234567', 'address' => 'Named test business address', 'cod_disabled' => false,
            'policies' => ['shipping' => ['title' => 'Shipping policy', 'content' => '<p>Named shipping text.</p>'], 'returns' => ['title' => 'Return policy', 'content' => '<p>Named return text.</p>'], 'cod' => ['title' => 'COD policy', 'content' => '<p>Named cash text.</p>']]], $replace);
    }

    public function test_defaults_are_unpublished_and_private_settings_require_permission_and_tenant(): void
    {
        $this->getJson('/api/v1/my-store/google-merchant', $this->auth)->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.version', 1);
        $this->getJson($this->public)->assertOk()->assertJsonPath('store.merchant_information', null);
        $this->assertNull($this->store->fresh()->google_merchant_configuration);
        $this->putJson('/api/v1/my-store/google-merchant', $this->settings(['phone' => '--']), $this->auth)->assertUnprocessable();
        $this->assertArrayNotHasKey('google_merchant_configuration', $this->store->toArray());
        $this->getJson('/api/v1/my-store/google-merchant')->assertUnauthorized();
        $stranger = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $stranger->assignRole('Merchant');
        $auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($stranger)];
        $this->putJson('/api/v1/stores/'.$this->store->id.'/google-merchant', $this->settings(), $auth)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_actual_enabled_settings_publish_sanitized_contact_and_three_policy_navigation_links(): void
    {
        $settings = $this->settings();
        $settings['policies']['shipping']['content'] = '<p style="text-align:center;color:#123456" onclick="alert(1)">Shipping</p><script>bad()</script><a href="javascript:alert(1)">bad link</a>';
        $result = $this->putJson('/api/v1/my-store/google-merchant', $settings, $this->auth)->assertOk()->assertJsonPath('data.version', 2)->json('data');
        $this->assertStringNotContainsString('onclick', $result['policies']['shipping']['content']);
        $this->assertStringNotContainsString('javascript:', $result['policies']['shipping']['content']);
        $this->assertStringNotContainsString('<script', $result['policies']['shipping']['content']);
        $public = $this->getJson($this->public)->assertOk()->json();
        $this->assertSame($settings['email'], $public['store']['merchant_information']['email']);
        $this->assertCount(3, $public['store']['merchant_information']['policies']);
        $this->assertSame(['/policies/shipping', '/policies/returns', '/policies/cod'], array_column($public['navigation']['footer'], 'url'));
        $this->assertDatabaseCount('store_pages', 0); // Existing page/publication records are not overwritten.
        $this->assertDatabaseCount('store_page_publications', 0);
        Http::assertNothingSent();
    }

    public function test_validation_stale_edits_cod_toggle_and_disabling_do_not_destroy_authored_content(): void
    {
        $data = $this->settings(['email' => '', 'address' => '']);
        $data['policies']['returns']['content'] = '<p>&nbsp;</p>';
        $this->putJson('/api/v1/my-store/google-merchant', $data, $this->auth)->assertUnprocessable();
        $this->assertNull($this->store->fresh()->google_merchant_configuration);
        $this->putJson('/api/v1/my-store/google-merchant', $this->settings(), $this->auth)->assertOk();
        $this->putJson('/api/v1/my-store/google-merchant', $this->settings(['enabled' => false]), $this->auth)->assertConflict();
        $this->putJson('/api/v1/my-store/google-merchant', $this->settings(['version' => 2, 'cod_disabled' => true]), $this->auth)->assertOk();
        $this->assertCount(2, $this->getJson($this->public)->assertOk()->json('store.merchant_information.policies'));
        $this->putJson('/api/v1/my-store/google-merchant', $this->settings(['version' => 3, 'enabled' => false]), $this->auth)->assertOk();
        $this->getJson($this->public)->assertOk()->assertJsonPath('store.merchant_information', null);
        $this->assertSame('COD policy', $this->store->fresh()->google_merchant_configuration['policies']['cod']['title']);
    }

    public function test_four_download_formats_have_correct_headers_prices_stock_gtin_and_no_private_fields(): void
    {
        foreach (ProductFeeds::CHANNELS as $channel) {
            $response = $this->get($this->public.'/product-feed/'.$channel)->assertOk();
            $csv = $response->streamedContent();
            $delimiter = $channel === 'google' ? "\t" : ',';
            $lines = array_values(array_filter(explode("\r\n", substr($csv, 3))));
            $headers = str_getcsv($lines[0], $delimiter, '"', '');
            $values = str_getcsv($lines[1], $delimiter, '"', '');
            $row = array_combine($headers, $values);
            $this->assertSame((string) $this->product->id, $row[$channel === 'tiktok' ? 'sku_id' : 'id']);
            $this->assertSame('300.00 EGP', $row['price']);
            $this->assertSame('250.00 EGP', $row['sale_price']);
            $this->assertSame($channel === 'google' ? 'in_stock' : 'in stock', $row['availability']);
            $this->assertSame('4006381333931', $row['gtin']);
            $this->assertSame('Bag & luggage', $row['description']);
            $this->assertStringContainsString('/products/catalog-bag', $row['link']);
            foreach (['cost', 'digital_url', 'supplier_code', 'customer_phone', 'tracking_credentials'] as $private) {
                $this->assertArrayNotHasKey($private, $row);
            }
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('carts', 0);
        $this->assertSame(2, $this->product->fresh()->reserved_quantity);
        Http::assertNothingSent();
    }

    public function test_all_variant_skus_use_real_overrides_images_reservations_and_matching_advertising_ids(): void
    {
        $variant = ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $this->product->id, 'name' => 'Red / M', 'price_override' => 200, 'compare_price' => null, 'image' => 'https://images.example.com/red.png', 'options' => ['Color' => 'Red', 'Size' => 'M'], 'barcode' => '4006381333931', 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 3, 'reserved_quantity' => 3]);
        $inactive = ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $this->product->id, 'name' => 'Inactive', 'price_override' => 150, 'is_active' => false]);
        $rows = iterator_to_array(app(ProductFeeds::class)->rows($this->store, 'meta', 'en'), false);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(CatalogItemId::selection($this->product->id, $variant->id), $row['id']);
        $this->assertSame((string) $this->product->id, $row['item_group_id']);
        $this->assertSame('200.00 EGP', $row['sale_price']);
        $this->assertSame('out of stock', $row['availability']);
        $this->assertSame('Red', $row['color']);
        $this->assertSame('M', $row['size']);
        $this->assertStringEndsWith('?variant='.$variant->id, $row['link']);
        app(StoreTrackingSettings::class)->pixels($this->store, ['version' => 1, 'facebook' => ['1234567890'], 'tiktok' => [], 'snapchat' => []]);
        $result = $this->postJson($this->public.'/tracking/events', ['event_id' => (string) Str::uuid(), 'event' => 'add_to_cart', 'advertising_allowed' => true, 'items' => [['product_id' => $this->product->id, 'variant_id' => $variant->id, 'quantity' => 1]]])->assertStatus(202)->json('data.event');
        $this->assertSame($row['id'], $result['items'][0]['item_id']);
        $this->assertSame('200.00', $result['value']);
        $view = $this->postJson($this->public.'/tracking/events', ['event_id' => (string) Str::uuid(), 'event' => 'view_item', 'advertising_allowed' => true, 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]])->assertStatus(202)->json('data.event');
        $this->assertSame('product_group', $view['content_type']);
        $this->assertSame($row['item_group_id'], $view['items'][0]['item_id']);
    }

    public function test_private_export_and_public_feed_cannot_leak_other_stores_or_drafts_or_invalid_images(): void
    {
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other', 'currency' => 'USD', 'status' => 'active']);
        Product::create(['store_id' => $other->id, 'name' => 'Foreign secret catalog', 'slug' => 'foreign', 'price' => 10, 'image' => 'https://images.example.com/foreign.png', 'is_active' => true]);
        Product::create(['store_id' => $this->store->id, 'name' => 'Draft', 'slug' => 'draft', 'price' => 10, 'image' => 'https://images.example.com/draft.png', 'is_active' => false]);
        Product::create(['store_id' => $this->store->id, 'name' => 'Invalid image', 'slug' => 'bad', 'price' => 10, 'image' => 'javascript:alert(1)', 'is_active' => true]);
        Product::create(['store_id' => $this->store->id, 'name' => 'Invalid landing slug', 'slug' => 'bad_slug', 'price' => 10, 'manufacturer' => 'Example brand', 'image' => 'https://images.example.com/bag.png', 'is_active' => true]);
        $this->getJson('/api/v1/my-store/product-feed?channel=meta', $this->auth)->assertOk()->assertJsonPath('data.channel', 'meta');
        $this->getJson('/api/v1/my-store/product-feed?channel=evil', $this->auth)->assertUnprocessable();
        $this->getJson('/api/v1/my-store/product-feed?channel=meta')->assertUnauthorized();
        $csv = $this->get('/api/v1/my-store/product-feed/download?channel=meta', $this->auth)->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Foreign secret', $csv);
        $this->assertStringNotContainsString('Draft', $csv);
        $this->assertStringNotContainsString('javascript:', $csv);
        $this->assertStringNotContainsString('Invalid landing slug', $csv);
        $this->getJson('http://unknown.example.test/api/v1/storefront/product-feed/meta')->assertNotFound();
        $this->getJson($this->public.'/product-feed/evil')->assertNotFound();
    }

    public function test_accepted_variant_order_uses_the_same_catalog_id_and_price_in_browser_and_server_purchase(): void
    {
        $variant = ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $this->product->id, 'name' => 'Blue', 'price_override' => 190, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 5]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true, 'test_mode' => true, 'credentials' => []]);
        $this->store->update(['shipping_enabled' => false]);
        app(StoreTrackingSettings::class)->pixels($this->store, ['version' => 1, 'facebook' => ['1234567890'], 'tiktok' => [], 'snapchat' => []]);
        $body = ['customer_name' => 'Catalog buyer', 'customer_phone' => '01001234567', 'customer_email' => 'catalog@example.test', 'advertising_allowed' => true, 'shipping_address' => ['line1' => 'Local test address', 'city' => 'Cairo', 'country' => 'EG'],
            'items' => [['product_id' => $this->product->id, 'variant_id' => $variant->id, 'quantity' => 1]], 'payment_method' => 'cod'];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $response = $this->postJson($this->public.'/checkout', $body, $headers)->assertCreated();
        $event = $response->json('data.tracking.event');
        $row = iterator_to_array(app(ProductFeeds::class)->rows($this->store, 'meta', 'en'), false)[0];
        $this->assertSame($row['id'], $event['items'][0]['item_id']);
        $this->assertSame('190.00', $event['items'][0]['price']);
        $this->assertSame('190.00 EGP', $row['sale_price']);
        foreach (['facebook', 'tiktok', 'snapchat'] as $provider) {
            $data = app(StoreTrackingProvider::class)->body($provider, $event + ['user_data' => []], now()->timestamp, '1234567890');
            $id = $provider === 'tiktok' ? $data['data'][0]['properties']['contents'][0]['content_id'] : $data['data'][0]['custom_data']['content_ids'][0];
            $this->assertSame($row['id'], $id);
        }
        $this->postJson($this->public.'/checkout', $body, $headers)->assertCreated()->assertJsonPath('data.tracking.event.event_id', $event['event_id']);
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseCount('store_tracking_events', 1);
        $this->assertSame(1, $variant->fresh()->reserved_quantity);
        Http::assertNothingSent();
    }

    public function test_stream_encoding_csv_injection_current_price_changes_and_image_channel_requirements(): void
    {
        $this->product->update(['name' => '=HYPERLINK("https://evil.test")', 'description' => '<p>Comma, quote " and newline</p>', 'barcode' => '0000000000000']);
        $csv = $this->get($this->public.'/product-feed/meta')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $row = iterator_to_array(app(ProductFeeds::class)->rows($this->store, 'meta', 'en'), false)[0];
        $this->assertSame('', $row['gtin']);
        $this->product->update(['price' => 180, 'image' => 'https://images.example.com/changed.webp', 'stock_quantity' => 2]);
        $meta = iterator_to_array(app(ProductFeeds::class)->rows($this->store, 'meta', 'en'), false)[0];
        $this->assertSame('180.00 EGP', $meta['sale_price']);
        $this->assertSame('out of stock', $meta['availability']);
        $this->assertSame([], iterator_to_array(app(ProductFeeds::class)->rows($this->store, 'tiktok', 'en'), false));
        Http::assertNothingSent();
    }

    public function test_translation_quantity_one_offer_and_populated_rollback_guard(): void
    {
        $this->product->update(['translations' => ['name' => ['en' => 'Catalog bag', 'ar' => 'حقيبة محلية']], 'quantity_offers' => [['id' => (string) Str::uuid(), 'enabled' => true, 'quantity' => 1, 'unit_price' => '210.00', 'free_shipping' => false, 'title' => ['en' => 'Single', 'ar' => 'قطعة'], 'subtitle' => ['en' => '', 'ar' => '']]]]);
        $row = iterator_to_array(app(ProductFeeds::class)->rows($this->store, 'google', 'ar'), false)[0];
        $this->assertSame('حقيبة محلية', $row['title']);
        $this->assertSame('210.00 EGP', $row['sale_price']);
        $this->putJson('/api/v1/my-store/google-merchant', $this->settings(), $this->auth)->assertOk();
        $migration = require database_path('migrations/2026_10_12_060000_add_google_merchant_configuration.php');
        try {
            $migration->down();
            $this->fail('Populated rollback must refuse');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Preserve', $error->getMessage());
        }
        $this->assertTrue($this->store->fresh()->google_merchant_configuration['enabled']);
    }
}
