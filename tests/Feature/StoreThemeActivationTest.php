<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreTheme;
use App\Models\StoreThemeActivation;
use App\Models\Theme;
use App\Models\User;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Pre-launch hardening — StoreThemeService::activate() must be atomic: a failure
 * partway through must leave no partial activation state.
 */
class StoreThemeActivationTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $registry = app(ThemeRegistry::class);
        $registry->registerFromFile(resource_path('themes/default/theme.json'));
        $registry->registerFromFile(resource_path('themes/aurora/theme.json'));

        $this->store = Store::create([
            'owner_user_id' => User::factory()->create()->id,
            'owner_type' => 'merchant', 'name' => 'Nike', 'slug' => 'nike',
            'currency' => 'USD', 'status' => 'active',
        ]);
        app(StoreThemeService::class)->installAndActivateDefault($this->store);
    }

    private function installAurora(): StoreTheme
    {
        $aurora = Theme::query()->where('key', 'aurora')->firstOrFail();

        return app(StoreThemeService::class)->install($this->store, $aurora, app(ThemeRegistry::class)->resolveThemeVersion($aurora));
    }

    public function test_activation_rolls_back_completely_on_failure(): void
    {
        $this->store->refresh();
        $defaultThemeId = $this->store->theme_id;
        $this->assertNotNull($defaultThemeId);

        $install = $this->installAurora();
        $this->assertSame('installed', $install->status);
        $activationsBefore = StoreThemeActivation::query()->count();

        // Force a failure at the LAST activation step (audit-row insert), after the
        // deactivate + activate + store-cache writes have already executed.
        StoreThemeActivation::creating(fn () => throw new RuntimeException('boom'));

        try {
            app(StoreThemeService::class)->activate($this->store, $install);
            $this->fail('activation should have thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        // No partial state: everything the transaction touched is rolled back.
        $this->store->refresh();
        $this->assertSame($defaultThemeId, $this->store->theme_id, 'store theme pointer unchanged');
        $this->assertSame('installed', $install->fresh()->status, 'aurora was not left active');
        $this->assertSame($activationsBefore, StoreThemeActivation::query()->count(), 'no orphan audit row');
        $this->assertSame(1, StoreTheme::query()->where('store_id', $this->store->id)->where('status', 'active')->count(), 'still exactly one active theme');
    }

    public function test_successful_activation_commits_a_consistent_state(): void
    {
        $aurora = Theme::query()->where('key', 'aurora')->firstOrFail();
        $install = $this->installAurora();

        app(StoreThemeService::class)->activate($this->store, $install);

        $this->store->refresh();
        $this->assertSame($aurora->id, $this->store->theme_id);
        $this->assertSame('active', $install->fresh()->status);
        $this->assertSame(1, StoreTheme::query()->where('store_id', $this->store->id)->where('status', 'active')->count());
    }
}
