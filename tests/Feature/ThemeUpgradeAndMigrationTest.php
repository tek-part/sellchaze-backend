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

/**
 * Version upgrade + settings migration. Uses a synthetic two-version theme
 * (1.0.0 renames `primary` to `brand_primary` and adds `accent` in 1.1.0) so the
 * test does not depend on any shipped manifest having more than one version.
 */
class ThemeUpgradeAndMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'upgrade-test';

    private StoreThemeService $service;

    private ThemeRegistry $registry;

    private Theme $theme;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(ThemeRegistry::class);
        $this->registry->register($this->manifest('1.0.0', [
            ['id' => 'primary', 'type' => 'color', 'label' => 'Primary', 'default' => '#2563eb'],
        ]));
        $this->registry->register($this->manifest('1.1.0', [
            ['id' => 'brand_primary', 'type' => 'color', 'label' => 'Brand primary', 'default' => '#2563eb'],
            ['id' => 'accent', 'type' => 'color', 'label' => 'Accent', 'default' => '#f59e0b'],
        ]));
        app(ThemeSettingsMigrator::class)->register(
            self::KEY, '1.0.0', '1.1.0',
            ThemeSettingsMigrator::rename(['primary' => 'brand_primary']),
        );

        $this->service = app(StoreThemeService::class);
        $this->theme = Theme::where('key', self::KEY)->firstOrFail();

        $this->store = Store::create([
            'owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant',
            'name' => 'Nike', 'slug' => 'nike', 'currency' => 'USD', 'status' => 'active',
        ]);

        // Install + activate 1.0.0 explicitly, then customise and publish a setting.
        $v100 = $this->registry->resolveThemeVersion($this->theme, '1.0.0');
        $install = $this->service->install($this->store, $this->theme, $v100);
        $this->service->activate($this->store, $install);
        $this->service->updateSettings($install, ['primary' => '#abcdef']);
        $this->service->publish($this->store, $install);
    }

    /** @param  list<array<string,mixed>>  $colorFields */
    private function manifest(string $version, array $colorFields): array
    {
        return [
            'key' => self::KEY, 'name' => 'Upgrade test', 'version' => $version, 'author' => 'Sellchaze',
            'min_platform_version' => '1.0.0',
            'settings_schema' => [
                ['id' => 'colors', 'label' => 'Colors', 'fields' => $colorFields],
                ['id' => 'layout', 'label' => 'Layout', 'fields' => [
                    ['id' => 'products_per_row', 'type' => 'range', 'label' => 'Products per row', 'default' => 4, 'min' => 2, 'max' => 6],
                ]],
            ],
            'sections_schema' => [
                'hero' => ['label' => 'Hero', 'settings' => []],
                'product-grid' => ['label' => 'Grid', 'settings' => []],
                'category-header' => ['label' => 'CH', 'settings' => []],
                'product-details' => ['label' => 'PD', 'settings' => []],
            ],
            'templates' => [
                'home' => ['sections' => [['type' => 'hero'], ['type' => 'product-grid']]],
                'product' => ['sections' => [['type' => 'product-details']]],
                'category' => ['sections' => [['type' => 'category-header'], ['type' => 'product-grid']]],
            ],
        ];
    }

    public function test_migrator_renames_fields(): void
    {
        $out = app(ThemeSettingsMigrator::class)->migrate(self::KEY, '1.0.0', '1.1.0', ['primary' => '#abcdef', 'products_per_row' => 4]);
        $this->assertArrayNotHasKey('primary', $out);
        $this->assertSame('#abcdef', $out['brand_primary']);
        $this->assertSame(4, $out['products_per_row']);
    }

    public function test_upgrade_migrates_settings_and_advances_version(): void
    {
        $cache = app(StorefrontPageCache::class);
        $gen = $cache->generation($this->store->id);

        $install = $this->service->upgrade($this->store, $this->theme, '1.1.0');

        $this->assertSame('1.1.0', $install->version->version);
        $this->assertSame('#abcdef', $install->settings['brand_primary']); // migrated from "primary"
        $this->assertArrayNotHasKey('primary', $install->settings);
        $this->assertSame('#f59e0b', $install->settings['accent']);        // new field default
        $this->assertGreaterThan($gen, $cache->generation($this->store->id)); // cache invalidated
    }

    public function test_rollback_after_upgrade_restores_previous_version_and_settings(): void
    {
        $this->service->upgrade($this->store, $this->theme, '1.1.0');
        $install = $this->service->rollback($this->store);

        $this->assertSame('1.0.0', $install->version->version);
        $this->assertSame('#abcdef', $install->settings['primary']); // exact pre-upgrade settings restored
        $this->assertSame($this->theme->id, (int) $this->store->fresh()->theme_id);
    }

    public function test_upgrade_to_same_or_older_version_is_rejected(): void
    {
        $this->service->upgrade($this->store, $this->theme, '1.1.0'); // now at 1.1.0
        $this->expectException(RuntimeException::class);
        $this->service->upgrade($this->store, $this->theme, '1.0.0'); // not newer
    }

    public function test_upgrade_defaults_to_latest_version(): void
    {
        $install = $this->service->upgrade($this->store, $this->theme, null); // null -> latest (1.1.0)
        $this->assertSame('1.1.0', $install->version->version);
    }
}
