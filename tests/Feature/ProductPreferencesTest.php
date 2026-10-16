<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Commerce\ProductFeeds;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private array $auth;

    private string $base = '/api/v1/my-store/catalog/products';

    private string $public = 'http://preferences.sellchase.com/api/v1/storefront';

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
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Preferences', 'slug' => 'preferences', 'currency' => 'EGP', 'status' => 'active', 'shipping_enabled' => true, 'shipping_flat_rate' => 35, 'shipping_free_over' => 10000]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'preferences.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
    }

    private function create(array $extra = []): array
    {
        return $this->postJson($this->base, $extra + ['name' => 'Preference product', 'price' => 100, 'is_active' => true], $this->auth)->assertCreated()->json('data');
    }

    public function test_preferences_are_validated_versioned_and_reset_without_touching_stock_or_other_fields(): void
    {
        $p = $this->create(['product_preferences' => ['is_skip_cart' => true, 'is_fixed_bottom_buy' => false, 'pixel_price' => 0, 'fake_visitors_min' => 10, 'fake_visitors_max' => 30], 'display_priority' => 9]);
        $this->assertTrue($p['product_preferences']['is_skip_cart']);
        $this->assertSame('0.00', $p['product_preferences']['pixel_price']);
        $this->putJson($this->base.'/'.$p['id'], ['price' => 105], $this->auth)->assertOk()->assertJsonPath('data.preferences_version', $p['preferences_version']);
        $this->putJson($this->base.'/'.$p['id'], ['product_preferences' => ['is_skip_cart' => false]], $this->auth)->assertConflict();
        $changed = $this->putJson($this->base.'/'.$p['id'], ['preferences_version' => $p['preferences_version'], 'product_preferences' => ['is_reviews_enabled' => false], 'display_priority' => 4], $this->auth)->assertOk()->json('data');
        $this->putJson($this->base.'/'.$p['id'], ['preferences_version' => $p['preferences_version'], 'catalog_hidden' => true], $this->auth)->assertConflict();
        $this->putJson($this->base.'/'.$p['id'], ['preferences_version' => $changed['preferences_version'], 'product_preferences' => null], $this->auth)->assertOk()->assertJsonPath('data.product_preferences', null)->assertJsonPath('data.price', '105.00');
        $this->getJson($this->public.'/products/'.$p['slug'])->assertOk()->assertJsonMissingPath('data.preferences_version')->assertJsonMissingPath('data.product_preferences.pixel_price');
    }

    public function test_invalid_ranges_unknown_fields_and_dependency_errors_cannot_partially_save(): void
    {
        foreach ([['fake_visitors_min' => 80, 'fake_visitors_max' => 20], ['fake_timer_hours' => 0], ['is_one_page_checkout' => true, 'is_free_shipping' => ['bad']], ['script' => '<script>'], ['pixel_price' => -1], ['is_checkout_before_description' => true]] as $preferences) {
            $this->postJson($this->base, ['name' => 'Invalid', 'product_preferences' => $preferences], $this->auth)->assertUnprocessable();
        }
        $this->assertDatabaseCount('products', 0);
    }

    public function test_hidden_products_stay_directly_purchasable_but_leave_lists_counts_and_sitemap_and_priority_preserves_legacy_ties(): void
    {
        $first = $this->create(['slug' => 'first', 'position' => 1]);
        $second = $this->create(['slug' => 'second', 'position' => 0]);
        $priority = $this->create(['slug' => 'priority', 'display_priority' => 9]);
        $hidden = $this->create(['slug' => 'hidden', 'display_priority' => 50, 'catalog_hidden' => true]);
        $this->getJson($this->public.'/products')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.id', $priority['id'])->assertJsonPath('data.1.id', $second['id'])->assertJsonPath('data.2.id', $first['id']);
        $this->getJson($this->public.'/products/hidden')->assertOk()->assertJsonPath('data.id', $hidden['id']);
        $this->postJson($this->public.'/cart/catalog', ['product_ids' => [$hidden['id']]])->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->base, $this->auth)->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('data.0.id', $hidden['id']);
        $this->get('http://preferences.sellchase.com/sitemap.xml')->assertOk()->assertDontSee('/products/hidden', false)->assertSee('/products/priority', false);
        $category = Category::create(['store_id' => $this->store->id, 'name' => 'Preferences category', 'slug' => 'preferences-category', 'is_active' => true]);
        Product::withoutGlobalScopes()->whereIn('id', [$priority['id'], $hidden['id']])->update(['category_id' => $category->id, 'image' => 'https://images.example.test/preferences.png']);
        $this->assertSame(1, Category::withoutGlobalScopes()->withCatalogProductCount(true)->findOrFail($category->id)->products_count);
        $this->assertSame(2, Category::withoutGlobalScopes()->withCatalogProductCount(false)->findOrFail($category->id)->products_count);
        $rows = iterator_to_array(app(ProductFeeds::class)->rows($this->store, 'google', 'en'));
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('/products/priority', $rows[0]['link']);

    }

    public function test_product_shipping_is_server_authoritative_for_quotes_orders_and_mixed_baskets_and_old_order_replay(): void
    {
        $free = $this->create(['product_preferences' => ['is_free_shipping' => true]]);
        $paid = $this->create();
        $one = ['product_id' => $free['id'], 'quantity' => 1];
        $two = ['product_id' => $paid['id'], 'quantity' => 1, 'is_free_shipping' => true];
        $this->postJson($this->public.'/checkout/quote', ['items' => [$one]])->assertOk()->assertJsonPath('data.product_free_shipping', true)->assertJsonPath('data.totals.shipping_total', '0.00');
        $this->postJson($this->public.'/checkout/quote', ['items' => [$one, $two]])->assertOk()->assertJsonPath('data.product_free_shipping', false)->assertJsonPath('data.totals.shipping_total', '35.00');
        $body = ['customer_name' => 'Preference reader', 'customer_email' => 'preference@example.test', 'customer_phone' => '01001234567', 'shipping_address' => ['line1' => 'Review address', 'city' => 'Cairo', 'country' => 'EG'], 'cart_mode' => 'direct', 'items' => [$one]];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $created = $this->postJson($this->public.'/checkout', $body, $headers)->assertCreated();
        $order = StoreOrder::withoutGlobalScopes()->findOrFail($created->json('data.id'));
        $this->assertSame('0.00', $order->shipping_total);
        $this->assertTrue($order->shipping_address['shipping_details']['product_free_shipping']);
        $this->assertSame([$free['id']], $order->shipping_address['shipping_details']['free_shipping_product_ids']);
        $this->putJson($this->base.'/'.$free['id'], ['product_preferences' => ['is_free_shipping' => false], 'preferences_version' => $free['preferences_version']], $this->auth)->assertOk();
        $this->postJson($this->public.'/checkout/quote', ['items' => [$one]])->assertOk()->assertJsonPath('data.totals.shipping_total', '35.00');
        $this->postJson($this->public.'/checkout', $body, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame('0.00', $order->fresh()->shipping_total);
    }

    public function test_defaults_do_not_rewrite_legacy_rows_and_configured_migration_cannot_be_rolled_back(): void
    {
        $legacy = $this->create(['position' => 3]);
        $this->assertNull($legacy['product_preferences']);
        $this->assertSame(0, $legacy['display_priority']);
        $this->assertFalse($legacy['catalog_hidden']);
        $first = $this->putJson($this->base.'/'.$legacy['id'], ['preferences_version' => $legacy['preferences_version'], 'product_preferences' => ['is_skip_cart' => true, 'is_free_shipping' => false]], $this->auth)->assertOk()->json('data');
        $this->putJson($this->base.'/'.$legacy['id'], ['preferences_version' => $first['preferences_version'], 'product_preferences' => ['is_free_shipping' => false, 'is_skip_cart' => true]], $this->auth)->assertOk()->assertJsonPath('data.preferences_version', $first['preferences_version'])->assertJsonPath('data.position', 3);
        $migration = require database_path('migrations/2026_10_16_020000_add_product_preferences.php');
        try {
            $migration->down();
            $this->fail('Configured product settings must be preserved.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Preserve configured', $error->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('products', 'product_preferences'));
        $stranger = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->putJson('/api/v1/stores/'.$this->store->id.'/catalog/products/'.$legacy['id'], ['product_preferences' => ['is_skip_cart' => false], 'preferences_version' => $first['preferences_version']], ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($stranger)])->assertForbidden();
        $this->getJson($this->base.'/'.$legacy['id'])->assertUnauthorized();
    }

    public function test_review_visibility_keeps_stored_reviews_and_can_be_restored(): void
    {
        $p = $this->create(['product_preferences' => ['is_reviews_enabled' => false], 'slug' => 'reviewed']);
        app(CurrentStore::class)->set($this->store);
        $customer = StoreCustomer::create(['name' => 'Review reader', 'email' => 'review@example.test', 'password' => bcrypt('TestOnly123!')]);
        ProductReview::create(['store_customer_id' => $customer->id, 'store_product_id' => $p['id'], 'author_name' => 'Review reader', 'rating' => 5, 'body' => 'Existing review', 'status' => 'approved']);
        $this->getJson($this->public.'/products/reviewed/reviews')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.count', 0);
        $this->assertDatabaseCount('product_reviews', 1);
        $this->putJson($this->base.'/'.$p['id'], ['preferences_version' => $p['preferences_version'], 'product_preferences' => ['is_reviews_enabled' => true]], $this->auth)->assertOk();
        $this->getJson($this->public.'/products/reviewed/reviews')->assertOk()->assertJsonCount(1, 'data');
    }
}
