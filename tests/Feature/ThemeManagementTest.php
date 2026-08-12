<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreTheme;
use App\Models\StoreThemeActivation;
use App\Models\Theme;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemePreviewToken;
use App\Services\Themes\ThemeRegistry;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;

    private Store $storeA;

    private Store $storeB;

    private int $defaultThemeId;

    private int $minimalThemeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);

        $registry = app(ThemeRegistry::class);
        $registry->registerFromFile(resource_path('themes/default/theme.json'));
        $registry->register($this->minimalManifest());
        $this->defaultThemeId = Theme::where('key', 'default')->value('id');
        $this->minimalThemeId = Theme::where('key', 'minimal')->value('id');

        [$this->ownerA, $this->storeA] = $this->makeMerchantStore('nike');
        [, $this->storeB] = $this->makeMerchantStore('adidas');
    }

    private function minimalManifest(): array
    {
        return [
            'key' => 'minimal', 'name' => 'Minimal', 'version' => '1.0.0', 'author' => 'Sellchase',
            'settings_schema' => [
                ['id' => 'colors', 'label' => 'Colors', 'fields' => [
                    ['id' => 'accent', 'type' => 'color', 'label' => 'Accent', 'default' => '#111111'],
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

    private function makeMerchantStore(string $slug): array
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $store = Store::create([
            'owner_user_id' => $user->id, 'owner_type' => 'merchant',
            'name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'USD', 'status' => 'active',
        ]);
        StoreDomain::create(['store_id' => $store->id, 'host' => "{$slug}.sellchase.com", 'type' => 'subdomain', 'is_primary' => true]);
        // store creation already installed+activated the default theme (StoreService path is bypassed here) -> do it explicitly:
        app(StoreThemeService::class)->installAndActivateDefault($store);

        return [$user, $store];
    }

    private function asOwnerA(): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->ownerA));
    }

    private function asOwnerB(): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->storeB->owner));
    }

    public function test_owner_nested_routes_keep_the_resolved_store_before_child_ids(): void
    {
        $this->asOwnerA()
            ->getJson("/api/v1/my-store/themes/{$this->defaultThemeId}")
            ->assertOk()
            ->assertJsonPath('theme.id', $this->defaultThemeId)
            ->assertJsonPath('is_active', true);

        $domain = StoreDomain::create([
            'store_id' => $this->storeA->id,
            'host' => 'owner-route-order.example.com',
            'type' => 'custom',
            'status' => 'pending',
            'is_primary' => false,
        ]);

        $this->asOwnerA()
            ->postJson("/api/v1/my-store/domains/{$domain->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('store_domains', ['id' => $domain->id]);
    }

    public function test_install_and_activate_theme(): void
    {
        $this->asOwnerA()->postJson("/api/v1/stores/{$this->storeA->id}/themes/install", ['theme_id' => $this->minimalThemeId])
            ->assertCreated()->assertJsonPath('data.status', 'installed');

        $this->asOwnerA()->postJson("/api/v1/stores/{$this->storeA->id}/themes/activate", ['theme_id' => $this->minimalThemeId])
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->storeA->refresh();
        $this->assertSame($this->minimalThemeId, (int) $this->storeA->theme_id);
        $this->assertSame(1, StoreTheme::where('store_id', $this->storeA->id)->where('status', 'active')->count());
    }

    public function test_activation_invalidates_the_page_cache(): void
    {
        $cache = app(StorefrontPageCache::class);
        $gen = $cache->generation($this->storeA->id);

        $this->asOwnerA()->postJson("/api/v1/stores/{$this->storeA->id}/themes/install", ['theme_id' => $this->minimalThemeId]);
        $this->asOwnerA()->postJson("/api/v1/stores/{$this->storeA->id}/themes/activate", ['theme_id' => $this->minimalThemeId])->assertOk();

        $this->assertGreaterThan($gen, $cache->generation($this->storeA->id));
    }

    public function test_preview_does_not_affect_active_theme_or_visitors(): void
    {
        $this->asOwnerA()->postJson("/api/v1/stores/{$this->storeA->id}/themes/install", ['theme_id' => $this->minimalThemeId]);
        $res = $this->asOwnerA()->postJson("/api/v1/stores/{$this->storeA->id}/themes/preview", ['theme_id' => $this->minimalThemeId])->assertOk();
        $url = $res->json('preview_url');
        $this->assertStringContainsString('__preview=', $url);
        $token = parse_url($url, PHP_URL_QUERY);
        $token = str_replace('__preview=', '', $token);

        // Preview renders the candidate (minimal) and is noindex.
        $this->get("http://nike.sellchase.com/?__preview={$token}")
            ->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('data-theme="minimal"', false);

        // Public visitor (no token) still sees the active default theme.
        $this->get('http://nike.sellchase.com/')->assertOk()->assertSee('data-theme="default"', false);

        // Active theme unchanged.
        $this->assertSame($this->defaultThemeId, (int) $this->storeA->fresh()->theme_id);
    }

    public function test_rollback_restores_the_previous_theme(): void
    {
        // default is active (from setUp). Activate minimal, then roll back.
        $this->asOwnerA()->postJson("/api/v1/stores/{$this->storeA->id}/themes/install", ['theme_id' => $this->minimalThemeId]);
        $this->asOwnerA()->postJson("/api/v1/stores/{$this->storeA->id}/themes/activate", ['theme_id' => $this->minimalThemeId])->assertOk();
        $this->assertSame($this->minimalThemeId, (int) $this->storeA->fresh()->theme_id);

        $this->asOwnerA()->postJson("/api/v1/stores/{$this->storeA->id}/themes/rollback")->assertOk();
        $this->assertSame($this->defaultThemeId, (int) $this->storeA->fresh()->theme_id);

        $last = StoreThemeActivation::where('store_id', $this->storeA->id)->latest('id')->first();
        $this->assertSame('rollback', $last->action);
        $this->assertNotNull($last->rollback_to_version_id);
    }

    public function test_rollback_without_history_is_rejected(): void
    {
        // storeA has exactly one activation (default from setUp) -> nothing to roll back to.
        $this->asOwnerA()->postJson("/api/v1/stores/{$this->storeA->id}/themes/rollback")->assertStatus(422);
    }

    public function test_settings_are_coerced_and_invalid_settings_rejected(): void
    {
        // valid but out-of-range -> coerced (products_per_row clamped to 6)
        $this->asOwnerA()->postJson("/api/v1/stores/{$this->storeA->id}/themes/settings", [
            'theme_id' => $this->defaultThemeId,
            'settings' => ['primary' => '#ffffff', 'products_per_row' => 99],
        ])->assertOk();
        $settings = StoreTheme::where('store_id', $this->storeA->id)->where('theme_id', $this->defaultThemeId)->value('settings');
        $this->assertSame(6, $settings['products_per_row']);
        $this->assertSame('#ffffff', $settings['primary']);

        // invalid type -> 422
        $this->asOwnerA()->putJson("/api/v1/stores/{$this->storeA->id}/themes/settings", [
            'theme_id' => $this->defaultThemeId,
            'settings' => ['products_per_row' => 'not-a-number'],
        ])->assertStatus(422)->assertJsonValidationErrors('settings');
    }

    public function test_theme_history_is_recorded(): void
    {
        $this->asOwnerA()->postJson("/api/v1/stores/{$this->storeA->id}/themes/install", ['theme_id' => $this->minimalThemeId]);
        $this->asOwnerA()->postJson("/api/v1/stores/{$this->storeA->id}/themes/activate", ['theme_id' => $this->minimalThemeId]);

        $rows = $this->asOwnerA()->getJson("/api/v1/stores/{$this->storeA->id}/themes/history")->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        $this->assertSame('minimal', $rows[0]['theme']);            // latest activation
        $this->assertNotNull($rows[0]['activated_by']);
    }

    public function test_non_owner_cannot_manage_or_preview(): void
    {
        // Owner B cannot list, activate, or change settings on Store A.
        $this->asOwnerB()->getJson("/api/v1/stores/{$this->storeA->id}/themes")->assertForbidden();
        $this->asOwnerB()->postJson("/api/v1/stores/{$this->storeA->id}/themes/activate", ['theme_id' => $this->defaultThemeId])->assertForbidden();
        $this->asOwnerB()->postJson("/api/v1/stores/{$this->storeA->id}/themes/preview", ['theme_id' => $this->defaultThemeId])->assertForbidden();
    }

    public function test_preview_tokens_cannot_be_forged(): void
    {
        $token = app(ThemePreviewToken::class)->make($this->storeA->id, 999, 1800);
        $this->assertSame(999, app(ThemePreviewToken::class)->verify($token, $this->storeA->id));
        $this->assertNull(app(ThemePreviewToken::class)->verify($token.'x', $this->storeA->id));      // tampered
        $this->assertNull(app(ThemePreviewToken::class)->verify($token, $this->storeB->id));          // wrong store
        $this->assertNull(app(ThemePreviewToken::class)->verify('garbage.sig', $this->storeA->id));
    }
}
