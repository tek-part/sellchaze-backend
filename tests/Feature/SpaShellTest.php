<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant hosts are served by the React storefront shell (copied from the frontend build), with
 * bundle URLs pointing at the dashboard origin and SEO tags injected server-side.
 */
class SpaShellTest extends TestCase
{
    use RefreshDatabase;

    private string $shellPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        app(ThemeRegistry::class)->registerFromFile(resource_path('themes/storefront/naseem.json'));

        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $store = Store::create([
            'owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Nike', 'slug' => 'nike',
            'currency' => 'USD', 'status' => 'active', 'default_locale' => 'en', 'supported_locales' => ['en', 'ar'],
        ]);
        StoreDomain::create(['store_id' => $store->id, 'host' => 'nike.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(StoreThemeService::class)->installAndActivateDefault($store);

        $this->shellPath = sys_get_temp_dir().'/sellchaze-shell-'.uniqid().'.html';
        file_put_contents($this->shellPath, <<<'HTML'
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Store</title>
<link rel="modulepreload" href="/assets/react-dom-abc.js">
<link rel="stylesheet" href="/assets/storefront-abc.css">
<link rel="icon" href="/icon.png">
</head>
<body><div id="storefront-root"></div><script type="module" src="/assets/storefront-abc.js"></script></body>
</html>
HTML);
        config()->set('sellchase.storefront.spa_shell', $this->shellPath);
        config()->set('sellchase.storefront.spa_origin', 'https://sellchaze.com');
        // Deliberately left as the real default (null): the shell must be used when SSR is unset.
        config()->set('sellchase.storefront.ssr_url', null);
    }

    protected function tearDown(): void
    {
        @unlink($this->shellPath);
        parent::tearDown();
    }

    public function test_tenant_home_is_served_by_the_spa_shell_with_absolute_assets_and_seo(): void
    {
        $response = $this->get('http://nike.sellchase.com/');

        $response->assertOk()
            ->assertHeader('X-Storefront-Renderer', 'spa')
            ->assertSee('data-rendered-by="spa"', false)
            ->assertSee('src="https://sellchaze.com/assets/storefront-abc.js"', false)
            ->assertSee('href="https://sellchaze.com/assets/react-dom-abc.js"', false)
            ->assertSee('href="https://sellchaze.com/icon.png"', false)
            ->assertSee('<title>Nike', false)
            ->assertSee('<link rel="canonical"', false)
            ->assertSee('application/ld+json', false)
            ->assertDontSee('data-rendered-by="blade"', false);
    }

    public function test_shell_opens_asset_and_media_connections_early(): void
    {
        config()->set('filesystems.disks.public.url', 'https://api.sellchaze.test/storage');
        $html = $this->get('http://nike.sellchase.com/')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="preconnect" href="https://sellchaze.com" crossorigin>', $html);
        $this->assertStringContainsString('<link rel="preconnect" href="https://api.sellchaze.test">', $html);
        $this->assertLessThan(strpos($html, 'rel="stylesheet"'), strpos($html, 'rel="preconnect"'));
        $this->assertStringNotContainsString('rel="preload" as="image"', $html);
    }

    public function test_product_pages_preload_the_main_image_with_the_gallery_srcset(): void
    {
        config()->set('filesystems.disks.public.url', 'https://api.sellchaze.test/storage');
        $store = Store::query()->where('slug', 'nike')->firstOrFail();
        app(CurrentStore::class)->set($store);
        Product::create(['store_id' => $store->id, 'user_id' => $store->owner_user_id, 'name' => 'Runner', 'slug' => 'runner', 'price' => 50,
            'is_active' => true, 'image' => 'https://api.sellchaze.test/storage/stores/1/runner.png']);
        app(CurrentStore::class)->forget();

        $html = $this->get('http://nike.sellchase.com/products/runner')->assertOk()->getContent();

        $this->assertStringContainsString('rel="preload" as="image" fetchpriority="high"', $html);
        $this->assertStringContainsString('_img/w320/stores/1/runner.png.webp 320w', $html);
        $this->assertStringContainsString('imagesizes="(min-width: 1024px) 50vw, 100vw"', $html);
    }

    public function test_arabic_request_sets_lang_and_dir_on_the_shell(): void
    {
        $this->get('http://nike.sellchase.com/?lang=ar')
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false);
    }

    public function test_store_identity_reaches_cached_shell_and_blade_fallback(): void
    {
        $this->get('http://nike.sellchase.com/')->assertOk()->assertSee('<title>Nike', false);
        $store = Store::where('slug', 'nike')->firstOrFail();
        $store->update(['site_title' => 'New site title', 'header_mode' => 'custom', 'header_text' => '<b>Plain text</b>',
            'favicon' => 'stores/'.$store->id.'/icons/new.png', 'font_family' => 'Almarai', 'primary_color' => '#123456']);
        $this->get('http://nike.sellchase.com/')->assertOk()->assertSee('<title>New site title', false)
            ->assertSee('/icons/new.png', false)->assertDontSee('href="https://sellchaze.com/icon.png"', false);
        config()->set('sellchase.storefront.spa_shell', '');
        app(StorefrontPageCache::class)->flushStore($store->id);
        $this->get('http://nike.sellchase.com/')->assertOk()->assertSee('data-rendered-by="blade"', false)
            ->assertSee('/icons/new.png', false)->assertSee('family=Almarai:wght@400;700', false)
            ->assertSee('a{color:#123456}', false)->assertSee('&lt;b&gt;Plain text&lt;/b&gt;', false)
            ->assertDontSee('<b>Plain text</b>', false);
    }

    public function test_client_side_routes_fall_back_to_the_shell_on_a_tenant_host(): void
    {
        foreach (['/cart', '/checkout', '/account/orders', '/search?q=shoes', '/collections/new-in', '/products'] as $path) {
            $this->get('http://nike.sellchase.com'.$path)
                ->assertOk()
                ->assertHeader('X-Storefront-Renderer', 'spa');
        }
    }

    public function test_api_paths_and_unknown_hosts_never_receive_the_shell(): void
    {
        $this->getJson('http://nike.sellchase.com/api/v1/does-not-exist')->assertNotFound();
        $this->get('http://unknown-host.sellchase.com/cart')->assertNotFound();
    }

    public function test_blade_fallback_remains_when_the_shell_is_missing(): void
    {
        config()->set('sellchase.storefront.spa_shell', '/nonexistent/shell.html');

        $this->get('http://nike.sellchase.com/')
            ->assertOk()
            ->assertSee('data-rendered-by="blade"', false);
        $this->get('http://nike.sellchase.com/cart')->assertNotFound();
    }
}
