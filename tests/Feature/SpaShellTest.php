<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
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

    public function test_arabic_request_sets_lang_and_dir_on_the_shell(): void
    {
        $this->get('http://nike.sellchase.com/?lang=ar')
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false);
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
