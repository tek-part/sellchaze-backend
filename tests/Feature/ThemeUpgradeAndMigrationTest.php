<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Theme;
use App\Models\User;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use App\Services\Themes\ThemeSettingsMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ThemeUpgradeAndMigrationTest extends TestCase
{
    use RefreshDatabase;

    private StoreThemeService $service;

    private ThemeRegistry $registry;

    private Theme $default;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(ThemeRegistry::class);
        $this->registry->registerFromFile(resource_path('themes/default/theme.json'));      // 1.0.0
        $this->registry->registerFromFile(resource_path('themes/default/v1.1.0.json'));      // 1.1.0
        $this->service = app(StoreThemeService::class);
        $this->default = Theme::where('key', 'default')->first();

        $this->store = Store::create([
            'owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant',
            'name' => 'Nike', 'slug' => 'nike', 'currency' => 'USD', 'status' => 'active',
        ]);

        // Install + activate 1.0.0 explicitly, then customise a setting.
        $v100 = $this->registry->resolveThemeVersion($this->default, '1.0.0');
        $install = $this->service->install($this->store, $this->default, $v100);
        $this->service->activate($this->store, $install);
        $this->service->updateSettings($install, ['primary' => '#abcdef']);
    }

    public function test_migrator_renames_fields(): void
    {
        $out = app(ThemeSettingsMigrator::class)->migrate('default', '1.0.0', '1.1.0', ['primary' => '#abcdef', 'products_per_row' => 4]);
        $this->assertArrayNotHasKey('primary', $out);
        $this->assertSame('#abcdef', $out['brand_primary']);
        $this->assertSame(4, $out['products_per_row']);
    }

    public function test_upgrade_migrates_settings_and_advances_version(): void
    {
        $cache = app(StorefrontPageCache::class);
        $gen = $cache->generation($this->store->id);

        $install = $this->service->upgrade($this->store, $this->default, '1.1.0');

        $this->assertSame('1.1.0', $install->version->version);
        $this->assertSame('#abcdef', $install->settings['brand_primary']); // migrated from "primary"
        $this->assertArrayNotHasKey('primary', $install->settings);
        $this->assertSame('#f59e0b', $install->settings['accent']);        // new field default
        $this->assertGreaterThan($gen, $cache->generation($this->store->id)); // cache invalidated
    }

    public function test_rollback_after_upgrade_restores_previous_version_and_settings(): void
    {
        $this->service->upgrade($this->store, $this->default, '1.1.0');
        $install = $this->service->rollback($this->store);

        $this->assertSame('1.0.0', $install->version->version);
        $this->assertSame('#abcdef', $install->settings['primary']); // exact pre-upgrade settings restored
        $this->assertSame($this->default->id, (int) $this->store->fresh()->theme_id);
    }

    public function test_upgrade_to_same_or_older_version_is_rejected(): void
    {
        $this->service->upgrade($this->store, $this->default, '1.1.0'); // now at 1.1.0
        $this->expectException(RuntimeException::class);
        $this->service->upgrade($this->store, $this->default, '1.0.0'); // not newer
    }

    public function test_upgrade_defaults_to_latest_version(): void
    {
        $install = $this->service->upgrade($this->store, $this->default, null); // null -> latest (1.1.0)
        $this->assertSame('1.1.0', $install->version->version);
    }
}
