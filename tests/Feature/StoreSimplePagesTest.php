<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StorePage;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Storefront\PublishedPageResolver;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StoreSimplePagesTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private array $auth;

    private string $api = '/api/v1/my-store/simple-pages';

    private string $public = 'http://pages.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Pages', 'slug' => 'pages', 'currency' => 'EGP', 'status' => 'active', 'default_locale' => 'ar', 'supported_locales' => ['ar', 'en']]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'pages.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
    }

    private function body(array $changes = []): array
    {
        return array_replace(['version' => 0, 'title' => ['ar' => 'سياسة المتجر', 'en' => 'Store policy'], 'content' => ['ar' => '<h2>أهلاً</h2><p>محتوى منشور</p>', 'en' => '<p>Published policy</p>'],
            'slug' => 'store-policy', 'active' => true, 'show_in_header' => false, 'show_in_footer' => true, 'position' => 0], $changes);
    }

    private function create(array $changes = []): array
    {
        return $this->postJson($this->api, $this->body($changes), $this->auth)->assertCreated()->json('data');
    }

    public function test_inactive_drafts_are_private_and_activation_publishes_bilingual_content(): void
    {
        $draft = $this->create(['active' => false]);
        $this->getJson($this->public.'/pages/store-policy')->assertNotFound();
        $this->getJson($this->public)->assertJsonPath('navigation.footer', []);
        $this->assertDatabaseCount('store_page_publications', 0);
        $this->getJson($this->api.'/'.$draft['id'], $this->auth)->assertOk()->assertJsonPath('data.content.en', '<p>Published policy</p>');
        $this->putJson($this->api.'/'.$draft['id'], $this->body(['version' => 1]), $this->auth)->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson($this->public.'/pages/store-policy?lang=en')->assertOk()->assertJsonPath('data.template', 'simple')->assertJsonPath('data.title', 'Store policy')->assertJsonPath('data.content_html', '<p>Published policy</p>')->assertJsonMissingPath('data.simple_configuration')->assertJsonMissingPath('data.content');
        $this->getJson($this->public.'/pages/store-policy?lang=ar')->assertJsonPath('data.title', 'سياسة المتجر')->assertJsonPath('data.content_html', '<h2>أهلاً</h2><p>محتوى منشور</p>');
        $this->getJson($this->public.'/funnels/store-policy')->assertNotFound();
        $this->assertDatabaseCount('store_page_publications', 1);
    }

    public function test_safe_rich_media_and_styles_survive_without_executable_content(): void
    {
        $html = '<h2 style="text-align:center;color:rgb(1,2,3);position:fixed">Title</h2><p onclick="bad()"><strong>Bold</strong><span style="background-color:#abc;background-image:url(https://bad.test)">Color</span></p><script>alert(1)</script><iframe src="https://bad.test"></iframe><img src="/safe.jpg" onerror="bad()"><video src="https://example.test/safe.mp4" autoplay></video><a href="javascript:bad()">bad link</a>';
        $saved = $this->create(['content' => ['ar' => '', 'en' => $html], 'title' => ['ar' => '', 'en' => '<Title>']]);
        $body = $this->getJson($this->public.'/pages/store-policy?lang=ar')->assertOk()->assertJsonPath('data.title', '<Title>')->json('data.content_html');
        foreach (['text-align:center', 'color:#010203', 'background-color:#aabbcc', '<strong>Bold</strong>', '/safe.jpg', 'safe.mp4', 'controls', 'playsinline'] as $safe) {
            $this->assertStringContainsString($safe, $body);
        }
        foreach (['<script', 'alert(1)', '<iframe', 'onclick', 'onerror', 'javascript:', 'position:', 'background-image', 'autoplay'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $body);
        }
        $this->assertSame($saved['content']['en'], $body);
    }

    public function test_stale_updates_are_atomic_and_slug_changes_invalidate_old_urls_and_links(): void
    {
        $page = $this->create(['show_in_header' => true]);
        $this->putJson($this->api.'/'.$page['id'], $this->body(['version' => 1, 'slug' => 'new-policy', 'show_in_header' => true]), $this->auth)->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($this->api.'/'.$page['id'], $this->body(['version' => 1, 'active' => false]), $this->auth)->assertStatus(409);
        $this->getJson($this->api.'/'.$page['id'], $this->auth)->assertJsonPath('data.slug', 'new-policy')->assertJsonPath('data.active', true);
        $this->getJson($this->public.'/pages/store-policy')->assertNotFound();
        $this->getJson($this->public.'/pages/new-policy')->assertOk();
        $this->getJson($this->public)->assertJsonPath('navigation.header.0.url', '/pages/new-policy');
        $this->assertDatabaseCount('store_page_publications', 2);
    }

    public function test_navigation_uses_published_flags_order_and_labels_with_manual_deduplication(): void
    {
        $last = $this->create(['slug' => 'last', 'position' => 20, 'title' => ['ar' => 'الأخير', 'en' => 'Last'], 'show_in_header' => true]);
        $first = $this->create(['slug' => 'first', 'position' => 1, 'title' => ['ar' => 'الأول', 'en' => 'First'], 'show_in_header' => true, 'show_in_footer' => false]);
        $this->create(['slug' => 'hidden', 'position' => 0, 'active' => false, 'show_in_header' => true]);
        $this->getJson($this->public.'?lang=en')->assertJsonCount(2, 'navigation.header')->assertJsonPath('navigation.header.0.label', 'First')->assertJsonPath('navigation.header.1.label', 'Last')->assertJsonPath('navigation.footer.0.label', 'Last');
        // Direct draft edits do not override the already published title/content/placement.
        $model = StorePage::withoutGlobalScopes()->findOrFail($last['id']);
        $config = $model->simple_configuration;
        $config['title']['en'] = 'Private draft';
        $config['content']['en'] = '<p>Secret</p>';
        $config['show_in_header'] = false;
        $model->update(['simple_configuration' => $config]);
        $this->getJson($this->public.'?lang=en')->assertJsonPath('navigation.header.1.label', 'Last');
        $this->getJson($this->public.'/pages/last?lang=en')->assertJsonPath('data.title', 'Last')->assertJsonPath('data.content_html', '<p>Published policy</p>');
        $this->putJson('/api/v1/my-store/menus/header', ['name' => 'Header', 'items' => [['label' => 'Manual', 'type' => 'internal', 'target' => 'pages/first']]], $this->auth)->assertOk();
        $this->getJson($this->public.'?lang=en')->assertJsonCount(2, 'navigation.header')->assertJsonPath('navigation.header.0.label', 'Manual')->assertJsonPath('navigation.header.1.label', 'Last');
        $this->putJson($this->api.'/'.$first['id'], $this->body(['version' => 1, 'slug' => 'first', 'active' => false]), $this->auth)->assertOk();
        $this->getJson($this->public.'/pages/first')->assertNotFound();
        $this->getJson($this->public)->assertJsonCount(1, 'navigation.footer');
    }

    public function test_validation_and_builder_url_collisions_do_not_partially_write_or_allow_builder_bypass(): void
    {
        $page = $this->create();
        foreach ([$this->body(['title' => ['ar' => ' ', 'en' => '']]), $this->body(['slug' => '../x']), $this->body(['slug' => 'shipping']), $this->body(['position' => -1]), $this->body(['version' => 1]), $this->body(['content' => ['ar' => str_repeat('x', 20001), 'en' => '']]), $this->body(['show_in_header' => 'true'])] as $body) {
            $this->postJson($this->api, $body, $this->auth)->assertStatus(in_array($body['version'], [1], true) ? 409 : 422);
        }
        $this->getJson('/api/v1/my-store/pages', $this->auth)->assertJsonCount(0, 'data');
        foreach (['', '/sections', '/publish', '/unpublish', '/schedule', '/preview', '/revisions'] as $suffix) {
            if (in_array($suffix, ['', '/revisions'], true)) {
                $this->getJson('/api/v1/my-store/pages/'.$page['id'].$suffix, $this->auth)->assertNotFound();
            } elseif ($suffix === '/sections') {
                $this->putJson('/api/v1/my-store/pages/'.$page['id'].$suffix, ['sections' => []], $this->auth)->assertNotFound();
            } else {
                $this->postJson('/api/v1/my-store/pages/'.$page['id'].$suffix, ['publish_at' => now()->addDay()->toISOString(), 'theme_id' => 1], $this->auth)->assertNotFound();
            }
        }
        $builder = $this->postJson('/api/v1/my-store/pages', ['title' => 'Builder', 'slug' => 'store-policy', 'locale' => 'en'], $this->auth)->assertCreated()->json('data');
        $this->assertNotSame('store-policy', $builder['slug']);
        $this->postJson($this->api, $this->body(['slug' => $builder['slug']]), $this->auth)->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->assertDatabaseCount('store_pages', 2);
    }

    public function test_bulk_archiving_is_atomic_versioned_and_restoration_never_republishes(): void
    {
        $a = $this->create(['slug' => 'one']);
        $b = $this->create(['slug' => 'two']);
        $this->postJson($this->api.'/archive', ['pages' => [['id' => $a['id'], 'version' => 1], ['id' => $b['id'], 'version' => 2]]], $this->auth)->assertStatus(409);
        $this->getJson($this->public.'/pages/one')->assertOk();
        $this->getJson($this->public.'/pages/two')->assertOk();
        $this->postJson($this->api.'/archive', ['pages' => [['id' => $a['id'], 'version' => 1], ['id' => $b['id'], 'version' => 1]]], $this->auth)->assertOk();
        $this->getJson($this->api, $this->auth)->assertJsonPath('meta.total', 0);
        $this->getJson($this->api.'?archived=1', $this->auth)->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.version', 2);
        $this->getJson($this->public.'/pages/one')->assertNotFound();
        $this->getJson($this->public)->assertJsonPath('navigation.footer', []);
        $this->putJson($this->api.'/'.$a['id'], $this->body(['version' => 2, 'slug' => 'one']), $this->auth)->assertStatus(409);
        $this->postJson($this->api.'/'.$a['id'].'/restore', ['version' => 1], $this->auth)->assertStatus(409);
        $this->postJson($this->api.'/'.$a['id'].'/restore', ['version' => 2], $this->auth)->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.version', 3);
        $this->getJson($this->public.'/pages/one')->assertNotFound();
        $this->putJson($this->api.'/'.$a['id'], $this->body(['version' => 3, 'slug' => 'one']), $this->auth)->assertOk()->assertJsonPath('data.version', 4);
        $this->getJson($this->public.'/pages/one')->assertOk();
    }

    public function test_scope_permissions_and_foreign_ids_are_checked_for_every_mutation(): void
    {
        $page = $this->create();
        $foreignOwner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $foreignOwner->assignRole('Merchant');
        $foreign = Store::create(['owner_user_id' => $foreignOwner->id, 'owner_type' => 'merchant', 'name' => 'Foreign', 'slug' => 'foreign', 'currency' => 'EGP', 'status' => 'active']);
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($foreignOwner)];
        $this->getJson('/api/v1/stores/'.$foreign->id.'/simple-pages/'.$page['id'], $headers)->assertNotFound();
        $this->putJson('/api/v1/stores/'.$foreign->id.'/simple-pages/'.$page['id'], $this->body(['version' => 1]), $headers)->assertNotFound();
        $this->postJson('/api/v1/stores/'.$foreign->id.'/simple-pages/archive', ['pages' => [['id' => $page['id'], 'version' => 1]]], $headers)->assertNotFound();
        $this->postJson('/api/v1/stores/'.$foreign->id.'/simple-pages/'.$page['id'].'/restore', ['version' => 1], $headers)->assertNotFound();
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee)];
        $this->getJson($this->api, $headers)->assertForbidden();
        $this->postJson($this->api, $this->body(['slug' => 'employee']), $headers)->assertForbidden();
        $employee->givePermissionTo('store.pages.manage');
        $this->travel(31)->seconds(); // Existing authorization snapshot expires before changed grants apply.
        $this->getJson($this->api.'/'.$page['id'], $headers)->assertOk();
        $this->putJson($this->api.'/'.$page['id'], $this->body(['version' => 1]), $headers)->assertOk();
        $admin = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $admin->assignRole('Admin');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($admin)];
        $this->getJson('/api/v1/stores/'.$this->store->id.'/simple-pages/'.$page['id'], $headers)->assertOk();
    }

    public function test_public_blade_and_json_use_same_localized_publication_and_bounded_pagination(): void
    {
        $page = $this->create(['show_in_header' => true]);
        config(['sellchase.storefront.spa_shell' => '', 'sellchase.storefront.ssr_url' => '']);
        $this->get('http://pages.sellchase.com/pages/store-policy?lang=en')->assertOk()->assertSee('Store policy')->assertSee('Published policy')->assertSee('data-simple-page', false);
        $this->getJson($this->api.'?per_page=1&q=policy&sort=slug&direction=desc', $this->auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        $this->getJson($this->api.'?per_page=101', $this->auth)->assertUnprocessable();
        $this->getJson($this->api.'?sort=unknown', $this->auth)->assertUnprocessable();
        $model = StorePage::withoutGlobalScopes()->findOrFail($page['id']);
        $publication = app(PublishedPageResolver::class)->latestPublication($model);
        $this->assertSame('<p>Published policy</p>', $publication['page']['simple_configuration']['content']['en']);
        $this->assertSame(1, DB::table('store_page_publications')->count());
    }

    public function test_page_titles_cannot_terminate_inline_json_ld_scripts(): void
    {
        $title = '</script><script id="injected">alert(1)</script>';
        $this->create(['title' => ['ar' => '', 'en' => $title]]);
        config(['sellchase.storefront.spa_shell' => '', 'sellchase.storefront.ssr_url' => '']);
        $html = $this->get('http://pages.sellchase.com/pages/store-policy?lang=en')->assertOk()->getContent();
        $this->assertStringNotContainsString('<script id="injected">', $html);
        $this->assertSame(1, preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches));
        $json = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($title, $json['name']);
        $this->assertStringNotContainsString('</script>', $matches[1]);
    }

    public function test_list_search_sort_and_pagination_keep_boolean_placement_and_totals_correct(): void
    {
        $a = $this->create(['slug' => 'first', 'position' => 1, 'title' => ['ar' => 'أول', 'en' => 'First'], 'show_in_header' => true]);
        $b = $this->create(['slug' => 'second', 'position' => 2, 'title' => ['ar' => 'ثاني', 'en' => 'Second'], 'show_in_footer' => false]);
        $this->getJson($this->api.'?page=2&per_page=1', $this->auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b['id'])->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.total', 2);
        $this->getJson($this->api.'?q=أول', $this->auth)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $a['id']);
        $this->getJson($this->api.'?sort=show_in_header&direction=asc', $this->auth)->assertJsonPath('data.0.id', $b['id']);
        $this->getJson($this->api.'?sort=show_in_footer&direction=desc', $this->auth)->assertJsonPath('data.0.id', $a['id']);
        $this->postJson($this->api.'/archive', ['pages' => [['id' => $a['id'], 'version' => 1], ['id' => $a['id'], 'version' => 1]]], $this->auth)->assertUnprocessable();
        $this->assertDatabaseCount('store_page_publications', 2);
    }
}
