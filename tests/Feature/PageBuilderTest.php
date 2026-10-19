<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StorePage;
use App\Models\StorePageRevision;
use App\Models\StorePageSection;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Storefront\StorefrontContextBuilder;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageBuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        app(ThemeRegistry::class)->registerFromFile(resource_path('themes/storefront/naseem.json'));

        [$this->ownerA, $this->storeA] = $this->makeStore('nike');
        [, $this->storeB] = $this->makeStore('adidas');
    }

    private function makeStore(string $slug): array
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $store = Store::create(['owner_user_id' => $user->id, 'owner_type' => 'merchant', 'name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'USD', 'status' => 'active']);
        StoreDomain::create(['store_id' => $store->id, 'host' => "{$slug}.sellchase.com", 'type' => 'subdomain', 'is_primary' => true]);
        app(StoreThemeService::class)->installAndActivateDefault($store);

        return [$user, $store];
    }

    private function ownerA(): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->ownerA));
    }

    private function ownerB(): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->storeB->owner));
    }

    private function createPage(array $attrs = []): int
    {
        return $this->ownerA()->postJson("/api/v1/stores/{$this->storeA->id}/pages", array_merge(['title' => 'About Us'], $attrs))
            ->assertCreated()->json('data.id');
    }

    public function test_page_crud(): void
    {
        $id = $this->createPage(['slug' => 'about']);
        $this->assertDatabaseHas('store_pages', ['id' => $id, 'store_id' => $this->storeA->id, 'slug' => 'about', 'status' => 'draft']);

        $this->ownerA()->getJson("/api/v1/stores/{$this->storeA->id}/pages")->assertOk()->assertJsonPath('meta.total', 1);
        $this->ownerA()->getJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}")->assertOk()->assertJsonPath('data.slug', 'about');

        $this->ownerA()->putJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}", ['title' => 'About', 'seo' => ['title' => 'About | Nike']])
            ->assertOk()->assertJsonPath('data.title', 'About');

        $this->ownerA()->deleteJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}")->assertOk();
        $this->assertDatabaseMissing('store_pages', ['id' => $id]);
    }

    public function test_hosting_compatible_post_metadata_and_layout_writes_publish_on_both_tenant_prefixes(): void
    {
        $id = $this->createPage(['slug' => 'post-writes']);
        foreach (["/api/v1/stores/{$this->storeA->id}", '/api/v1/my-store'] as $index => $prefix) {
            $this->ownerA()->postJson("{$prefix}/pages/{$id}", ['title' => 'Saved through POST '.$index])
                ->assertOk()->assertJsonPath('data.title', 'Saved through POST '.$index);
            $this->ownerA()->postJson("{$prefix}/pages/{$id}/sections", ['sections' => [['type' => 'hero-banner', 'settings' => ['heading' => 'Own heading']]]])
                ->assertOk()->assertJsonPath('data.sections.0.type', 'hero-banner');
            $this->ownerA()->postJson("{$prefix}/pages/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        }
        $this->assertDatabaseHas('store_pages', ['id' => $id, 'title' => 'Saved through POST 1', 'status' => 'published']);
        $context = app(StorefrontContextBuilder::class)->buildPage($this->storeA, StorePage::findOrFail($id));
        $this->assertSame('hero-banner', $context['page']['sections'][0]['type']);
    }

    public function test_post_writes_keep_validation_and_revision_conflict_checks_before_mutation(): void
    {
        $id = $this->createPage(['title' => 'Preserved title']);
        $base = "/api/v1/my-store/pages/{$id}";
        $this->ownerA()->postJson($base.'/sections', ['sections' => [['type' => 'hero-banner']]])->assertOk();
        $revisions = StorePageRevision::where('store_page_id', $id)->count();
        $this->ownerA()->postJson($base, ['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->ownerA()->postJson($base.'/sections', ['sections' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('sections');
        $this->ownerA()->postJson($base.'/sections', ['expected_checksum' => str_repeat('0', 64), 'sections' => []])->assertConflict();
        $this->assertSame($revisions, StorePageRevision::where('store_page_id', $id)->count());
        $this->assertSame(1, StorePageSection::where('store_page_id', $id)->count());
        $this->assertDatabaseHas('store_pages', ['id' => $id, 'title' => 'Preserved title']);
    }

    public function test_post_aliases_do_not_grant_another_owner_or_anonymous_caller_access(): void
    {
        $id = $this->createPage(['title' => 'Private title']);
        foreach (['' => ['title' => 'Unauthorized'], '/sections' => ['sections' => []]] as $suffix => $body) {
            $this->ownerB()->postJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}{$suffix}", $body)->assertForbidden();
            $this->ownerB()->postJson("/api/v1/my-store/pages/{$id}{$suffix}", $body)->assertNotFound();
            $this->withToken('invalid')->postJson("/api/v1/my-store/pages/{$id}{$suffix}", $body)->assertUnauthorized();
        }
        $this->assertDatabaseHas('store_pages', ['id' => $id, 'title' => 'Private title']);
    }

    public function test_post_writes_require_the_same_explicit_page_permission_for_store_managers(): void
    {
        $id = $this->createPage();
        $manager = $this->ownerA()->postJson('/api/v1/my-store/managers', [
            'name' => 'Page manager fixture', 'email' => 'page-manager@example.test', 'password' => 'PrivateFixture123!', 'permissions' => [],
        ])->assertCreated()->json('data');
        $token = $this->postJson('/api/v1/auth/login', ['email' => $manager['email'], 'password' => 'PrivateFixture123!'])
            ->assertOk()->json('access_token');
        $base = "/api/v1/my-store/pages/{$id}";
        $this->withToken($token)->postJson($base, ['title' => 'Blocked'])->assertForbidden();
        $this->withToken($token)->postJson($base.'/sections', ['sections' => []])->assertForbidden();
        $editor = $this->ownerA()->postJson('/api/v1/my-store/managers', [
            'name' => 'Authorized page editor', 'email' => 'page-editor@example.test', 'password' => 'PrivateFixture123!', 'permissions' => ['store.pages.manage'],
        ])->assertCreated()->json('data');
        $token = $this->postJson('/api/v1/auth/login', ['email' => $editor['email'], 'password' => 'PrivateFixture123!'])
            ->assertOk()->json('access_token');
        $this->withToken($token)->postJson($base, ['title' => 'Authorized page editor'])->assertOk()->assertJsonPath('data.title', 'Authorized page editor');
        $this->withToken($token)->postJson($base.'/sections', ['sections' => [['type' => 'hero-banner']]])->assertOk();
    }

    public function test_slugs_are_unique_per_store_and_locale(): void
    {
        $this->createPage(['slug' => 'promo']);
        $second = $this->createPage(['slug' => 'promo']);
        $this->assertNotSame('promo', StorePage::find($second)->slug); // auto-suffixed
    }

    public function test_sections_sync_supports_add_reorder_duplicate_remove(): void
    {
        $id = $this->createPage();
        // add three sections incl. a duplicate hero
        $this->ownerA()->putJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}/sections", ['sections' => [
            ['type' => 'hero-banner', 'settings' => ['heading' => 'A']],
            ['type' => 'product-grid'],
            ['type' => 'hero-banner', 'settings' => ['heading' => 'B']], // duplicate type allowed
        ]])->assertOk()->assertJsonCount(3, 'data.sections');

        $sections = StorePageSection::where('store_page_id', $id)->orderBy('position')->get();
        $this->assertSame(['hero-banner', 'product-grid', 'hero-banner'], $sections->pluck('type')->all());
        $this->assertSame([0, 1, 2], $sections->pluck('position')->all());

        // reorder + remove one
        $this->ownerA()->putJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}/sections", ['sections' => [
            ['type' => 'product-grid'],
            ['type' => 'hero-banner', 'settings' => ['heading' => 'A']],
        ]])->assertOk()->assertJsonCount(2, 'data.sections');
        $this->assertSame(['product-grid', 'hero-banner'], StorePageSection::where('store_page_id', $id)->orderBy('position')->pluck('type')->all());
    }

    public function test_unsupported_section_types_are_dropped(): void
    {
        $id = $this->createPage();
        $this->ownerA()->putJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}/sections", ['sections' => [
            ['type' => 'hero-banner'],
            ['type' => 'totally-unknown'],   // not in theme sections_schema -> dropped
            ['type' => 'product-grid'],
        ]])->assertOk()->assertJsonCount(2, 'data.sections');
    }

    public function test_hidden_sections_are_saved_but_excluded_from_the_public_render_context(): void
    {
        $id = $this->createPage(['slug' => 'visibility']);
        $this->ownerA()->putJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}/sections", ['sections' => [
            ['type' => 'hero-banner', 'settings' => ['heading' => 'Visible'], 'is_visible' => true],
            ['type' => 'product-grid', 'is_visible' => false],
        ]])->assertOk()->assertJsonPath('data.sections.1.is_visible', false);
        $this->ownerA()->postJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}/publish")->assertOk();

        $context = app(StorefrontContextBuilder::class)
            ->buildPage($this->storeA, StorePage::query()->findOrFail($id));
        $this->assertCount(1, $context['page']['sections']);
        $this->assertSame('hero-banner', $context['page']['sections'][0]['type']);
    }

    public function test_builder_schema_endpoint_returns_theme_section_types(): void
    {
        $this->ownerA()->getJson("/api/v1/stores/{$this->storeA->id}/pages/schema")
            ->assertOk()->assertJsonPath('theme.key', 'naseem')
            ->assertJsonStructure(['sections_schema' => ['hero-banner', 'hero-slider', 'product-grid']]);
    }

    public function test_publish_schedule_unpublish_workflow(): void
    {
        $id = $this->createPage();
        $this->ownerA()->postJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertTrue(StorePage::find($id)->isPubliclyVisible());
        $this->assertDatabaseHas('store_page_publications', ['store_page_id' => $id, 'store_id' => $this->storeA->id, 'version' => 1]);

        $this->ownerA()->postJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}/unpublish")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertFalse(StorePage::find($id)->isPubliclyVisible());

        $this->ownerA()->postJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}/schedule", ['publish_at' => now()->addDay()->toISOString()])
            ->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->assertFalse(StorePage::find($id)->isPubliclyVisible()); // future -> hidden
    }

    public function test_scheduled_pages_auto_publish_when_due(): void
    {
        $id = $this->createPage();
        $this->ownerA()->postJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}/schedule", ['publish_at' => now()->subMinute()->toISOString()]);

        $this->assertSame('scheduled', StorePage::find($id)->status);
        $this->artisan('store-pages:publish-due')->assertSuccessful();
        $this->assertSame('published', StorePage::find($id)->status);
    }

    public function test_revisions_are_saved_and_restorable(): void
    {
        $id = $this->createPage(['slug' => 'r']);
        $this->ownerA()->putJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}/sections", ['sections' => [['type' => 'hero-banner']]]);
        $this->ownerA()->putJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}", ['title' => 'Changed']);
        $this->ownerA()->putJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}/sections", ['sections' => [['type' => 'product-grid'], ['type' => 'rich-text']]]);

        $revs = $this->ownerA()->getJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}/revisions")->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(3, count($revs));

        // restore the earliest revision (the one snapshotting the single-banner layout)
        $target = collect($revs)->firstWhere('sections_count', 1);
        $this->ownerA()->postJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}/revisions/{$target['id']}/restore")
            ->assertOk()->assertJsonCount(1, 'data.sections');
    }

    public function test_draft_preview_is_signed_and_isolated(): void
    {
        $id = $this->createPage(['slug' => 'secret']);
        $res = $this->ownerA()->postJson("/api/v1/stores/{$this->storeA->id}/pages/{$id}/preview")->assertOk();
        $this->assertStringContainsString('/pages/secret?__preview=', $res->json('preview_url'));

        $token = explode('__preview=', $res->json('preview_url'))[1];
        // draft is hidden publicly, visible with the token
        $this->get('http://nike.sellchase.com/pages/secret')->assertNotFound();
        $this->get("http://nike.sellchase.com/pages/secret?__preview={$token}")->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_non_owner_cannot_touch_another_stores_pages(): void
    {
        $id = $this->createPage();

        $this->ownerB()->getJson("/api/v1/stores/{$this->storeA->id}/pages")->assertForbidden();
        $this->ownerB()->postJson("/api/v1/stores/{$this->storeA->id}/pages", ['title' => 'x'])->assertForbidden();

        // Owner A's page id is not reachable under Store B's scope.
        $this->ownerB()->getJson("/api/v1/stores/{$this->storeB->id}/pages/{$id}")->assertNotFound();
    }
}
