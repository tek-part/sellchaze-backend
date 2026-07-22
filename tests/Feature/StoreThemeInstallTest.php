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
        $this->registry->registerFromFile(resource_path('themes/default/theme.json'));
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
        $this->assertSame('#2563eb', $store->theme_settings['primary']);
        $this->assertSame(4, $store->theme_settings['products_per_row']);
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
