<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 2: host-agnostic storefront routing. The same layer must serve a
 * subdomain (nike.sellchase.com) and a custom domain (apple-store.com), and
 * return a storefront 404 for unknown hosts.
 */
class StorefrontRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeStore('Nike', 'nike', 'nike.sellchase.com', 'subdomain');
        $this->makeStore('Apple', 'apple', 'apple-store.com', 'custom'); // custom domain
    }

    private function makeStore(string $name, string $slug, string $host, string $type): Store
    {
        $store = Store::create([
            'owner_user_id' => User::factory()->create()->id,
            'owner_type' => 'merchant', 'name' => $name, 'slug' => $slug,
            'currency' => 'USD', 'status' => 'active',
        ]);
        // Custom domains only serve once ownership is verified, so the fixture
        // reflects a fully connected domain.
        StoreDomain::create([
            'store_id' => $store->id,
            'host' => $host,
            'type' => $type,
            'status' => StoreDomain::STATUS_VERIFIED,
            'is_primary' => true,
        ]);
        $cat = Category::create(['store_id' => $store->id, 'name' => 'Shoes', 'slug' => 'shoes', 'is_active' => true]);
        Product::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => "{$name} One", 'slug' => 'one', 'price' => 10, 'is_active' => true]);

        return $store;
    }

    public function test_main_domain_root_serves_app_welcome(): void
    {
        $this->get('http://localhost/')->assertOk()->assertJson(['app' => 'Sellchase API']);
    }

    public function test_subdomain_host_renders_storefront(): void
    {
        $this->get('http://nike.sellchase.com/')->assertOk()->assertSee('Nike');
    }

    public function test_custom_domain_host_renders_same_layer(): void
    {
        // Proves the routing is host-agnostic: a non-subdomain custom host renders.
        $this->get('http://apple-store.com/')->assertOk()->assertSee('Apple');
    }

    public function test_unknown_host_root_returns_404(): void
    {
        $this->get('http://ghost.sellchase.com/')->assertNotFound();
        $this->get('http://evil.com/')->assertNotFound();
    }

    public function test_storefront_paths_404_on_unknown_hosts(): void
    {
        $this->get('http://nike.sellchase.com/products')->assertOk();
        $this->get('http://ghost.sellchase.com/products')->assertNotFound();
    }

    public function test_sitemap_and_robots_render_per_store(): void
    {
        $this->get('http://nike.sellchase.com/sitemap.xml')
            ->assertOk()->assertSee('https://nike.sellchase.com/products/one');
        $this->get('http://nike.sellchase.com/robots.txt')
            ->assertOk()->assertSee('Sitemap: https://nike.sellchase.com/sitemap.xml');
    }
}
