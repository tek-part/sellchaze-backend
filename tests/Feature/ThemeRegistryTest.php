<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Models\User;
use App\Services\Themes\StoreThemeService;
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

    public function test_missing_only_command_preserves_existing_theme_and_version_customizations(): void
    {
        $theme = $this->registry->registerFromFile(resource_path('themes/storefront/naseem.json'));
        $theme->update(['name' => 'Merchant custom theme', 'preview_image' => '/merchant-preview.png', 'status' => 'draft']);
        $version = ThemeVersion::findOrFail($theme->latest_version_id);
        $version->update(['changelog' => ['Merchant release notes']]);
        $themeBefore = $theme->fresh()->getAttributes();
        $versionBefore = $version->fresh()->getAttributes();

        $this->artisan('themes:register', ['--only' => 'naseem,blast-pets', '--missing-only' => true])
            ->expectsOutputToContain('Registered 1 theme manifest(s).')
            ->expectsOutputToContain('Preserved 1 existing theme key(s).')
            ->assertSuccessful();
        $this->assertSame($themeBefore, $theme->fresh()->getAttributes());
        $this->assertSame($versionBefore, $version->fresh()->getAttributes());
        $this->assertSame(2, Theme::count());
        $this->assertSame(2, ThemeVersion::count());
        $this->assertDatabaseHas('themes', ['key' => 'blast-pets', 'status' => 'published']);
        $this->assertDatabaseCount('store_themes', 0);
        $this->assertDatabaseCount('store_theme_activations', 0);

        $this->artisan('themes:register', ['--only' => 'naseem,blast-pets', '--missing-only' => true])
            ->expectsOutputToContain('Registered 0 theme manifest(s).')
            ->expectsOutputToContain('Preserved 2 existing theme key(s).')
            ->assertSuccessful();
        $this->assertSame($themeBefore, $theme->fresh()->getAttributes());
        $this->assertSame($versionBefore, $version->fresh()->getAttributes());
        $this->assertSame(2, ThemeVersion::count());
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

        $this->assertSame(['bazaar', 'blast-pets', 'elite-furniture', 'elite-wallets', 'fresh', 'glow', 'hype-fashion', 'hype-perfumes', 'karat', 'maison', 'naseem', 'nature-kids', 'nature-pets', 'pure-furniture', 'sahra', 'sphinx-v3', 'stride', 'techno'], $keys);
        foreach (ThemeRegistry::manifestPaths() as $path) {
            $this->assertStringStartsWith(realpath(resource_path('themes/storefront')).DIRECTORY_SEPARATOR, realpath($path));
        }
    }

    public function test_sphinx_installs_activates_and_publishes_translatable_footer_settings(): void
    {
        $theme = $this->registry->registerFromFile(resource_path('themes/storefront/sphinx-v3.json'));
        $owner = User::factory()->create();
        $store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Sphinx', 'slug' => 'sphinx-registry', 'currency' => 'EGP', 'status' => 'active']);
        $service = app(StoreThemeService::class);
        $install = $service->install($store, $theme, $this->registry->resolveThemeVersion($theme), $owner->id);
        $this->assertSame('Raleway', $install->settings['heading_font']);
        $service->activate($store, $install, $owner->id);
        $this->assertSame($theme->id, $store->fresh()->theme_id);
        $service->updateSettings($install, ['newsletter_heading' => ['ar' => 'أخبارنا', 'en' => 'Our news'], 'show_newsletter' => false], $owner->id);
        $this->assertTrue($install->fresh()->settings['show_newsletter']);
        $published = $service->publish($store, $install, $owner->id);
        $this->assertFalse($published->settings['show_newsletter']);
        $this->assertSame(['ar' => 'أخبارنا', 'en' => 'Our news'], $store->fresh()->theme_settings['newsletter_heading']);
        $this->assertArrayHasKey('sphinx-categories', $this->registry->resolveThemeVersion($theme)->sections_schema);
    }
}
