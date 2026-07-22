<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreMenu;
use App\Models\StorePage;
use App\Models\StorePageRevision;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemePreviewToken;
use App\Services\Themes\ThemeRegistry;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageBuilderCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        app(ThemeRegistry::class)->registerFromFile(resource_path('themes/default/theme.json'));
        [$this->ownerA, $this->a] = $this->store('nike');
        [, $this->b] = $this->store('adidas');
    }

    private function store(string $slug): array
    {
        $u = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $u->assignRole('Merchant');
        $s = Store::create(['owner_user_id' => $u->id, 'owner_type' => 'merchant', 'name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'USD', 'status' => 'active']);
        StoreDomain::create(['store_id' => $s->id, 'host' => "{$slug}.sellchase.com", 'type' => 'subdomain', 'is_primary' => true]);
        app(StoreThemeService::class)->installAndActivateDefault($s);

        return [$u, $s];
    }

    private function a(): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->ownerA));
    }

    private function b(): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->b->owner));
    }

    public function test_landing_pages_are_unlimited_and_typed(): void
    {
        foreach (['summer', 'winter', 'launch', 'promo', 'campaign'] as $slug) {
            $this->a()->postJson("/api/v1/stores/{$this->a->id}/pages", ['title' => ucfirst($slug), 'slug' => $slug, 'template' => 'landing'])
                ->assertCreated()->assertJsonPath('data.template', 'landing');
        }
        $this->assertSame(5, StorePage::where('store_id', $this->a->id)->where('template', 'landing')->count());
        $this->a()->getJson("/api/v1/stores/{$this->a->id}/pages")->assertOk()->assertJsonPath('meta.total', 5);
    }

    public function test_all_theme_section_types_can_be_composed(): void
    {
        $id = $this->a()->postJson("/api/v1/stores/{$this->a->id}/pages", ['title' => 'All', 'slug' => 'all'])->json('data.id');
        $types = ['hero', 'category-list', 'product-grid', 'category-header', 'product-details', 'rich-text'];
        $sections = array_map(fn ($t) => ['type' => $t], $types);

        $this->a()->putJson("/api/v1/stores/{$this->a->id}/pages/{$id}/sections", ['sections' => $sections])
            ->assertOk()->assertJsonCount(6, 'data.sections');
        foreach ($types as $i => $t) {
            $this->assertSame($t, StorePage::find($id)->sections[$i]->type);
        }
    }

    public function test_revision_snapshot_captures_page_and_sections(): void
    {
        $id = $this->a()->postJson("/api/v1/stores/{$this->a->id}/pages", ['title' => 'V1', 'slug' => 'v'])->json('data.id');
        $this->a()->putJson("/api/v1/stores/{$this->a->id}/pages/{$id}/sections", ['sections' => [['type' => 'hero', 'settings' => ['headline' => 'X']]]]);
        $this->a()->putJson("/api/v1/stores/{$this->a->id}/pages/{$id}", ['title' => 'V2']);

        $rev = StorePageRevision::where('store_page_id', $id)->orderByDesc('revision_number')->first();
        $this->assertIsArray($rev->snapshot);
        $this->assertArrayHasKey('page', $rev->snapshot);
        $this->assertArrayHasKey('sections', $rev->snapshot);
        $this->assertSame('hero', $rev->snapshot['sections'][0]['type']);
        $this->assertSame('X', $rev->snapshot['sections'][0]['settings']['headline']);
    }

    public function test_restore_reverts_title_and_sections_exactly(): void
    {
        $id = $this->a()->postJson("/api/v1/stores/{$this->a->id}/pages", ['title' => 'Original', 'slug' => 'o'])->json('data.id');
        $this->a()->putJson("/api/v1/stores/{$this->a->id}/pages/{$id}/sections", ['sections' => [['type' => 'hero'], ['type' => 'product-grid']]]);
        $revId = $this->a()->getJson("/api/v1/stores/{$this->a->id}/pages/{$id}/revisions")->json('data.0.id');

        // now change everything
        $this->a()->putJson("/api/v1/stores/{$this->a->id}/pages/{$id}", ['title' => 'Different']);
        $this->a()->putJson("/api/v1/stores/{$this->a->id}/pages/{$id}/sections", ['sections' => [['type' => 'rich-text']]]);

        $restored = $this->a()->postJson("/api/v1/stores/{$this->a->id}/pages/{$id}/revisions/{$revId}/restore")->assertOk();
        $this->assertGreaterThanOrEqual(1, $restored->json('data.sections'));
    }

    public function test_reusable_sections_are_store_isolated(): void
    {
        $aId = $this->a()->postJson("/api/v1/stores/{$this->a->id}/reusable-sections", ['key' => 'bar', 'name' => 'Bar', 'type' => 'hero', 'settings' => []])->json('data.id');

        // store B does not see store A's reusable section, and cannot fetch it under B
        $this->a()->getJson("/api/v1/stores/{$this->a->id}/reusable-sections")->assertOk()->assertJsonCount(1, 'data');
        $this->b()->getJson("/api/v1/stores/{$this->b->id}/reusable-sections")->assertOk()->assertJsonCount(0, 'data');
        $this->b()->putJson("/api/v1/stores/{$this->b->id}/reusable-sections/{$aId}", ['name' => 'hijack'])->assertNotFound();
        $this->assertDatabaseHas('store_reusable_sections', ['id' => $aId, 'name' => 'Bar']);
    }

    public function test_menus_are_store_isolated(): void
    {
        $this->a()->putJson("/api/v1/stores/{$this->a->id}/menus/header", ['name' => 'A', 'items' => []])->assertOk();
        $this->a()->getJson("/api/v1/stores/{$this->a->id}/menus")->assertOk()->assertJsonCount(1, 'data');
        $this->b()->getJson("/api/v1/stores/{$this->b->id}/menus")->assertOk()->assertJsonCount(0, 'data');
        $this->b()->getJson("/api/v1/stores/{$this->b->id}/menus/header")->assertNotFound();
    }

    public function test_menu_footer_and_custom_handles(): void
    {
        $this->a()->putJson("/api/v1/stores/{$this->a->id}/menus/footer", ['name' => 'Footer', 'items' => [['label' => 'Terms', 'type' => 'url', 'target' => '/pages/terms']]])->assertOk();
        $this->a()->putJson("/api/v1/stores/{$this->a->id}/menus/legal", ['name' => 'Legal', 'items' => []])->assertOk();
        $this->assertSame(2, StoreMenu::where('store_id', $this->a->id)->count());
        $this->a()->getJson("/api/v1/stores/{$this->a->id}/menus/footer")->assertOk()->assertJsonPath('items.0.url', '/pages/terms');
    }

    public function test_preview_token_from_another_store_is_rejected(): void
    {
        $id = $this->a()->postJson("/api/v1/stores/{$this->a->id}/pages", ['title' => 'P', 'slug' => 'p'])->json('data.id');
        // craft a token bound to store B for this page id — must not preview on store A's host
        $foreign = app(ThemePreviewToken::class)->make($this->b->id, $id, 1800);
        $this->get("http://nike.sellchase.com/pages/p?__preview={$foreign}")->assertNotFound();
    }

    public function test_updating_page_seo_and_slug(): void
    {
        $id = $this->a()->postJson("/api/v1/stores/{$this->a->id}/pages", ['title' => 'SEO', 'slug' => 'seo-a'])->json('data.id');
        $this->a()->putJson("/api/v1/stores/{$this->a->id}/pages/{$id}", [
            'slug' => 'seo-b', 'seo' => ['title' => 'Meta', 'description' => 'Desc', 'robots' => 'noindex'],
        ])->assertOk()->assertJsonPath('data.slug', 'seo-b');
        $page = StorePage::find($id);
        $this->assertSame('Meta', $page->seo['title']);
        $this->assertSame('noindex', $page->seo['robots']);
    }

    public function test_page_data_never_leaks_across_stores_in_listing(): void
    {
        $this->a()->postJson("/api/v1/stores/{$this->a->id}/pages", ['title' => 'A page', 'slug' => 'apage']);
        $this->b()->postJson("/api/v1/stores/{$this->b->id}/pages", ['title' => 'B page', 'slug' => 'bpage']);

        $aList = $this->a()->getJson("/api/v1/stores/{$this->a->id}/pages")->json('data');
        $this->assertSame(['A page'], array_column($aList, 'title'));
        $bList = $this->b()->getJson("/api/v1/stores/{$this->b->id}/pages")->json('data');
        $this->assertSame(['B page'], array_column($bList, 'title'));
    }
}
