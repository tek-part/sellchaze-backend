<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreFunnel;
use App\Models\StorePage;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreFunnelsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        app(ThemeRegistry::class)->registerFromFile(resource_path('themes/storefront/naseem.json'));
        $this->store = $this->makeStore('funnel');
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->store->owner_user_id, 'name' => 'Canvas bag', 'slug' => 'canvas-bag', 'description' => 'A washable bag.', 'price' => 25, 'is_active' => true]);
        $this->asOwner($this->store);
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

    private function asOwner(Store $store): void
    {
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($store->owner));
    }

    private function createFunnel(array $extra = [])
    {
        return $this->postJson('/api/v1/my-store/funnels', array_merge(['title' => 'Bag campaign', 'slug' => 'bag-campaign', 'locale' => 'en', 'product_id' => $this->product->id, 'template_key' => 'spotlight'], $extra));
    }

    public function test_published_funnel_resolves_live_product_slug_and_hides_inactive_product(): void
    {
        $pageId = $this->createFunnel()->assertCreated()->json('data.page_id');
        $this->postJson('/api/v1/my-store/pages/'.$pageId.'/publish')->assertOk();
        $url = 'http://funnel.sellchase.com/api/v1/storefront/funnels/bag-campaign';
        $this->getJson($url)->assertOk()->assertJsonPath('data.funnel_product_slug', 'canvas-bag');
        $this->product->update(['slug' => 'updated-bag']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.funnel_product_slug', 'updated-bag');
        $this->product->update(['is_active' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.funnel_product_slug', null);
        $this->getJson('http://other.sellchase.com/api/v1/storefront/funnels/bag-campaign')->assertNotFound();
    }

    public function test_create_persists_editable_draft_and_product_cta(): void
    {
        $funnel = $this->createFunnel()->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        $page = StorePage::forStore($this->store)->with('sections')->findOrFail($funnel['page_id']);
        $this->assertSame('landing', $page->template);
        $this->assertCount(2, $page->sections);
        $this->assertSame('#funnel-checkout', $page->sections[0]->settings['cta_url']);
        $this->getJson('/api/v1/my-store/funnels?search=Bag')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/my-store/funnels?search=Other')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/my-store/funnels?status=published')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_foreign_product_and_funnel_are_rejected(): void
    {
        $id = $this->createFunnel()->assertCreated()->json('data.id');
        $other = $this->makeStore('other');
        $this->asOwner($other);
        $this->createFunnel()->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->postJson('/api/v1/my-store/funnels/'.$id.'/duplicate')->assertNotFound();
        $this->getJson('/api/v1/my-store/funnels')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/stores/'.$this->store->id.'/funnels')->assertForbidden();
    }

    public function test_duplicate_is_an_independent_draft_of_published_funnel(): void
    {
        $source = $this->createFunnel()->assertCreated()->json('data');
        $this->postJson('/api/v1/my-store/pages/'.$source['page_id'].'/publish')->assertOk();
        $copy = $this->postJson('/api/v1/my-store/funnels/'.$source['id'].'/duplicate')->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        $this->assertNotSame($source['page_id'], $copy['page_id']);
        $this->assertNotSame($source['slug'], $copy['slug']);
        $this->putJson('/api/v1/my-store/pages/'.$copy['page_id'].'/sections', ['sections' => [['type' => 'hero-banner', 'settings' => ['heading' => 'New campaign']]]])->assertOk();
        $this->assertSame('published', StorePage::forStore($this->store)->findOrFail($source['page_id'])->status);
        $this->assertDatabaseHas('store_page_publications', ['store_page_id' => $source['page_id']]);
        $this->assertDatabaseMissing('store_page_publications', ['store_page_id' => $copy['page_id']]);
        $this->getJson('/api/v1/my-store/pages/'.$source['page_id'])->assertOk()->assertJsonPath('data.sections.0.settings.heading', 'Canvas bag');
    }

    public function test_invalid_template_locale_and_slug_leave_no_partial_records(): void
    {
        $this->createFunnel(['template_key' => 'unknown', 'locale' => 'xx', 'slug' => '../bad'])->assertUnprocessable()->assertJsonValidationErrors(['template_key', 'locale', 'slug']);
        $this->assertDatabaseCount('store_funnels', 0);
        $this->assertDatabaseCount('store_pages', 0);
    }

    public function test_no_tenant_scope_returns_no_funnels(): void
    {
        $this->createFunnel()->assertCreated();
        app(CurrentStore::class)->set(null);
        $this->assertSame(0, StoreFunnel::query()->count());
    }

    public function test_public_funnel_uses_published_snapshot_and_unpublishes(): void
    {
        $funnel = $this->createFunnel()->assertCreated()->json('data');
        $url = 'http://funnel.sellchase.com/api/v1/storefront/pages/'.$funnel['slug'];
        $this->getJson($url)->assertNotFound();
        $this->postJson('/api/v1/my-store/pages/'.$funnel['page_id'].'/publish')->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.sections.0.settings.cta_url', '#funnel-checkout');
        $this->putJson('/api/v1/my-store/pages/'.$funnel['page_id'].'/sections', ['sections' => [['type' => 'hero-banner', 'settings' => ['heading' => 'Draft only']]]])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.sections.0.settings.heading', 'Canvas bag');
        $this->postJson('/api/v1/my-store/pages/'.$funnel['page_id'].'/unpublish')->assertOk();
        $this->getJson($url)->assertNotFound();
    }

    public function test_template_uses_selected_locale_and_product_image(): void
    {
        $this->product->image = 'https://example.com/bag.jpg';
        $this->product->setTranslations('name', ['en' => 'Canvas bag', 'ar' => 'حقيبة قماش']);
        $this->product->save();
        $funnel = $this->createFunnel(['locale' => 'ar'])->assertCreated()->json('data');
        $this->getJson('/api/v1/my-store/pages/'.$funnel['page_id'])->assertOk()
            ->assertJsonPath('data.sections.0.settings.heading', 'حقيبة قماش')
            ->assertJsonPath('data.sections.0.settings.image', 'https://example.com/bag.jpg');
    }

    private function aiInput(array $extra = []): array
    {
        return array_merge(['title' => 'Bag campaign', 'slug' => 'bag-ai', 'locale' => 'ar', 'product_id' => $this->product->id, 'template_key' => 'spotlight', 'generation' => ['language' => 'Arabic', 'dialect' => 'Egyptian', 'product_name' => 'Canvas bag', 'description' => 'A washable cotton bag with reinforced handles for everyday shopping.']], $extra);
    }

    private function enableAi(): void
    {
        config(['services.funnel_ai.api_key' => 'test-key', 'services.funnel_ai.model' => 'configured-model']);
        Http::preventStrayRequests();
    }

    private function aiResponse(): array
    {
        return ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
            'heading' => 'Your everyday bag', 'text' => 'Washable cotton with reinforced handles.', 'cta_label' => 'Order now',
            'details_heading' => 'Made for shopping', 'paragraphs' => ['<script>alert(1)</script>', 'A washable bag.'],
            'faq_heading' => 'Questions', 'faqs' => [['question' => 'Can I wash it?', 'answer' => 'Yes, it is washable.']],
        ])]]]]];
    }

    public function test_ai_copy_becomes_an_editable_unpublished_draft_with_safe_links(): void
    {
        $this->enableAi();
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->aiResponse())]);
        $this->getJson('/api/v1/my-store/funnels/templates')->assertOk()->assertJsonPath('ai.available', true)->assertDontSee('test-key');
        $funnel = $this->postJson('/api/v1/my-store/funnels/generate', $this->aiInput())->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        Http::assertSent(function ($request) {
            $brief = json_decode($request['input'][1]['content'], true);

            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request['model'] === 'configured-model' && $request['store'] === false
                && $request['text']['format']['strict'] === true
                && $brief['dialect'] === 'Egyptian' && count($brief) === 4;
        });
        $page = $this->getJson('/api/v1/my-store/pages/'.$funnel['page_id'])->assertOk()
            ->assertJsonPath('data.sections.0.settings.heading', 'Your everyday bag')
            ->assertJsonPath('data.sections.0.settings.cta_url', '#funnel-checkout')->json('data');
        $this->assertCount(3, $page['sections']);
        $this->assertStringNotContainsString('<script>', $page['sections'][1]['settings']['body']);
        $this->assertDatabaseCount('store_page_publications', 0);
        $this->putJson('/api/v1/my-store/pages/'.$funnel['page_id'].'/sections', ['sections' => [['type' => 'hero-banner', 'settings' => ['heading' => 'Reviewed copy']]]])->assertOk();
    }

    public function test_ai_missing_configuration_fails_without_creating_a_draft(): void
    {
        config(['services.funnel_ai.api_key' => '', 'services.funnel_ai.model' => '']);
        Http::fake();
        $this->getJson('/api/v1/my-store/funnels/templates')->assertOk()->assertJsonPath('ai.available', false);
        $this->postJson('/api/v1/my-store/funnels/generate', $this->aiInput())->assertStatus(503);
        Http::assertNothingSent();
        $this->assertDatabaseCount('store_pages', 0);
        $this->createFunnel()->assertCreated();
    }

    public function test_ai_rejects_invalid_briefs_and_foreign_products_before_provider_call(): void
    {
        $this->enableAi();
        Http::fake();
        $this->postJson('/api/v1/my-store/funnels/generate', $this->aiInput(['generation' => ['language' => 'ar']]))->assertUnprocessable();
        $other = $this->makeStore('other');
        $this->asOwner($other);
        $this->postJson('/api/v1/my-store/funnels/generate', $this->aiInput())->assertUnprocessable()->assertJsonValidationErrors('product_id');
        Http::assertNothingSent();
        $this->assertDatabaseCount('store_pages', 0);
    }

    public function test_ai_requires_page_management_permission_for_employees(): void
    {
        $this->enableAi();
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->aiResponse())]);
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($employee));
        $this->postJson('/api/v1/my-store/funnels/generate', $this->aiInput())->assertForbidden();
        Http::assertNothingSent();
        $employee->givePermissionTo('store.pages.manage');
        // Existing JWT authentication caches user permission relations for 30 seconds.
        $this->travel(31)->seconds();
        $this->postJson('/api/v1/my-store/funnels/generate', $this->aiInput())->assertCreated();
    }

    public function test_ai_daily_budget_is_shared_by_store_and_manual_creation_still_works(): void
    {
        $this->enableAi();
        config(['services.funnel_ai.per_day' => 1]);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->aiResponse())]);
        $this->postJson('/api/v1/my-store/funnels/generate', $this->aiInput())->assertCreated();
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $employee->givePermissionTo('store.pages.manage');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($employee));
        $this->postJson('/api/v1/my-store/funnels/generate', $this->aiInput())->assertStatus(429)->assertHeader('Retry-After');
        Http::assertSentCount(1);
        $this->createFunnel()->assertCreated();
        $this->assertDatabaseCount('store_funnels', 2);
    }

    public function test_ai_concurrent_request_is_rejected_without_provider_spend(): void
    {
        $this->enableAi();
        Http::fake();
        $lock = Cache::lock('funnel-ai:'.$this->store->id.':lock', 90);
        $this->assertTrue($lock->get());
        try {
            $this->postJson('/api/v1/my-store/funnels/generate', $this->aiInput())->assertStatus(429);
            Http::assertNothingSent();
            $this->assertDatabaseCount('store_pages', 0);
        } finally {
            $lock->release();
        }
    }

    #[DataProvider('failedAiResponses')]
    public function test_ai_provider_failures_never_leave_partial_pages(array $payload, int $providerStatus, int $expectedStatus): void
    {
        $this->enableAi();
        Http::fake(['api.openai.com/v1/responses' => Http::response($payload, $providerStatus)]);
        $this->postJson('/api/v1/my-store/funnels/generate', $this->aiInput())->assertStatus($expectedStatus)->assertDontSee('secret-provider-detail');
        $this->assertDatabaseCount('store_pages', 0);
        $this->assertDatabaseCount('store_funnels', 0);
        // Failures release the lock, so a later request is not stuck as in progress.
        $lock = Cache::lock('funnel-ai:'.$this->store->id.':lock', 90);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public static function failedAiResponses(): array
    {
        return [
            'provider error' => [['error' => 'secret-provider-detail'], 500, 502],
            'incomplete' => [['status' => 'incomplete', 'output' => []], 200, 502],
            'missing text' => [['status' => 'completed', 'output' => []], 200, 502],
            'invalid output shape' => [['status' => 'completed', 'output' => 'not-an-array'], 200, 502],
            'invalid schema' => [['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{}']]]]], 200, 502],
            'refusal' => [['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'secret-provider-detail']]]]], 200, 422],
        ];
    }

    public function test_ai_connection_timeout_leaves_no_draft_and_does_not_retry(): void
    {
        $this->enableAi();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('secret-provider-detail');
        });
        $this->postJson('/api/v1/my-store/funnels/generate', $this->aiInput())->assertStatus(504)->assertDontSee('secret-provider-detail');
        $this->assertSame(1, $attempts);
        $this->assertDatabaseCount('store_pages', 0);
    }

    public function test_public_funnel_routes_hide_drafts_and_allow_only_signed_tenant_preview(): void
    {
        $funnel = $this->createFunnel()->assertCreated()->assertJsonPath('data.public_path', '/funnels/bag-campaign')->json('data');
        $this->getJson('http://funnel.sellchase.com/api/v1/storefront/funnels/bag-campaign')->assertNotFound();
        $this->get('http://funnel.sellchase.com/funnels/bag-campaign')->assertNotFound();
        $preview = $this->postJson('/api/v1/my-store/pages/'.$funnel['page_id'].'/preview')->assertOk()->json('preview_url');
        $this->assertStringContainsString('/funnels/bag-campaign?', $preview);
        $this->get($preview)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('http://funnel.sellchase.com/funnels/bag-campaign?__preview=invalid')->assertNotFound();
        $this->makeStore('other');
        $this->get(str_replace('funnel.sellchase.com', 'other.sellchase.com', $preview))->assertNotFound();
    }

    public function test_published_funnel_and_legacy_page_alias_share_canonical_url(): void
    {
        $funnel = $this->createFunnel()->assertCreated()->json('data');
        $this->postJson('/api/v1/my-store/pages/'.$funnel['page_id'].'/publish')->assertOk();
        $shell = tempnam(sys_get_temp_dir(), 'funnel-shell-');
        file_put_contents($shell, '<html><head><title>Store</title></head><body><div id="storefront-root"></div><script type="module" src="/assets/storefront.js"></script></body></html>');
        config(['sellchase.storefront.spa_shell' => $shell, 'sellchase.storefront.spa_origin' => 'https://sellchaze.com', 'sellchase.storefront.ssr_url' => null]);
        try {
            foreach (['funnels', 'pages'] as $kind) {
                $this->getJson('http://funnel.sellchase.com/api/v1/storefront/'.$kind.'/bag-campaign')->assertOk()
                    ->assertJsonPath('data.public_path', '/funnels/bag-campaign')
                    ->assertJsonPath('data.sections.0.settings.heading', 'Canvas bag');
                $this->get('http://funnel.sellchase.com/'.$kind.'/bag-campaign')->assertOk()
                    ->assertHeader('X-Storefront-Renderer', 'spa')
                    ->assertSee('rel="canonical" href="https://funnel.sellchase.com/funnels/bag-campaign"', false);
            }
        } finally {
            unlink($shell);
        }
        $this->postJson('/api/v1/my-store/pages/'.$funnel['page_id'].'/unpublish')->assertOk();
        $this->getJson('http://funnel.sellchase.com/api/v1/storefront/funnels/bag-campaign')->assertNotFound();
        $this->get('http://funnel.sellchase.com/funnels/bag-campaign')->assertNotFound();
    }

    public function test_funnel_slug_and_copy_remain_at_last_publication_until_republished(): void
    {
        $funnel = $this->createFunnel()->assertCreated()->json('data');
        $path = '/api/v1/my-store/pages/'.$funnel['page_id'];
        $this->postJson($path.'/publish')->assertOk();
        $this->putJson($path, ['title' => 'Unpublished title', 'slug' => 'renamed-campaign'])->assertOk()
            ->assertJsonPath('data.public_path', '/funnels/bag-campaign')
            ->assertJsonPath('data.preview_path', '/funnels/renamed-campaign');
        $public = 'http://funnel.sellchase.com/api/v1/storefront/funnels/';
        $this->getJson($public.'bag-campaign')->assertOk()->assertJsonPath('data.title', 'Bag campaign')
            ->assertJsonPath('data.public_path', '/funnels/bag-campaign');
        $this->getJson($public.'renamed-campaign')->assertNotFound();
        $this->postJson($path.'/publish')->assertOk();
        $this->getJson($public.'renamed-campaign')->assertOk()->assertJsonPath('data.title', 'Unpublished title')
            ->assertJsonPath('data.public_path', '/funnels/renamed-campaign');
        $this->getJson($public.'bag-campaign')->assertNotFound();
    }

    public function test_funnel_locale_selection_excludes_ordinary_page_siblings_and_other_stores(): void
    {
        $funnel = $this->createFunnel()->assertCreated()->json('data');
        $this->postJson('/api/v1/my-store/pages/'.$funnel['page_id'].'/publish')->assertOk();
        $ordinary = $this->postJson('/api/v1/my-store/pages', ['title' => 'Ordinary Arabic page', 'slug' => 'bag-campaign', 'locale' => 'ar', 'template' => 'page'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/my-store/pages/'.$ordinary.'/publish')->assertOk();
        $this->getJson('http://funnel.sellchase.com/api/v1/storefront/pages/bag-campaign?lang=ar')->assertOk()->assertJsonPath('data.title', 'Ordinary Arabic page');
        $this->getJson('http://funnel.sellchase.com/api/v1/storefront/funnels/bag-campaign?lang=ar')->assertOk()->assertJsonPath('data.title', 'Bag campaign');
        $this->makeStore('other');
        $this->getJson('http://other.sellchase.com/api/v1/storefront/funnels/bag-campaign')->assertNotFound();
        $this->get('http://other.sellchase.com/funnels/bag-campaign')->assertNotFound();
    }

    public function test_ordinary_published_page_and_signed_preview_cannot_be_opened_as_funnel(): void
    {
        $page = $this->postJson('/api/v1/my-store/pages', ['title' => 'About', 'slug' => 'about', 'locale' => 'en', 'template' => 'page'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/my-store/pages/'.$page.'/publish')->assertOk();
        $this->getJson('http://funnel.sellchase.com/api/v1/storefront/pages/about')->assertOk()->assertJsonPath('data.public_path', '/pages/about');
        $this->getJson('http://funnel.sellchase.com/api/v1/storefront/funnels/about')->assertNotFound();
        $this->get('http://funnel.sellchase.com/funnels/about')->assertNotFound();
        $preview = $this->postJson('/api/v1/my-store/pages/'.$page.'/preview')->assertOk()->json('preview_url');
        $this->get(str_replace('/pages/about', '/funnels/about', $preview))->assertNotFound();
    }
}
