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
        $theme = $this->registry->registerFromFile(resource_path('themes/default/theme.json'));

        $this->assertSame('default', $theme->key);
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
        $this->registry->registerFromFile(resource_path('themes/default/theme.json'));
        $this->registry->registerFromFile(resource_path('themes/default/theme.json'));

        $this->assertSame(1, Theme::count());
        $this->assertSame(1, ThemeVersion::count());
    }

    public function test_resolve_theme_version_returns_latest(): void
    {
        $theme = $this->registry->registerFromFile(resource_path('themes/default/theme.json'));

        $this->assertSame('1.0.0', $this->registry->resolveThemeVersion($theme)?->version);
        $this->assertSame('1.0.0', $this->registry->resolveThemeVersion($theme, '1.0.0')?->version);
        $this->assertNull($this->registry->resolveThemeVersion($theme, '9.9.9'));
    }

    public function test_default_theme_lookup(): void
    {
        $this->assertNull($this->registry->defaultTheme());
        $this->registry->registerFromFile(resource_path('themes/default/theme.json'));
        $this->assertSame('default', $this->registry->defaultTheme()?->key);
    }
}
