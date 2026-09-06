<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Theme;
use App\Models\User;
use App\Services\Storefront\StorefrontContextBuilder;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multiple themes render simultaneously: the render context carries the correct
 * per-store theme key/version/bundle_url so the SSR runtime loads the right bundle.
 */
class MultiThemeContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $registry = app(ThemeRegistry::class);
        $registry->registerFromFile(resource_path('themes/storefront/naseem.json'));
        $registry->registerFromFile(resource_path('themes/storefront/bazaar.json'));
    }

    private function storeWithTheme(string $slug, string $themeKey): Store
    {
        $store = Store::create([
            'owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant',
            'name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'USD', 'status' => 'active',
        ]);
        $theme = Theme::where('key', $themeKey)->first();
        $version = app(ThemeRegistry::class)->resolveThemeVersion($theme);
        $service = app(StoreThemeService::class);
        $service->activate($store, $service->install($store, $theme, $version));

        return $store;
    }

    public function test_each_store_context_carries_its_own_theme_bundle(): void
    {
        $nike = $this->storeWithTheme('nike', 'naseem');
        $apple = $this->storeWithTheme('apple', 'bazaar');
        $builder = app(StorefrontContextBuilder::class);

        app(CurrentStore::class)->set($nike);
        $nikeCtx = $builder->build($nike, 'home');
        $this->assertSame('naseem', $nikeCtx['theme']['key']);
        $this->assertSame('1.0.0', $nikeCtx['theme']['version']);
        $this->assertSame('builtin:naseem@1.0.0', $nikeCtx['theme']['bundle_url']);

        app(CurrentStore::class)->set($apple);
        $appleCtx = $builder->build($apple, 'home');
        $this->assertSame('bazaar', $appleCtx['theme']['key']);
        $this->assertSame('builtin:bazaar@1.0.0', $appleCtx['theme']['bundle_url']);
    }
}
