<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\Themes\ThemeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeRegistryTest extends TestCase
{
    use RefreshDatabase;

    private ThemeRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ThemeRegistry;
    }

    public function test_register_from_file_creates_theme_and_version(): void
    {
        $theme = $this->registry->registerFromFile(resource_path('themes/storefront/naseem.json'));

        $this->assertSame('naseem', $theme->key);
        $this->assertSame(1, Theme::count());
        $this->assertSame(1, ThemeVersion::count());
        $this->assertNotNull($theme->latest_version_id);

        $version = ThemeVersion::find($theme->latest_version_id);
        $this->assertSame('1.0.0', $version->version);
        $this->assertIsArray($version->settings_schema);
        $this->assertIsArray($version->templates);
    }

    public function test_register_is_idempotent(): void
    {
        $this->registry->registerFromFile(resource_path('themes/storefront/naseem.json'));
        $this->registry->registerFromFile(resource_path('themes/storefront/naseem.json'));

        $this->assertSame(1, Theme::count());
        $this->assertSame(1, ThemeVersion::count());
    }

    public function test_resolve_theme_version_returns_latest(): void
    {
        $theme = $this->registry->registerFromFile(resource_path('themes/storefront/naseem.json'));

        $this->assertSame('1.0.0', $this->registry->resolveThemeVersion($theme)?->version);
        $this->assertSame('1.0.0', $this->registry->resolveThemeVersion($theme, '1.0.0')?->version);
        $this->assertNull($this->registry->resolveThemeVersion($theme, '9.9.9'));
    }

    public function test_default_theme_lookup(): void
    {
        $this->assertNull($this->registry->defaultTheme());
        $this->registry->registerFromFile(resource_path('themes/storefront/naseem.json'));
        $this->assertSame('naseem', $this->registry->defaultTheme()?->key);
    }

    public function test_default_theme_key_comes_from_config(): void
    {
        $this->registry->registerFromFile(resource_path('themes/storefront/naseem.json'));
        $this->registry->registerFromFile(resource_path('themes/storefront/bazaar.json'));

        $this->assertSame('naseem', ThemeRegistry::defaultThemeKey());
        config(['sellchase.storefront.default_theme' => 'bazaar']);
        $this->assertSame('bazaar', ThemeRegistry::defaultThemeKey());
        $this->assertSame('bazaar', $this->registry->defaultTheme()?->key);
        config(['sellchase.storefront.default_theme' => 'missing-theme']);
        $this->assertNull($this->registry->defaultTheme());
    }

    public function test_manifest_discovery_lists_only_the_first_party_storefront_themes(): void
    {
        $keys = array_map(
            fn (string $path) => json_decode((string) file_get_contents($path), true)['key'],
            ThemeRegistry::manifestPaths(),
        );

        $this->assertSame(['bazaar', 'fresh', 'naseem', 'sahra', 'techno'], $keys);
        foreach (ThemeRegistry::manifestPaths() as $path) {
            $this->assertStringStartsWith(resource_path('themes/storefront/'), $path);
        }
    }
}
