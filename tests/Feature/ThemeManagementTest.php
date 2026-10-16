<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreTheme;
use App\Models\StoreThemeActivation;
use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemePreviewToken;
use App\Services\Themes\ThemeRegistry;
use App\Services\Themes\ThemeResolver;
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
        $registry->registerFromFile(resource_path('themes/storefront/naseem.json'));
        $registry->register($this->minimalManifest());
        $this->defaultThemeId = Theme::where('key', 'naseem')->value('id');
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
        // StoreService (which auto-installs the default theme) is bypassed here -> do it explicitly:
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

    public function test_owner_gallery_classifies_real_packages_without_installing_or_activating_them(): void
    {
        foreach (['blast-pets', 'pure-furniture', 'nature-kids', 'elite-furniture'] as $key) {
            app(ThemeRegistry::class)->registerFromFile(resource_path("themes/storefront/{$key}.json"));
        }
        $beforeA = $this->storeA->fresh()->getAttributes();
        $beforeB = $this->storeB->fresh()->getAttributes();
        $beforeInstalls = StoreTheme::count();
        $beforeHistory = StoreThemeActivation::count();

        $response = $this->asOwnerA()->getJson('/api/v1/my-store/themes')->assertOk();
        $rows = collect($response->json('data'))->keyBy('key');
        $this->assertCount(6, $rows);
        $this->assertSame('modern', $rows['blast-pets']['gallery_collection']);
        $this->assertSame(['pets'], $rows['blast-pets']['gallery_categories']);
        $this->assertSame(3, $rows['blast-pets']['gallery_rank']);
        $this->assertTrue($rows['blast-pets']['licensed']);
        $this->assertFalse($rows['blast-pets']['installed']);
        $this->assertSame(['hobbies', 'kids'], $rows['nature-kids']['gallery_categories']);
        $this->assertSame('classic', $rows['minimal']['gallery_collection']);
        $this->assertSame([], $rows['minimal']['gallery_categories']);
        $this->assertSame(1000, $rows['minimal']['gallery_rank']);
        $this->assertArrayHasKey('category', $rows['naseem']);
        $this->assertFalse($rows->has('nature-pets')); // Metadata never creates a fictional package.
        $this->assertSame($this->defaultThemeId, $response->json('active_theme_id'));
        $this->assertSame($beforeInstalls, StoreTheme::count());
        $this->assertSame($beforeHistory, StoreThemeActivation::count());
        $this->assertSame($beforeA, $this->storeA->fresh()->getAttributes());
        $this->assertSame($beforeB, $this->storeB->fresh()->getAttributes());
    }

    public function test_gallery_reports_the_effective_default_without_creating_an_install(): void
    {
        $this->storeA->storeThemes()->delete();
        $this->storeA->update(['theme_id' => null, 'theme_settings' => null]);
        $count = StoreTheme::count();
        $this->asOwnerA()->getJson('/api/v1/my-store/themes')
            ->assertOk()->assertJsonPath('active_theme_id', $this->defaultThemeId);
        $this->assertSame('naseem', app(ThemeResolver::class)->resolve($this->storeA->fresh())['key']);
        $this->assertSame($count, StoreTheme::count());
        $this->assertNull($this->storeA->fresh()->theme_id);
    }

    public function test_catalog_preview_bootstrap_uses_the_selected_package_without_install_or_activation(): void
    {
        $version = ThemeVersion::where('theme_id', $this->minimalThemeId)->firstOrFail();
        $version->update(['bundle_url' => '/api/v1/theme-assets/fixture-bundle.js', 'bundle_integrity' => 'sha384-'.base64_encode(str_repeat('x', 48))]);
        $before = $this->storeA->fresh()->getAttributes();
        $installs = StoreTheme::count();
        $history = StoreThemeActivation::count();
        $preview = $this->asOwnerA()->postJson('/api/v1/my-store/themes/preview', ['theme_id' => $this->minimalThemeId])->assertOk();
        parse_str((string) parse_url($preview->json('preview_url'), PHP_URL_QUERY), $query);
        $response = $this->getJson('http://nike.sellchase.com/api/v1/storefront?__preview='.urlencode($query['__preview']))
            ->assertOk()->assertJsonPath('theme.key', 'minimal')->assertJsonPath('preview.valid', true)
            ->assertJsonPath('theme.bundle_url', '/api/v1/theme-assets/fixture-bundle.js')
            ->assertJsonPath('theme.bundle_integrity', $version->bundle_integrity)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($before, $this->storeA->fresh()->getAttributes());
        $this->assertSame($installs, StoreTheme::count());
        $this->assertSame($history, StoreThemeActivation::count());
        $this->getJson('http://nike.sellchase.com/api/v1/storefront')->assertOk()
            ->assertJsonPath('theme.key', 'naseem')->assertJsonMissingPath('preview');
    }

    public function test_invalid_expired_or_foreign_catalog_tokens_keep_public_theme_data_and_report_unavailable_preview(): void
    {
        $versionId = (int) ThemeVersion::where('theme_id', $this->minimalThemeId)->value('id');
        $tokens = app(ThemePreviewToken::class);
        $valid = $tokens->makeCatalog($this->storeA->id, $versionId);
        $forged = substr($valid, 0, -1).(substr($valid, -1) === 'a' ? 'b' : 'a');
        foreach ([$forged, $tokens->makeCatalog($this->storeA->id, $versionId, -1), $tokens->makeCatalog($this->storeB->id, $versionId)] as $token) {
            $response = $this->getJson('http://nike.sellchase.com/api/v1/storefront?__preview='.urlencode($token))
                ->assertOk()->assertJsonPath('theme.key', 'naseem')->assertJsonPath('preview.valid', false)
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
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
        $this->get('http://nike.sellchase.com/')->assertOk()->assertSee('data-theme="naseem"', false);

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
        // valid but out-of-range -> coerced (base_font_size clamped to its max of 18)
        $this->asOwnerA()->postJson("/api/v1/stores/{$this->storeA->id}/themes/settings", [
            'theme_id' => $this->defaultThemeId,
            'settings' => ['primary_color' => '#ffffff', 'base_font_size' => 99],
        ])->assertOk();
        $settings = StoreTheme::where('store_id', $this->storeA->id)->where('theme_id', $this->defaultThemeId)->value('draft_settings');
        $this->assertSame(18, $settings['base_font_size']);
        $this->assertSame('#ffffff', $settings['primary_color']);

        // invalid type -> 422
        $this->asOwnerA()->putJson("/api/v1/stores/{$this->storeA->id}/themes/settings", [
            'theme_id' => $this->defaultThemeId,
            'settings' => ['base_font_size' => 'not-a-number'],
        ])->assertStatus(422)->assertJsonValidationErrors('settings');
    }

    public function test_theme_autosave_creates_deduplicated_revisions_and_can_restore(): void
    {
        $this->asOwnerA()->postJson("/api/v1/stores/{$this->storeA->id}/themes/install", [
            'theme_id' => $this->minimalThemeId,
        ])->assertCreated();

        $this->asOwnerA()->putJson("/api/v1/stores/{$this->storeA->id}/themes/settings", [
            'theme_id' => $this->minimalThemeId,
            'settings' => ['accent' => '#111111'],
            'source' => 'autosave',
        ])->assertOk();
        $this->asOwnerA()->putJson("/api/v1/stores/{$this->storeA->id}/themes/settings", [
            'theme_id' => $this->minimalThemeId,
            'settings' => ['accent' => '#222222'],
            'source' => 'autosave',
        ])->assertOk();
        $this->asOwnerA()->putJson("/api/v1/stores/{$this->storeA->id}/themes/settings", [
            'theme_id' => $this->minimalThemeId,
            'settings' => ['accent' => '#222222'],
            'source' => 'autosave',
        ])->assertOk();

        $history = $this->asOwnerA()
            ->getJson("/api/v1/stores/{$this->storeA->id}/themes/{$this->minimalThemeId}/revisions")
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $oldestRevisionId = $history->json('data.1.id');

        $this->asOwnerA()->postJson("/api/v1/stores/{$this->storeA->id}/themes/{$this->minimalThemeId}/revisions/{$oldestRevisionId}/restore")
            ->assertOk()
            ->assertJsonPath('data.settings.accent', '#111111');
        $this->assertDatabaseHas('store_theme_revisions', [
            'store_id' => $this->storeA->id,
            'source' => 'restore',
        ]);

        $this->asOwnerB()
            ->getJson("/api/v1/stores/{$this->storeA->id}/themes/{$this->minimalThemeId}/revisions")
            ->assertForbidden();
    }

    public function test_my_store_dynamic_theme_routes_keep_the_injected_store_parameter_first(): void
    {
        $this->asOwnerA()
            ->getJson("/api/v1/my-store/themes/{$this->defaultThemeId}")
            ->assertOk()
            ->assertJsonPath('theme.id', $this->defaultThemeId);

        $this->asOwnerA()->putJson('/api/v1/my-store/themes/settings', [
            'theme_id' => $this->defaultThemeId,
            'settings' => ['primary_color' => '#123456', 'base_font_size' => 16],
            'source' => 'manual',
        ])->assertOk();

        $history = $this->asOwnerA()
            ->getJson("/api/v1/my-store/themes/{$this->defaultThemeId}/revisions")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->asOwnerA()
            ->postJson("/api/v1/my-store/themes/{$this->defaultThemeId}/revisions/{$history->json('data.0.id')}/restore")
            ->assertOk();
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
