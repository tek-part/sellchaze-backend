<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StorePage;
use App\Models\StorePageSection;
use App\Models\StoreReusableSection;
use App\Models\User;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end theme runtime: context API + rendered HTML (Blade fallback path,
 * which is the always-on server-rendered output). Covers Task 4 (context shape),
 * Task 8 (SSR SEO), and tenant isolation.
 */
class StorefrontRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ThemeRegistry::class)->registerFromFile(resource_path('themes/default/theme.json'));
        $this->makeStore('Nike', 'nike', ['Air Max', 'Pegasus']);
        $this->makeStore('Adidas', 'adidas', ['Ultraboost', 'Samba']);
    }

    private function makeStore(string $name, string $slug, array $products): Store
    {
        $store = Store::create([
            'owner_user_id' => User::factory()->create()->id,
            'owner_type' => 'merchant', 'name' => $name, 'slug' => $slug,
            'currency' => 'USD', 'status' => 'active',
        ]);
        StoreDomain::create(['store_id' => $store->id, 'host' => "{$slug}.sellchase.com", 'type' => 'subdomain', 'is_primary' => true]);
        $cat = Category::create(['store_id' => $store->id, 'name' => 'Shoes', 'slug' => 'shoes', 'is_active' => true]);
        foreach ($products as $i => $p) {
            Product::create([
                'store_id' => $store->id, 'category_id' => $cat->id,
                'name' => $p, 'slug' => Str::slug($p), 'price' => 100 + $i,
                'is_active' => true, 'is_featured' => true,
            ]);
        }
        app(StoreThemeService::class)->installAndActivateDefault($store);

        return $store;
    }

    public function test_context_api_returns_theme_page_and_data(): void
    {
        $this->getJson('http://nike.sellchase.com/api/v1/storefront/context')
            ->assertOk()
            ->assertJsonPath('store.slug', 'nike')
            ->assertJsonPath('theme.key', 'default')
            ->assertJsonPath('page.template', 'home')
            ->assertJsonStructure(['store', 'seo', 'theme' => ['settings'], 'page' => ['sections'], 'data']);
    }

    public function test_context_api_is_tenant_isolated(): void
    {
        $body = $this->getJson('http://nike.sellchase.com/api/v1/storefront/context')->json('data.products');
        $names = array_column($body, 'name');
        $this->assertContains('Air Max', $names);
        $this->assertNotContains('Ultraboost', $names); // no cross-store leak
    }

    public function test_context_api_product_and_category_templates(): void
    {
        $this->getJson('http://nike.sellchase.com/api/v1/storefront/context?template=product&slug=air-max')
            ->assertOk()->assertJsonPath('page.template', 'product')->assertJsonPath('data.product.slug', 'air-max');

        $this->getJson('http://nike.sellchase.com/api/v1/storefront/context?template=product&slug=ghost')
            ->assertNotFound();

        $this->getJson('http://nike.sellchase.com/api/v1/storefront/context?template=category&slug=shoes')
            ->assertOk()->assertJsonPath('data.category.slug', 'shoes');
    }

    public function test_rendered_home_html_contains_all_seo_tags_server_side(): void
    {
        $html = $this->get('http://nike.sellchase.com/')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Nike</title>', $html);
        $this->assertStringContainsString('name="description"', $html);
        $this->assertStringContainsString('rel="canonical"', $html);
        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('name="twitter:card"', $html);
        $this->assertStringContainsString('application/ld+json', $html);
        // theme-driven sections rendered
        $this->assertStringContainsString('data-section="hero"', $html);
        $this->assertStringContainsString('data-section="product-grid"', $html);
        $this->assertStringContainsString('data-rendered-by="blade"', $html); // fallback path (SSR off in tests)
    }

    public function test_rendered_product_and_category_pages(): void
    {
        $this->get('http://nike.sellchase.com/products/air-max')
            ->assertOk()->assertSee('data-section="product-details"', false)->assertSee('Air Max');

        $this->get('http://nike.sellchase.com/categories/shoes')
            ->assertOk()->assertSee('data-section="category-header"', false);
    }

    public function test_rendered_html_is_tenant_isolated(): void
    {
        $html = $this->get('http://nike.sellchase.com/')->getContent();
        $this->assertStringContainsString('Air Max', $html);
        $this->assertStringNotContainsString('Ultraboost', $html);
    }

    // ---- Task 5: SSR resiliency (SSR-on path + fail-closed Blade fallback) ----

    public function test_ssr_html_is_used_when_the_runtime_is_configured_and_healthy(): void
    {
        config(['sellchase.storefront.ssr_url' => 'http://ssr.test']);
        Http::fake(['*' => Http::response('<html>SSR-RENDERED-MARKER</html>', 200)]);

        $this->get('http://nike.sellchase.com/')->assertOk()->assertSee('SSR-RENDERED-MARKER', false);
    }

    public function test_storefront_falls_back_to_blade_when_ssr_is_unavailable(): void
    {
        config(['sellchase.storefront.ssr_url' => 'http://ssr.test']);
        Http::fake(['*' => Http::response('', 500)]); // SSR down / unusable

        $this->get('http://nike.sellchase.com/')
            ->assertOk() // still renders — never a 500
            ->assertSee('data-rendered-by="blade"', false)
            ->assertSee('Air Max');
    }

    // ---- Task 2: reusable sections eager-loaded (no N+1 on public render) ----

    public function test_reusable_sections_render_without_n_plus_one(): void
    {
        $store = Store::query()->where('slug', 'nike')->first();

        $r1 = StoreReusableSection::create(['store_id' => $store->id, 'key' => 'promo-a', 'name' => 'Promo A', 'type' => 'rich-text', 'settings' => ['content' => 'Hello']]);
        $r2 = StoreReusableSection::create(['store_id' => $store->id, 'key' => 'promo-b', 'name' => 'Promo B', 'type' => 'rich-text', 'settings' => ['content' => 'World']]);
        $page = StorePage::create(['store_id' => $store->id, 'title' => 'About', 'slug' => 'about', 'status' => 'published', 'template' => 'page', 'published_at' => now()]);
        StorePageSection::create(['store_page_id' => $page->id, 'store_id' => $store->id, 'reusable_section_id' => $r1->id, 'type' => 'rich-text', 'settings' => [], 'position' => 0]);
        StorePageSection::create(['store_page_id' => $page->id, 'store_id' => $store->id, 'reusable_section_id' => $r2->id, 'type' => 'rich-text', 'settings' => [], 'position' => 1]);

        DB::enableQueryLog();
        $html = $this->get('http://nike.sellchase.com/pages/about')->assertOk()->getContent();
        $reusableQueries = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'store_reusable_sections'))
            ->count();
        DB::disableQueryLog();

        // Output correct: both reusable blocks expanded to rich-text sections and rendered.
        $this->assertStringContainsString('data-section="rich-text"', $html);
        // And loaded in ONE query (eager), not one-per-section (N+1).
        $this->assertLessThanOrEqual(1, $reusableQueries);
    }
}
