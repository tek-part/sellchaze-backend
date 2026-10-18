<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreTheme;
use App\Models\User;
use App\Services\StoreService;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreThemeInstallTest extends TestCase
{
    use RefreshDatabase;

    private ThemeRegistry $registry;

    private StoreThemeService $installer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ThemeRegistry;
        $this->installer = app(StoreThemeService::class);
        $this->registry->registerFromFile(resource_path('themes/storefront/naseem.json'));
    }

    private function makeStore(): Store
    {
        return Store::create([
            'owner_user_id' => User::factory()->create()->id,
            'owner_type' => 'merchant', 'name' => 'Nike', 'slug' => 'nike',
            'currency' => 'USD', 'status' => 'active',
        ]);
    }

    public function test_install_and_activate_default_wires_the_store_cache(): void
    {
        $store = $this->makeStore();
        $install = $this->installer->installAndActivateDefault($store);

        $this->assertNotNull($install);
        $this->assertSame('active', $install->status);

        $store->refresh();
        $this->assertSame($install->theme_id, (int) $store->theme_id);
        // settings seeded from the manifest defaults
        $this->assertSame('#1D4ED8', $store->theme_settings['primary_color']);
        $this->assertSame(16, $store->theme_settings['base_font_size']);
        $this->assertSame('naseem', $install->theme->key);
    }

    public function test_resolve_active_theme_returns_the_active_version(): void
    {
        $store = $this->makeStore();
        $this->installer->installAndActivateDefault($store);

        $this->assertSame('1.0.0', $this->registry->resolveActiveTheme($store)?->version);
    }

    public function test_resolve_active_theme_falls_back_to_default_when_none_installed(): void
    {
        $store = $this->makeStore(); // no install

        $this->assertSame(0, StoreTheme::count());
        $this->assertSame('1.0.0', $this->registry->resolveActiveTheme($store)?->version);
    }

    public function test_install_is_idempotent_per_store_and_theme(): void
    {
        $store = $this->makeStore();
        $this->installer->installAndActivateDefault($store);
        $this->installer->installAndActivateDefault($store);

        $this->assertSame(1, StoreTheme::where('store_id', $store->id)->count());
    }

    public function test_elite_furniture_registers_and_activates_only_the_selected_store_with_editable_comparison(): void
    {
        $store = $this->makeStore();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-theme-store', 'currency' => 'USD', 'status' => 'active']);
        $this->installer->installAndActivateDefault($other);
        $oldTheme = $other->fresh()->theme_id;
        $theme = $this->registry->registerFromFile(resource_path('themes/storefront/elite-furniture.json'));
        $version = $this->registry->resolveThemeVersion($theme);
        $this->assertSame('builtin:elite-furniture@1.0.0', $version->bundle_url);
        $this->assertArrayHasKey('elite-image-compare', $version->sections_schema);
        $installed = $this->installer->install($store, $theme, $version);
        $this->installer->activate($store, $installed);
        $this->assertSame('elite-furniture', $this->registry->resolveActiveTheme($store->fresh())->theme->key);
        $this->assertSame('Instrument Sans', $store->fresh()->theme_settings['body_font']);
        $this->assertSame($oldTheme, $other->fresh()->theme_id);
        $this->assertSame('naseem', $this->registry->resolveActiveTheme($other->fresh())->theme->key);
    }

    public function test_pure_furniture_registration_is_additive_and_activation_is_store_scoped(): void
    {
        $store = $this->makeStore();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-pure-store', 'currency' => 'USD', 'status' => 'active']);
        $this->installer->installAndActivateDefault($other);
        $oldTheme = $other->fresh()->theme_id;
        $oldSettings = $other->fresh()->theme_settings;
        $theme = $this->registry->registerFromFile(resource_path('themes/storefront/pure-furniture.json'));
        $version = $this->registry->resolveThemeVersion($theme);
        $this->assertSame('builtin:pure-furniture@1.0.0', $version->bundle_url);
        $this->assertArrayHasKey('pure-image-compare', $version->sections_schema);
        $this->assertArrayHasKey('pure-hero', $version->sections_schema);
        $installed = $this->installer->install($store, $theme, $version);
        $this->installer->activate($store, $installed);
        $this->assertSame('pure-furniture', $this->registry->resolveActiveTheme($store->fresh())->theme->key);
        $this->assertSame('#834523', $store->fresh()->theme_settings['primary_color']);
        $this->assertSame($oldTheme, $other->fresh()->theme_id);
        $this->assertSame($oldSettings, $other->fresh()->theme_settings);
        $this->assertSame('naseem', $this->registry->resolveActiveTheme($other->fresh())->theme->key);
    }

    public function test_nature_kids_install_keeps_other_store_activation_and_settings(): void
    {
        $store = $this->makeStore();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-kids-store', 'currency' => 'USD', 'status' => 'active']);
        $this->installer->installAndActivateDefault($other);
        $oldTheme = $other->fresh()->theme_id;
        $oldSettings = $other->fresh()->theme_settings;
        $theme = $this->registry->registerFromFile(resource_path('themes/storefront/nature-kids.json'));
        $version = $this->registry->resolveThemeVersion($theme);
        $this->assertSame('builtin:nature-kids@1.0.0', $version->bundle_url);
        $this->assertArrayHasKey('kids-peek-hero', $version->sections_schema);
        $this->assertArrayHasKey('kids-shop-look', $version->sections_schema);
        $installed = $this->installer->install($store, $theme, $version);
        $this->installer->activate($store, $installed);
        $this->assertSame('nature-kids', $this->registry->resolveActiveTheme($store->fresh())->theme->key);
        $this->assertSame('#1470F2', $store->fresh()->theme_settings['primary_color']);
        $this->assertSame($oldTheme, $other->fresh()->theme_id);
        $this->assertSame($oldSettings, $other->fresh()->theme_settings);
    }

    public function test_blast_pets_install_keeps_other_store_activation_and_settings(): void
    {
        $store = $this->makeStore();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-pets-store', 'currency' => 'USD', 'status' => 'active']);
        $this->installer->installAndActivateDefault($other);
        $oldTheme = $other->fresh()->theme_id;
        $oldSettings = $other->fresh()->theme_settings;
        $theme = $this->registry->registerFromFile(resource_path('themes/storefront/blast-pets.json'));
        $version = $this->registry->resolveThemeVersion($theme);
        $this->assertSame('builtin:blast-pets@1.0.0', $version->bundle_url);
        $this->assertArrayHasKey('pets-slideshow', $version->sections_schema);
        $this->assertArrayHasKey('pets-linked-look', $version->sections_schema);
        $installed = $this->installer->install($store, $theme, $version);
        $this->installer->activate($store, $installed);
        $this->assertSame('blast-pets', $this->registry->resolveActiveTheme($store->fresh())->theme->key);
        $this->assertSame('#FDAE03', $store->fresh()->theme_settings['primary_color']);
        $this->assertSame($oldTheme, $other->fresh()->theme_id);
        $this->assertSame($oldSettings, $other->fresh()->theme_settings);
        $this->assertSame('naseem', $this->registry->resolveActiveTheme($other->fresh())->theme->key);
    }

    public function test_nature_pets_installs_its_own_editable_composition_without_changing_another_store(): void
    {
        $store = $this->makeStore();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-nature-pets', 'currency' => 'USD', 'status' => 'active']);
        $this->installer->installAndActivateDefault($other);
        $before = $other->fresh()->only(['theme_id', 'theme_settings']);
        $theme = $this->registry->registerFromFile(resource_path('themes/storefront/nature-pets.json'));
        $version = $this->registry->resolveThemeVersion($theme);
        $this->assertSame('builtin:nature-pets@1.0.0', $version->bundle_url);
        $this->assertArrayHasKey('nature-pets-faq', $version->sections_schema);
        $this->assertArrayHasKey('nature-pets-shelf', $version->sections_schema);
        $this->assertCount(9, $version->templates['home']['sections']);
        $installed = $this->installer->install($store, $theme, $version);
        $this->installer->activate($store, $installed);
        $this->assertSame('nature-pets', $this->registry->resolveActiveTheme($store->fresh())->theme->key);
        $this->assertSame('#A67500', $store->fresh()->theme_settings['primary_color']);
        $this->assertSame($before, $other->fresh()->only(['theme_id', 'theme_settings']));
    }

    public function test_store_created_via_service_gets_default_theme(): void
    {
        // StoreService::create resolves the owner from the authenticated user.
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->actingAs($user);

        // Merchant role so effectiveMerchantUserId resolves to the owner.
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        $user->assignRole('Merchant');

        $store = app(StoreService::class)->create($user, ['name' => 'Made By Service']);

        $this->assertNotNull($store->fresh()->theme_id, 'store created via service should have an active theme');
        $this->assertSame('active', StoreTheme::where('store_id', $store->id)->value('status'));
    }
}
