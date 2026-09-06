<?php

namespace Tests\Feature;

use App\Console\Commands\PruneLegacyThemes;
use App\Models\Store;
use App\Models\StoreTheme;
use App\Models\StoreThemeActivation;
use App\Models\StoreThemeLicense;
use App\Models\StoreThemeRevision;
use App\Models\Theme;
use App\Models\ThemeAsset;
use App\Models\ThemeLicensePurchase;
use App\Models\ThemeLicensePurchaseEvent;
use App\Models\ThemeStatusChange;
use App\Models\ThemeVersion;
use App\Models\ThemeVersionStatusChange;
use App\Models\User;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `themes:prune-legacy` removes every theme outside the first-party five, moving
 * stores that were using one onto the configured default theme first.
 */
class PruneLegacyThemesTest extends TestCase
{
    use RefreshDatabase;

    private ThemeRegistry $registry;

    private StoreThemeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(ThemeRegistry::class);
        $this->service = app(StoreThemeService::class);
    }

    private function registerFirstParty(): void
    {
        $this->registry->registerFromFile(resource_path('themes/storefront/naseem.json'));
        $this->registry->registerFromFile(resource_path('themes/storefront/bazaar.json'));
    }

    private function registerLegacy(string $key, string $version = '1.0.0'): Theme
    {
        return $this->registry->register([
            'key' => $key, 'name' => ucfirst($key), 'version' => $version, 'author' => 'Sellchase',
            'category' => 'legacy', 'is_marketplace' => true,
            'settings_schema' => [['id' => 'colors', 'label' => 'Colors', 'fields' => [
                ['id' => 'primary', 'type' => 'color', 'label' => 'Primary', 'default' => '#2563eb'],
            ]]],
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
        ]);
    }

    private function store(string $slug): Store
    {
        return Store::create([
            'owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant',
            'name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'USD', 'status' => 'active',
        ]);
    }

    private function activate(Store $store, Theme $theme): StoreTheme
    {
        $install = $this->service->install($store, $theme, $this->registry->resolveThemeVersion($theme));

        return $this->service->activate($store, $install);
    }

    public function test_allowed_keys_are_exactly_the_five_first_party_themes(): void
    {
        $this->assertSame(['naseem', 'bazaar', 'sahra', 'fresh', 'techno'], PruneLegacyThemes::ALLOWED_KEYS);
        $shipped = array_map(fn (string $p) => basename($p, '.json'), ThemeRegistry::manifestPaths());
        sort($shipped);
        $allowed = PruneLegacyThemes::ALLOWED_KEYS;
        sort($allowed);
        $this->assertSame($allowed, $shipped);
    }

    public function test_nothing_to_prune_is_a_successful_no_op(): void
    {
        $this->registerFirstParty();
        $this->artisan('themes:prune-legacy')
            ->expectsOutputToContain('nothing to prune')
            ->assertExitCode(0);
        $this->assertSame(['bazaar', 'naseem'], Theme::query()->orderBy('key')->pluck('key')->all());
    }

    public function test_dry_run_reports_but_changes_nothing(): void
    {
        $this->registerFirstParty();
        $legacy = $this->registerLegacy('aurora');
        $store = $this->store('nike');
        $this->activate($store, $legacy);

        $this->artisan('themes:prune-legacy', ['--dry-run' => true])
            ->expectsOutputToContain("store {$store->id}: aurora -> naseem (dry run, not applied)")
            ->expectsOutputToContain('Dry run — would prune 1 legacy theme(s)')
            ->assertExitCode(0);

        $this->assertNotNull(Theme::query()->where('key', 'aurora')->first());
        $this->assertSame($legacy->id, (int) $store->fresh()->theme_id);
        $this->assertSame('active', StoreTheme::query()->where('store_id', $store->id)->where('theme_id', $legacy->id)->value('status'));
    }

    public function test_prunes_legacy_themes_and_moves_their_stores_to_the_default_theme(): void
    {
        $this->registerFirstParty();
        $naseem = Theme::query()->where('key', 'naseem')->firstOrFail();
        $bazaar = Theme::query()->where('key', 'bazaar')->firstOrFail();
        $legacyActive = $this->registerLegacy('aurora');
        $this->registerLegacy('aurora', '1.1.0'); // second version
        $legacyInstalled = $this->registerLegacy('modern');

        // Store A: legacy theme active, with a draft revision + a rollback history.
        $a = $this->store('nike');
        $this->activate($a, $bazaar);
        $installA = $this->activate($a, $legacyActive);
        $this->service->updateSettings($installA, ['primary' => '#abcdef'], null, 'autosave');

        // Store B: bazaar active, legacy theme merely installed (not active).
        $b = $this->store('adidas');
        $this->activate($b, $bazaar);
        $this->service->install($b, $legacyInstalled, $this->registry->resolveThemeVersion($legacyInstalled));

        // Store C: only the denormalised stores.theme_id points at a legacy theme (no install row).
        $c = $this->store('puma');
        $c->forceFill(['theme_id' => $legacyInstalled->id])->save();

        // Marketplace side-tables referencing the legacy themes.
        ThemeAsset::create(['theme_id' => $legacyActive->id, 'type' => 'screenshot', 'path' => 'x.png', 'disk' => 'public', 'position' => 0]);
        ThemeStatusChange::create(['theme_id' => $legacyActive->id, 'from_status' => 'draft', 'to_status' => 'published']);
        ThemeVersionStatusChange::create(['theme_version_id' => $legacyActive->latest_version_id, 'from_status' => 'draft', 'to_status' => 'published']);
        $purchase = ThemeLicensePurchase::create([
            'store_id' => $b->id, 'theme_id' => $legacyInstalled->id, 'provider' => 'stripe', 'status' => 'paid',
            'amount' => 49, 'currency' => 'USD', 'idempotency_key' => (string) Str::uuid(),
        ]);
        ThemeLicensePurchaseEvent::create(['theme_license_purchase_id' => $purchase->id, 'provider' => 'stripe', 'provider_event_id' => 'evt_1', 'event_type' => 'checkout.session.completed']);

        $this->assertSame(2, ThemeVersion::query()->where('theme_id', $legacyActive->id)->count());
        $this->assertGreaterThan(0, StoreThemeRevision::query()->where('store_theme_id', $installA->id)->count());

        $this->artisan('themes:prune-legacy')
            ->expectsOutputToContain("store {$a->id}: aurora -> naseem")
            ->expectsOutputToContain("store {$c->id}: modern -> naseem")
            ->assertExitCode(0);

        // Only the first-party themes remain.
        $this->assertSame(['bazaar', 'naseem'], Theme::query()->orderBy('key')->pluck('key')->all());
        foreach ([$legacyActive->id, $legacyInstalled->id] as $themeId) {
            $this->assertSame(0, ThemeVersion::query()->where('theme_id', $themeId)->count());
            $this->assertSame(0, StoreTheme::query()->where('theme_id', $themeId)->count());
            $this->assertSame(0, StoreThemeActivation::query()->where('theme_id', $themeId)->count());
            $this->assertSame(0, StoreThemeLicense::query()->where('theme_id', $themeId)->count());
            $this->assertSame(0, ThemeLicensePurchase::query()->where('theme_id', $themeId)->count());
            $this->assertSame(0, ThemeAsset::query()->where('theme_id', $themeId)->count());
            $this->assertSame(0, ThemeStatusChange::query()->where('theme_id', $themeId)->count());
            $this->assertSame(0, Store::query()->where('theme_id', $themeId)->count());
        }
        $this->assertSame(0, StoreThemeRevision::query()->where('store_theme_id', $installA->id)->count());
        $this->assertSame(0, ThemeLicensePurchaseEvent::query()->count());
        $this->assertSame(0, ThemeVersionStatusChange::query()->count());

        // Store A moved to the default theme (settings reset to naseem's defaults), B untouched, C repaired.
        $this->assertSame($naseem->id, (int) $a->fresh()->theme_id);
        $this->assertSame('#1D4ED8', $a->fresh()->theme_settings['primary_color']);
        $this->assertSame(1, StoreTheme::query()->where('store_id', $a->id)->where('status', 'active')->count());
        $this->assertSame('naseem', $a->activeStoreTheme()->firstOrFail()->theme->key);
        $this->assertSame($bazaar->id, (int) $b->fresh()->theme_id);
        $this->assertSame('bazaar', $b->activeStoreTheme()->firstOrFail()->theme->key);
        $this->assertSame($naseem->id, (int) $c->fresh()->theme_id);
        $this->assertSame('naseem', $c->activeStoreTheme()->firstOrFail()->theme->key);
        $this->assertSame(2, StoreTheme::query()->where('theme_id', $bazaar->id)->count()); // A + B keep their bazaar installs

        // Idempotent.
        $this->artisan('themes:prune-legacy')->expectsOutputToContain('nothing to prune')->assertExitCode(0);
    }

    public function test_fails_without_touching_a_legacy_theme_when_the_default_theme_is_missing(): void
    {
        $legacy = $this->registerLegacy('aurora');
        $store = $this->store('nike');
        $this->activate($store, $legacy);

        $this->artisan('themes:prune-legacy')
            ->expectsOutputToContain('run `themes:register` first')
            ->assertExitCode(1);

        $this->assertNotNull(Theme::query()->where('key', 'aurora')->first());
        $this->assertSame($legacy->id, (int) $store->fresh()->theme_id);
    }

    public function test_rejects_a_default_theme_outside_the_allowed_list(): void
    {
        config(['sellchase.storefront.default_theme' => 'aurora']);
        $this->registerLegacy('aurora');

        $this->artisan('themes:prune-legacy')->assertExitCode(1);
        $this->assertNotNull(Theme::query()->where('key', 'aurora')->first());
    }
}
