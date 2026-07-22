<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use App\Services\Themes\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ThemeRegistry::class)->registerFromFile(resource_path('themes/default/theme.json'));
    }

    private function makeStore(): Store
    {
        return Store::create([
            'owner_user_id' => User::factory()->create()->id,
            'owner_type' => 'merchant', 'name' => 'Nike', 'slug' => 'nike',
            'currency' => 'USD', 'status' => 'active',
        ]);
    }

    public function test_resolves_active_theme_context(): void
    {
        $store = $this->makeStore();
        app(StoreThemeService::class)->installAndActivateDefault($store);

        $ctx = app(ThemeResolver::class)->resolve($store);

        $this->assertSame('default', $ctx['key']);
        $this->assertSame('1.0.0', $ctx['version']);
        $this->assertSame('#2563eb', $ctx['settings']['primary']);   // validated/defaulted
        $this->assertArrayHasKey('home', $ctx['templates']);
        $this->assertArrayHasKey('hero', $ctx['sections_schema']);
    }

    public function test_falls_back_to_default_theme_when_no_install(): void
    {
        $store = $this->makeStore(); // no active install

        $ctx = app(ThemeResolver::class)->resolve($store);

        $this->assertSame('default', $ctx['key']);
        $this->assertSame('1.0.0', $ctx['version']);
    }
}
