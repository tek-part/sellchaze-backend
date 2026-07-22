<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
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
        $store = Store::create([
            'owner_user_id' => User::factory()->create()->id,
            'owner_type' => 'merchant', 'name' => ucfirst($slug), 'slug' => $slug,
            'currency' => 'USD', 'status' => 'active',
        ]);
        StoreDomain::create(['store_id' => $store->id, 'host' => "{$slug}.sellchase.com", 'type' => 'subdomain', 'is_primary' => true]);
        $cat = Category::create(['store_id' => $store->id, 'name' => 'Shoes', 'slug' => 'shoes', 'is_active' => true]);
        foreach ($productNames as $i => $name) {
            Product::create([
                'store_id' => $store->id, 'category_id' => $cat->id,
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
}
