<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StorePage;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreFunnelWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        app(ThemeRegistry::class)->registerFromFile(resource_path('themes/storefront/naseem.json'));
        $this->store = $this->makeStore('workspace');
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->store->owner_user_id, 'name' => 'Own bag', 'slug' => 'own-bag', 'price' => 25, 'is_active' => true]);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->store->owner));
    }

    private function makeStore(string $slug): Store
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $store = Store::create(['owner_user_id' => $user->id, 'owner_type' => 'merchant', 'name' => $slug, 'slug' => $slug, 'status' => 'active', 'currency' => 'USD', 'default_locale' => 'en', 'supported_locales' => ['en', 'ar']]);
        StoreDomain::create(['store_id' => $store->id, 'host' => $slug.'.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(StoreThemeService::class)->installAndActivateDefault($store);

        return $store;
    }

    private function createFunnel(string $title = 'Bag campaign', string $slug = 'bag-campaign', array $extra = []): array
    {
        return $this->postJson('/api/v1/my-store/funnels', array_merge(['title' => $title, 'slug' => $slug, 'locale' => 'en', 'product_id' => $this->product->id, 'template_key' => 'spotlight'], $extra))->assertCreated()->json('data');
    }

    public function test_list_sorts_and_paginates_with_stable_codes_and_canonical_public_links(): void
    {
        $bravo = $this->createFunnel('Bravo', 'bravo');
        $alpha = $this->createFunnel('Alpha', 'alpha', ['public_id' => (string) Str::uuid()]);
        $this->createFunnel('Charlie', 'charlie');
        $this->assertTrue(Str::isUuid($alpha['code']));
        $this->assertNotSame($alpha['code'], $bravo['code']);
        $this->assertFalse($alpha['is_public']);
        $this->postJson('/api/v1/my-store/pages/'.$alpha['page_id'].'/publish')->assertOk();
        $this->putJson('/api/v1/my-store/pages/'.$alpha['page_id'], ['slug' => 'alpha-draft'])->assertOk();
        $this->getJson('/api/v1/my-store/funnels?sort=title&direction=asc&per_page=2')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')->assertJsonPath('data.0.title', 'Alpha')
            ->assertJsonPath('data.0.slug', 'alpha-draft')->assertJsonPath('data.0.public_url', 'https://workspace.sellchase.com/funnels/alpha')
            ->assertJsonPath('data.0.is_public', true)->assertJsonPath('data.0.code', $alpha['code'])
            ->assertJsonPath('data.1.title', 'Bravo')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.from', 1)->assertJsonPath('meta.to', 2);
        $this->getJson('/api/v1/my-store/funnels?sort=title&direction=asc&per_page=2&page=2')->assertOk()->assertJsonPath('data.0.title', 'Charlie')->assertJsonPath('meta.from', 3);
        $this->getJson('/api/v1/my-store/funnels?sort=slug&direction=desc')->assertOk()->assertJsonPath('data.0.title', 'Charlie');
        $this->getJson('/api/v1/my-store/funnels?search='.$alpha['code'].'&status=published')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $alpha['id']);
        $this->getJson('/api/v1/my-store/funnels?search=alpha-draft')->assertOk()->assertJsonCount(1, 'data');
        foreach (['sort=title;drop', 'direction=sideways', 'per_page=101', 'page=0'] as $query) {
            $this->getJson('/api/v1/my-store/funnels?'.$query)->assertUnprocessable();
        }
    }

    public function test_lookup_does_not_create_a_draft_and_code_clone_keeps_an_independent_composition(): void
    {
        $source = $this->createFunnel('Source', 'source', ['template_key' => 'glasses-en']);
        $this->postJson('/api/v1/my-store/pages/'.$source['page_id'].'/publish')->assertOk();
        $before = DB::table('store_pages')->count();
        $this->getJson('/api/v1/my-store/funnels/by-code/'.$source['code'])->assertOk()->assertJsonPath('data.id', $source['id']);
        $this->assertSame($before, DB::table('store_pages')->count());
        $otherProduct = Product::create(['store_id' => $this->store->id, 'user_id' => $this->store->owner_user_id, 'name' => 'New item', 'slug' => 'new-item', 'price' => 30, 'is_active' => true]);
        $copy = $this->createFunnel('Own copy', 'own-copy', ['clone_code' => $source['code'], 'template_key' => 'glasses-en', 'product_id' => $otherProduct->id]);
        $this->assertNotSame($source['code'], $copy['code']);
        $this->assertSame('draft', $copy['status']);
        $this->assertSame($otherProduct->id, $copy['product']['id']);
        $originalPage = StorePage::forStore($this->store)->with('sections')->findOrFail($source['page_id']);
        $copyPage = StorePage::forStore($this->store)->with('sections')->findOrFail($copy['page_id']);
        $this->assertCount($originalPage->sections->count(), $copyPage->sections);
        $this->assertSame([], array_intersect($originalPage->sections->pluck('id')->all(), $copyPage->sections->pluck('id')->all()));
        $first = $copyPage->sections->first();
        $first->settings = ['heading' => 'Changed copy'] + $first->settings;
        $first->save();
        $this->assertNotSame('Changed copy', $originalPage->sections->first()->fresh()->settings['heading']);
        $this->assertDatabaseHas('store_page_publications', ['store_page_id' => $source['page_id']]);
        $this->assertDatabaseMissing('store_page_publications', ['store_page_id' => $copy['page_id']]);
        $this->getJson('http://workspace.sellchase.com/api/v1/storefront/funnels/own-copy')->assertNotFound();
        $this->postJson('/api/v1/my-store/funnels', ['title' => 'Wrong template', 'slug' => 'wrong', 'locale' => 'en', 'product_id' => $this->product->id, 'template_key' => 'minimal', 'clone_code' => $source['code']])->assertUnprocessable();
    }

    public function test_foreign_codes_and_mixed_delete_selections_cannot_cross_store_boundaries(): void
    {
        $source = $this->createFunnel();
        $other = $this->makeStore('other-workspace');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($other->owner));
        $otherProduct = Product::create(['store_id' => $other->id, 'user_id' => $other->owner_user_id, 'name' => 'Other', 'slug' => 'other-item', 'price' => 12, 'is_active' => true]);
        $own = $this->createFunnel('Other campaign', 'other-campaign', ['product_id' => $otherProduct->id]);
        $before = DB::table('store_funnels')->count();
        $this->getJson('/api/v1/my-store/funnels/by-code/'.$source['code'])->assertNotFound();
        $this->postJson('/api/v1/my-store/funnels', ['title' => 'Forbidden', 'slug' => 'forbidden', 'locale' => 'en', 'product_id' => $otherProduct->id, 'template_key' => 'spotlight', 'clone_code' => $source['code']])->assertNotFound();
        $this->postJson('/api/v1/my-store/funnels/bulk-delete', ['ids' => [$own['id'], $source['id']]])->assertNotFound();
        $this->assertSame($before, DB::table('store_funnels')->count());
        $this->getJson('/api/v1/my-store/funnels?search=Bag')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('store_pages', ['id' => $source['page_id']]);
        $this->assertDatabaseHas('store_pages', ['id' => $own['page_id']]);
    }

    public function test_confirmed_bulk_delete_removes_only_selected_compositions_and_publications(): void
    {
        $remove = $this->createFunnel('Remove', 'remove');
        $keep = $this->createFunnel('Keep', 'keep');
        $this->postJson('/api/v1/my-store/pages/'.$remove['page_id'].'/publish')->assertOk();
        $this->getJson('http://workspace.sellchase.com/api/v1/storefront/funnels/remove')->assertOk();
        $this->postJson('/api/v1/my-store/funnels/bulk-delete', ['ids' => [$remove['id'], $remove['id']]])->assertUnprocessable();
        $this->postJson('/api/v1/my-store/funnels/bulk-delete', ['ids' => []])->assertUnprocessable();
        $this->postJson('/api/v1/my-store/funnels/bulk-delete', ['ids' => [$remove['id']]])->assertOk()->assertJsonPath('deleted', 1);
        $this->assertDatabaseMissing('store_funnels', ['id' => $remove['id']]);
        $this->assertDatabaseMissing('store_pages', ['id' => $remove['page_id']]);
        $this->assertDatabaseMissing('store_page_sections', ['store_page_id' => $remove['page_id']]);
        $this->assertDatabaseMissing('store_page_publications', ['store_page_id' => $remove['page_id']]);
        $this->assertDatabaseHas('store_funnels', ['id' => $keep['id'], 'public_id' => $keep['code']]);
        $this->getJson('http://workspace.sellchase.com/api/v1/storefront/funnels/remove')->assertNotFound();
        $this->assertDatabaseHas('products', ['id' => $this->product->id]);
    }

    public function test_employee_requires_page_permission_for_codes_and_deletion(): void
    {
        $source = $this->createFunnel();
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($employee));
        $this->getJson('/api/v1/my-store/funnels/by-code/'.$source['code'])->assertForbidden();
        $this->postJson('/api/v1/my-store/funnels/bulk-delete', ['ids' => [$source['id']]])->assertForbidden();
        $employee->givePermissionTo('store.pages.manage');
        Cache::flush();
        $this->getJson('/api/v1/my-store/funnels/by-code/'.$source['code'])->assertOk();
        $this->postJson('/api/v1/my-store/funnels/bulk-delete', ['ids' => [$source['id']]])->assertOk();
    }

    public function test_code_migration_backfills_legacy_rows_without_changing_publications_and_refuses_populated_rollback(): void
    {
        $source = $this->createFunnel();
        $this->postJson('/api/v1/my-store/pages/'.$source['page_id'].'/publish')->assertOk();
        DB::table('store_funnels')->where('id', $source['id'])->update(['public_id' => null]);
        $row = (array) DB::table('store_funnels')->where('id', $source['id'])->first();
        unset($row['public_id']);
        $publication = (array) DB::table('store_page_publications')->where('store_page_id', $source['page_id'])->first();
        $migration = require database_path('migrations/2026_10_18_070000_add_public_codes_to_store_funnels.php');
        $migration->down();
        $migration->up();
        $after = (array) DB::table('store_funnels')->where('id', $source['id'])->first();
        $this->assertTrue(Str::isUuid($after['public_id']));
        unset($after['public_id']);
        $this->assertSame($row, $after);
        $this->assertSame($publication, (array) DB::table('store_page_publications')->where('store_page_id', $source['page_id'])->first());
        try {
            $migration->down();
            $this->fail('Populated code rollback must be refused.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Cannot remove funnel codes', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('store_funnels', 'public_id'));
        $this->assertDatabaseHas('store_funnels', ['id' => $source['id']]);
    }

    public function test_details_update_live_binding_without_publishing_draft_or_replacing_sections(): void
    {
        $source = $this->createFunnel('Original', 'original', ['template_key' => 'glasses-en']);
        $url = '/api/v1/my-store/funnels/'.$source['id'];
        $this->postJson('/api/v1/my-store/pages/'.$source['page_id'].'/publish')->assertOk();
        $sections = DB::table('store_page_sections')->where('store_page_id', $source['page_id'])->get()->toArray();
        $publications = DB::table('store_page_publications')->where('store_page_id', $source['page_id'])->get()->toArray();
        $public = 'http://workspace.sellchase.com/api/v1/storefront/funnels/original';
        $this->getJson($public)->assertOk()->assertJsonPath('data.funnel_product_slug', 'own-bag');
        $details = $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertJsonPath('funnel_base_url', 'https://workspace.sellchase.com/funnels/')->json();
        $replacement = Product::create(['store_id' => $this->store->id, 'user_id' => $this->store->owner_user_id, 'name' => 'Replacement', 'slug' => 'replacement', 'price' => 45, 'is_active' => true]);
        $changed = $this->putJson($url, ['title' => 'Draft title', 'slug' => 'draft-url', 'product_id' => $replacement->id, 'version' => $details['version'], 'template_key' => 'minimal', 'public_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.code', $source['code'])->assertJsonPath('data.template_key', 'glasses-en')
            ->assertJsonPath('data.public_url', 'https://workspace.sellchase.com/funnels/original')->json();
        $this->assertNotSame($details['version'], $changed['version']);
        $this->getJson($public)->assertOk()->assertJsonPath('data.title', 'Original')->assertJsonPath('data.slug', 'original')->assertJsonPath('data.funnel_product_slug', 'replacement');
        $this->getJson('http://workspace.sellchase.com/api/v1/storefront/funnels/draft-url')->assertNotFound();
        $this->assertEquals($sections, DB::table('store_page_sections')->where('store_page_id', $source['page_id'])->get()->toArray());
        $this->assertEquals($publications, DB::table('store_page_publications')->where('store_page_id', $source['page_id'])->get()->toArray());
        $revisions = DB::table('store_page_revisions')->where('store_page_id', $source['page_id'])->count();
        $this->putJson($url, ['title' => 'Draft title', 'slug' => 'draft-url', 'product_id' => $replacement->id, 'version' => $changed['version']])->assertOk()->assertJsonPath('version', $changed['version']);
        $this->assertSame($revisions, DB::table('store_page_revisions')->where('store_page_id', $source['page_id'])->count());
    }

    public function test_stale_or_invalid_details_are_rejected_atomically_and_current_version_can_retry(): void
    {
        $source = $this->createFunnel();
        $url = '/api/v1/my-store/funnels/'.$source['id'];
        $details = $this->getJson($url)->assertOk()->json();
        $payload = ['title' => 'Attempt', 'slug' => 'attempt', 'product_id' => $this->product->id, 'version' => $details['version']];
        $this->putJson('/api/v1/my-store/pages/'.$source['page_id'], ['title' => 'Other session'])->assertOk();
        $revisions = DB::table('store_page_revisions')->count();
        $this->putJson($url, $payload)->assertConflict();
        $this->assertDatabaseHas('store_pages', ['id' => $source['page_id'], 'title' => 'Other session', 'slug' => 'bag-campaign']);
        $this->assertSame($revisions, DB::table('store_page_revisions')->count());
        $payload['version'] = $this->getJson($url)->assertOk()->json('version');
        $this->putJson($url, array_merge($payload, ['product_id' => 999999]))->assertUnprocessable();
        $this->putJson($url, array_merge($payload, ['slug' => '../invalid']))->assertUnprocessable();
        $this->putJson($url, array_merge($payload, ['version' => '']))->assertUnprocessable();
        $this->assertSame($revisions, DB::table('store_page_revisions')->count());
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.title', 'Attempt');
        $this->assertDatabaseMissing('store_page_publications', ['store_page_id' => $source['page_id']]);
    }

    public function test_details_require_permission_and_reject_foreign_funnels_and_products(): void
    {
        $source = $this->createFunnel();
        $url = '/api/v1/my-store/funnels/'.$source['id'];
        $details = $this->getJson($url)->assertOk()->json();
        $other = $this->makeStore('other-details');
        $foreign = Product::create(['store_id' => $other->id, 'user_id' => $other->owner_user_id, 'name' => 'Foreign', 'slug' => 'foreign', 'price' => 10]);
        $payload = ['title' => 'Attempt', 'slug' => 'attempt', 'product_id' => $foreign->id, 'version' => $details['version']];
        $this->putJson($url, $payload)->assertUnprocessable();
        $this->assertDatabaseHas('store_pages', ['id' => $source['page_id'], 'title' => 'Bag campaign']);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($other->owner));
        $this->getJson($url)->assertNotFound();
        $this->putJson($url, $payload)->assertNotFound();
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($employee));
        $this->getJson($url)->assertForbidden();
        $this->putJson($url, $payload)->assertForbidden();
        $employee->givePermissionTo('store.pages.manage');
        Cache::flush();
        $this->getJson($url)->assertOk();
        $this->putJson($url, array_merge($payload, ['product_id' => $this->product->id]))->assertOk();
    }

    public function test_product_lookup_searches_enabled_translations_without_leaking_foreign_or_slugless_products(): void
    {
        $this->product->setTranslations('name', ['en' => 'Everyday bag', 'ar' => 'حقيبة يومية'])->save();
        $other = $this->makeStore('other-search');
        Product::create(['store_id' => $other->id, 'user_id' => $other->owner_user_id, 'name' => 'Everyday foreign', 'slug' => 'foreign', 'price' => 10]);
        Product::create(['store_id' => $this->store->id, 'user_id' => $this->store->owner_user_id, 'name' => 'Everyday without link', 'price' => 10]);
        foreach (['Everyday', 'حقيبة', ''] as $term) {
            $this->getJson('/api/v1/my-store/funnels/products?'.http_build_query(['search' => $term]))->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->product->id);
        }
    }
}
