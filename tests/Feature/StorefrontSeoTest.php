<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\Storefront\StoreSeoService;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontSeoTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private StoreSeoService $seo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seo = new StoreSeoService;
        $this->store = Store::create([
            'owner_user_id' => User::factory()->create()->id,
            'owner_type' => 'merchant', 'name' => 'Nike', 'slug' => 'nike',
            'description' => 'Just do it', 'currency' => 'USD', 'status' => 'active',
        ]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'nike.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
    }

    public function test_store_seo_has_all_metadata(): void
    {
        $seo = $this->seo->forStore($this->store);

        $this->assertSame('Nike', $seo['title']);
        $this->assertSame('Just do it', $seo['description']);
        $this->assertSame('https://nike.sellchase.com/', $seo['canonical']);
        $this->assertSame('index, follow', $seo['robots']);
        $this->assertSame('Store', $seo['json_ld']['@type']);
        $this->assertArrayHasKey('og:title', $seo['og']);
        $this->assertArrayHasKey('twitter:card', $seo['twitter']);
    }

    public function test_custom_site_title_and_favicon_apply_to_product_metadata_without_renaming_business(): void
    {
        $this->store->update(['site_title' => 'Online catalog', 'favicon' => 'stores/1/icons/site.png']);
        $home = $this->seo->forStore($this->store);
        $this->assertSame('Online catalog', $home['title']);
        $this->assertSame('Nike', $home['json_ld']['name']);
        $product = new Product(['name' => 'Air Max', 'slug' => 'air-max', 'price' => 100, 'is_active' => true]);
        $seo = $this->seo->forProduct($this->store, $product);
        $this->assertSame('Air Max — Online catalog', $seo['title']);
        $this->assertSame('Online catalog', $seo['og']['og:site_name']);
        $this->assertStringContainsString('/icons/site.png', $seo['favicon_url']);
    }

    public function test_product_seo_includes_offer_structured_data(): void
    {
        $product = Product::create([
            'user_id' => $this->store->owner_user_id, 'name' => 'Air Max', 'slug' => 'air-max',
            'price' => 199.99, 'is_active' => true,
        ]);

        $seo = $this->seo->forProduct($this->store, $product);

        $this->assertSame('https://nike.sellchase.com/products/air-max', $seo['canonical']);
        $this->assertSame('Product', $seo['json_ld']['@type']);
        $this->assertSame('199.99', $seo['json_ld']['offers']['price']);
        $this->assertSame('USD', $seo['json_ld']['offers']['priceCurrency']);
    }

    public function test_category_seo_and_sitemap_and_robots(): void
    {
        Category::create(['user_id' => $this->store->owner_user_id, 'name' => 'Shoes', 'slug' => 'shoes', 'is_active' => true]);
        Product::create(['user_id' => $this->store->owner_user_id, 'name' => 'Air Max', 'slug' => 'air-max', 'price' => 10, 'is_active' => true]);

        $category = Category::query()->first();
        $catSeo = $this->seo->forCategory($this->store, $category);
        $this->assertSame('CollectionPage', $catSeo['json_ld']['@type']);
        $this->assertSame('https://nike.sellchase.com/categories/shoes', $catSeo['canonical']);

        $sitemap = $this->seo->sitemap($this->store);
        $this->assertStringContainsString('https://nike.sellchase.com/products/air-max', $sitemap);
        $this->assertStringContainsString('https://nike.sellchase.com/categories/shoes', $sitemap);

        $robots = $this->seo->robots($this->store);
        $this->assertStringContainsString('Sitemap: https://nike.sellchase.com/sitemap.xml', $robots);
        $this->assertStringContainsString('Allow: /', $robots);
    }

    public function test_rich_product_copy_is_plain_text_in_search_metadata(): void
    {
        $product = Product::create(['name' => 'Bag', 'slug' => 'bag', 'price' => 10, 'description' => '<h2>Quality</h2><p>Soft &amp; <strong>durable</strong></p>']);
        $seo = $this->seo->forProduct($this->store, $product);
        $this->assertSame('Quality Soft & durable', $seo['description']);
        $this->assertSame($seo['description'], $seo['json_ld']['description']);
        $this->assertSame($seo['description'], $seo['og']['og:description']);
    }
}
