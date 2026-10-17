<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StorePaymentGateway;
use App\Models\User;
use App\Services\Storefront\VisitorCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Public storefront API: host resolution + per-store data, no cross-store leakage.
 */
class StorefrontApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeStore('nike', ['Air Max', 'Pegasus']);
        $this->makeStore('adidas', ['Ultraboost', 'Samba', 'Gazelle']);
    }

    private function makeStore(string $slug, array $productNames): Store
    {
        // Unified per-owner catalog: the store surfaces its owner's store-less
        // catalog (store_id = NULL, user_id = owner), resolved by ProductScope.
        $ownerId = User::factory()->create()->id;
        $store = Store::create([
            'owner_user_id' => $ownerId,
            'owner_type' => 'merchant', 'name' => ucfirst($slug), 'slug' => $slug,
            'currency' => 'USD', 'status' => 'active',
        ]);
        StoreDomain::create(['store_id' => $store->id, 'host' => "{$slug}.sellchase.com", 'type' => 'subdomain', 'is_primary' => true]);
        $cat = Category::create(['store_id' => $store->id, 'user_id' => $ownerId, 'name' => 'Shoes', 'slug' => "shoes-{$slug}", 'is_active' => true]);
        foreach ($productNames as $i => $name) {
            Product::create([
                'store_id' => $store->id, 'user_id' => $ownerId, 'category_id' => $cat->id,
                'name' => $name, 'slug' => Str::slug($name),
                'price' => 100 + $i, 'is_active' => true, 'is_featured' => true,
            ]);
        }

        return $store;
    }

    public function test_homepage_returns_only_the_resolved_store(): void
    {
        $this->getJson('http://nike.sellchase.com/api/v1/storefront')
            ->assertOk()
            ->assertJsonPath('store.slug', 'nike')
            ->assertJsonCount(2, 'homepage.featured_products');
    }

    public function test_products_are_isolated_per_host(): void
    {
        $this->getJson('http://nike.sellchase.com/api/v1/storefront/products')
            ->assertOk()->assertJsonPath('meta.total', 2);

        $this->getJson('http://adidas.sellchase.com/api/v1/storefront/products')
            ->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_product_from_another_store_is_not_reachable(): void
    {
        // 'gazelle' exists only in adidas; must 404 on nike's host.
        $this->getJson('http://nike.sellchase.com/api/v1/storefront/products/gazelle')->assertNotFound();
        $this->getJson('http://adidas.sellchase.com/api/v1/storefront/products/gazelle')->assertOk();
    }

    public function test_listing_sort_and_pagination_keep_category_and_store_scope(): void
    {
        Product::withoutGlobalScopes()->where('slug', 'air-max')->update(['rating_avg' => 5, 'rating_count' => 2, 'created_at' => '2025-01-01 00:00:00']);
        Product::withoutGlobalScopes()->where('slug', 'pegasus')->update(['rating_avg' => 3, 'rating_count' => 8, 'created_at' => '2026-01-01 00:00:00']);
        foreach (['rating' => 'air-max', 'newest' => 'pegasus', 'oldest' => 'air-max', 'price_asc' => 'air-max', 'price_desc' => 'pegasus'] as $sort => $first) {
            $this->getJson('http://nike.sellchase.com/api/v1/storefront/products?category=shoes-nike&per_page=1&sort='.$sort)
                ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.slug', $first);
        }
        $this->getJson('http://nike.sellchase.com/api/v1/storefront/products?category=shoes-nike&per_page=1&page=2&sort=price_desc')
            ->assertOk()->assertJsonPath('data.0.slug', 'air-max');
        $this->getJson('http://nike.sellchase.com/api/v1/storefront/products?sort=price;drop')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('http://nike.sellchase.com/api/v1/storefront/products?category=shoes-adidas&sort=rating')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_categories_endpoint_is_store_scoped(): void
    {
        $this->getJson('http://nike.sellchase.com/api/v1/storefront/categories')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_main_domain_has_no_store_context(): void
    {
        // localhost resolves to no store -> 404 (no cross-store data served).
        $this->getJson('http://localhost/api/v1/storefront')->assertNotFound();
        $this->getJson('http://ghost.sellchase.com/api/v1/storefront')->assertNotFound();
    }

    public function test_bootstrap_exposes_only_configured_currencies_with_valid_conversion_rates(): void
    {
        CurrencyRate::query()->create(['currency_code' => 'EUR', 'rate_to_usd' => 1.25, 'source' => 'test']);
        $store = Store::query()->where('slug', 'nike')->firstOrFail();
        $store->update(['supported_currencies' => ['USD', 'EUR', 'EGP']]);

        $this->getJson('http://nike.sellchase.com/api/v1/storefront')->assertOk()
            ->assertJsonPath('store.currency', 'USD')
            ->assertJsonPath('store.supported_currencies.0', 'USD')
            ->assertJsonPath('store.supported_currencies.1', 'EUR')
            ->assertJsonPath('store.currency_multipliers.USD', 1)
            ->assertJsonPath('store.currency_multipliers.EUR', 0.8)
            ->assertJsonMissingPath('store.currency_multipliers.EGP');
    }

    public function test_country_currency_is_private_supported_and_scoped_to_the_current_store(): void
    {
        CurrencyRate::query()->create(['currency_code' => 'EUR', 'rate_to_usd' => 1.25, 'source' => 'test']);
        Store::query()->where('slug', 'nike')->firstOrFail()->update(['supported_currencies' => ['USD', 'EUR'], 'auto_currency_by_country' => true]);
        $visitor = $this->mock(VisitorCurrency::class)->makePartial();
        $visitor->shouldReceive('country')->once()->andReturn('DE');
        $visitor->shouldReceive('rates')->once()->andReturn(['USD' => 1, 'EUR' => 0.9, 'AED' => 3.67]);

        $this->getJson('http://nike.sellchase.com/api/v1/storefront')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('store.visitor_currency', 'EUR')
            ->assertJsonPath('store.currency_multipliers.EUR', 0.9)
            ->assertJsonMissingPath('store.currency_multipliers.AED');
        $this->getJson('http://adidas.sellchase.com/api/v1/storefront')->assertOk()
            ->assertJsonPath('store.auto_currency_by_country', false)
            ->assertJsonPath('store.visitor_currency', 'USD');
    }

    public function test_bootstrap_payment_methods_include_only_enabled_methods_for_this_store(): void
    {
        foreach (['nike', 'adidas'] as $slug) {
            $store = Store::query()->where('slug', $slug)->firstOrFail();
            StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => $slug === 'nike' ? 'cod' : 'fawaterak', 'enabled' => true, 'credentials' => [], 'sort_order' => 0]);
        }
        StorePaymentGateway::create(['store_id' => Store::query()->where('slug', 'nike')->value('id'), 'gateway' => 'stripe', 'enabled' => false, 'credentials' => [], 'sort_order' => 1]);
        $this->getJson('http://nike.sellchase.com/api/v1/storefront')->assertOk()->assertJsonPath('store.payment_methods', ['cod']);
    }

    public function test_checkout_methods_exclude_incomplete_and_unimplemented_connections_and_expose_custom_presentation(): void
    {
        $store = Store::where('slug', 'nike')->firstOrFail();
        StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => 'stripe', 'enabled' => true, 'credentials' => ['secret_key' => 'incomplete']]);
        StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => 'taager', 'enabled' => true, 'credentials' => ['api_key' => 'synthetic']]);
        StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => 'cod', 'enabled' => true, 'presentation' => ['name' => 'الدفع عند التسليم', 'description' => 'وصف الدفع']]);
        $id = Product::withoutGlobalScopes()->where('store_id', $store->id)->value('id');
        $this->getJson('http://nike.sellchase.com/api/v1/storefront/payment-methods?product_ids[]='.$id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'cod')->assertJsonPath('data.0.name', 'الدفع عند التسليم')->assertJsonPath('data.0.description', 'وصف الدفع')->assertJsonPath('data.0.logo_url', '/payments/cod.png');
    }
}
