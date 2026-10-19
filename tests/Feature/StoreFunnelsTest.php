<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreFunnel;
use App\Models\StorePage;
use App\Models\StoreTheme;
use App\Models\ThemeVersion;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
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

    public function test_native_glasses_sections_survive_edit_publish_and_independent_duplicate(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $funnel = $this->createFunnel(['template_key' => 'glasses-'.$locale, 'locale' => $locale, 'slug' => 'glasses-'.$locale])->assertCreated()->json('data');
            $path = '/api/v1/my-store/pages/'.$funnel['page_id'];
            $page = $this->getJson($path)->assertOk()->assertJsonPath('data.native_funnel_template', 'glasses-'.$locale)->json('data');
            $this->assertCount(10, $page['sections']);
            $this->assertSame('glasses-hero', $page['sections'][0]['type']);
            $this->assertSame('Canvas bag', $page['sections'][0]['settings']['heading']);
            $this->assertSame('', $page['sections'][0]['settings']['expires_at']);
            $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertOk()->assertJsonStructure(['sections_schema' => ['glasses-purchase', 'glasses-faq']]);
            $sections = array_map(fn ($section) => Arr::only($section, ['type', 'settings', 'is_visible']), $page['sections']);
            $sections[0]['settings']['heading'] = 'Published campaign';
            $sections[8]['settings']['items'] = [['question' => 'Own question', 'answer' => 'Own answer']];
            $sections[8]['is_visible'] = true;
            $this->putJson($path.'/sections', ['sections' => $sections])->assertOk()->assertJsonCount(10, 'data.sections');
            $this->postJson($path.'/publish')->assertOk();
            $url = 'http://funnel.sellchase.com/api/v1/storefront/funnels/glasses-'.$locale.'?lang='.$locale;
            $this->getJson($url)->assertOk()->assertJsonPath('data.native_funnel_template', 'glasses-'.$locale)->assertJsonPath('data.sections.0.settings.heading', 'Published campaign');
            $sections[0]['settings']['heading'] = 'Unpublished change';
            $this->putJson($path.'/sections', ['sections' => $sections])->assertOk();
            $this->getJson($url)->assertOk()->assertJsonPath('data.sections.0.settings.heading', 'Published campaign');
            $copy = $this->postJson('/api/v1/my-store/funnels/'.$funnel['id'].'/duplicate')->assertCreated()->json('data');
            $this->getJson('/api/v1/my-store/pages/'.$copy['page_id'])->assertOk()->assertJsonCount(10, 'data.sections')->assertJsonPath('data.native_funnel_template', 'glasses-'.$locale)->assertJsonPath('data.sections.0.settings.heading', 'Unpublished change');
            $this->assertDatabaseMissing('store_page_publications', ['store_page_id' => $copy['page_id']]);
        }
    }

    public function test_signed_native_draft_read_is_bound_to_store_page_and_slug_and_is_not_cached(): void
    {
        $funnel = $this->createFunnel(['template_key' => 'glasses-en'])->assertCreated()->json('data');
        $preview = $this->postJson('/api/v1/my-store/pages/'.$funnel['page_id'].'/preview')->assertOk()->json('preview_url');
        parse_str(parse_url($preview, PHP_URL_QUERY), $query);
        $url = 'http://funnel.sellchase.com/api/v1/storefront/funnels/bag-campaign';
        $this->getJson($url)->assertNotFound();
        $this->getJson($url.'?__preview='.urlencode($query['__preview']))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertJsonPath('data.native_funnel_template', 'glasses-en')->assertJsonPath('data.funnel_product_slug', 'canvas-bag');
        $this->getJson(str_replace('bag-campaign', 'wrong-slug', $url).'?__preview='.urlencode($query['__preview']))->assertNotFound();
        $this->getJson($url.'?__preview='.urlencode($query['__preview'].'x'))->assertNotFound();
        $other = $this->makeStore('other-native');
        $this->getJson(str_replace('funnel.sellchase.com', 'other-native.sellchase.com', $url).'?__preview='.urlencode($query['__preview']))->assertNotFound();
        $this->asOwner($other);
        $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertNotFound();
    }

    public function test_native_schema_cannot_be_selected_by_ordinary_page_or_untrusted_template_parameter(): void
    {
        $this->getJson('/api/v1/my-store/pages/schema?template_key=glasses-en')->assertOk()->assertJsonMissingPath('sections_schema.glasses-purchase');
        $page = $this->postJson('/api/v1/my-store/pages', ['title' => 'Ordinary', 'slug' => 'ordinary', 'template' => 'landing', 'locale' => 'en'])->assertCreated()->json('data');
        $this->putJson('/api/v1/my-store/pages/'.$page['id'].'/sections', ['sections' => [['type' => 'glasses-purchase', 'settings' => []]]])->assertOk()->assertJsonCount(0, 'data.sections');
    }

    public function test_native_sections_and_revisions_are_independent_of_active_theme_library(): void
    {
        $funnel = $this->createFunnel(['template_key' => 'glasses-en'])->assertCreated()->json('data');
        $path = '/api/v1/my-store/pages/'.$funnel['page_id'];
        $version = StoreTheme::where('store_id', $this->store->id)->where('status', 'active')->value('theme_version_id');
        ThemeVersion::whereKey($version)->update(['sections_schema' => []]);
        Cache::flush();
        $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertOk()->assertJsonStructure(['sections_schema' => ['glasses-hero', 'glasses-faq']]);
        $this->putJson($path.'/sections', ['sections' => [['type' => 'glasses-faq', 'settings' => ['items' => [['question' => 'Own question', 'answer' => 'Own answer']]]]]])->assertOk()->assertJsonCount(1, 'data.sections');
        $revisions = $this->getJson($path.'/revisions')->assertOk()->json('data');
        $revision = collect($revisions)->firstWhere('sections_count', 10);
        $this->postJson($path.'/revisions/'.$revision['id'].'/restore')->assertOk()->assertJsonCount(10, 'data.sections')->assertJsonPath('data.sections.0.type', 'glasses-hero');
        $this->createFunnel(['template_key' => 'glasses-ar', 'slug' => 'independent-theme', 'locale' => 'ar'])->assertCreated();
        $this->assertDatabaseCount('store_order_items', 0);
    }

    public function test_native_layout_rejects_duplicate_checkout_without_discarding_the_saved_layout(): void
    {
        $funnel = $this->createFunnel(['template_key' => 'glasses-en'])->assertCreated()->json('data');
        $path = '/api/v1/my-store/pages/'.$funnel['page_id'];
        $this->putJson($path.'/sections', ['sections' => [['type' => 'glasses-purchase'], ['type' => 'glasses-purchase']]])->assertUnprocessable()->assertJsonValidationErrors('sections');
        $this->getJson($path)->assertOk()->assertJsonCount(10, 'data.sections');
    }

    public function test_native_template_catalog_links_to_read_only_localized_previews(): void
    {
        $templates = $this->getJson('/api/v1/my-store/funnels/templates')->assertOk()->json('data');
        $byKey = collect($templates)->keyBy('key');
        $this->assertSame('/funnel-templates/glasses-ar?preview=1&lang=ar', $byKey['glasses-ar']['preview_url']);
        $this->assertSame('/funnel-templates/glasses-en?preview=1&lang=en', $byKey['glasses-en']['preview_url']);
        foreach (['spotlight', 'story', 'minimal'] as $key) {
            $this->assertArrayNotHasKey('preview_url', $byKey[$key]);
        }
    }

    public function test_hair_revive_is_an_independent_editable_family_with_localized_publications(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $funnel = $this->createFunnel(['template_key' => 'hair-revive-'.$locale, 'locale' => $locale, 'slug' => 'hair-'.$locale])->assertCreated()->json('data');
            $path = '/api/v1/my-store/pages/'.$funnel['page_id'];
            $page = $this->getJson($path)->assertOk()->assertJsonPath('data.native_funnel_template', 'hair-revive-'.$locale)->json('data');
            $this->assertCount(15, $page['sections']);
            $this->assertSame('hair-hero', $page['sections'][0]['type']);
            $this->assertSame('', $page['sections'][0]['settings']['expires_at']);
            $this->assertSame([], $page['sections'][9]['settings']['items']);
            $schema = $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertOk()->json('sections_schema');
            $this->assertCount(11, $schema);
            $this->assertArrayNotHasKey('glasses-hero', $schema);
            $sections = array_map(fn ($section) => Arr::only($section, ['type', 'settings', 'is_visible']), $page['sections']);
            $sections[0]['settings']['heading'] = 'Own hair campaign';
            $sections[7]['is_visible'] = true;
            $sections[7]['settings']['items'] = [['heading' => 'Own details', 'text' => 'Our product photographs', 'before_image' => 'https://example.test/one.png', 'after_image' => 'https://example.test/two.png', 'before_label' => 'Packaging', 'after_label' => 'Details']];
            $this->putJson($path.'/sections', ['sections' => $sections])->assertOk()->assertJsonCount(15, 'data.sections')->assertJsonPath('data.sections.7.settings.items.0.before_label', 'Packaging');
            $this->postJson($path.'/publish')->assertOk();
            $url = 'http://funnel.sellchase.com/api/v1/storefront/funnels/hair-'.$locale.'?lang='.$locale;
            $this->getJson($url)->assertOk()->assertJsonPath('data.native_funnel_template', 'hair-revive-'.$locale)->assertJsonPath('data.funnel_product_slug', 'canvas-bag')->assertJsonPath('data.sections.0.settings.heading', 'Own hair campaign');
            $sections[0]['settings']['heading'] = 'Draft change';
            $this->putJson($path.'/sections', ['sections' => $sections])->assertOk();
            $this->getJson($url)->assertOk()->assertJsonPath('data.sections.0.settings.heading', 'Own hair campaign');
            $copy = $this->postJson('/api/v1/my-store/funnels/'.$funnel['id'].'/duplicate')->assertCreated()->json('data');
            $this->getJson('/api/v1/my-store/pages/'.$copy['page_id'])->assertOk()->assertJsonCount(15, 'data.sections')->assertJsonPath('data.native_funnel_template', 'hair-revive-'.$locale)->assertJsonPath('data.sections.0.settings.heading', 'Draft change');
            $this->assertDatabaseMissing('store_page_publications', ['store_page_id' => $copy['page_id']]);
        }
    }

    public function test_hair_revive_schema_survives_theme_changes_and_cannot_be_spoofed_or_duplicate_checkout(): void
    {
        $this->getJson('/api/v1/my-store/pages/schema?template_key=hair-revive-en')->assertOk()->assertJsonMissingPath('sections_schema.hair-purchase');
        $funnel = $this->createFunnel(['template_key' => 'hair-revive-en'])->assertCreated()->json('data');
        $path = '/api/v1/my-store/pages/'.$funnel['page_id'];
        $version = StoreTheme::where('store_id', $this->store->id)->where('status', 'active')->value('theme_version_id');
        ThemeVersion::whereKey($version)->update(['sections_schema' => []]);
        Cache::flush();
        $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertOk()->assertJsonStructure(['sections_schema' => ['hair-hero', 'hair-faq']]);
        $this->putJson($path.'/sections', ['sections' => [['type' => 'hair-purchase'], ['type' => 'hair-purchase']]])->assertUnprocessable();
        $this->getJson($path)->assertOk()->assertJsonCount(15, 'data.sections');
        $this->putJson($path.'/sections', ['sections' => [['type' => 'hair-statement', 'settings' => ['heading' => 'Changed']]]])->assertOk();
        $revisions = $this->getJson($path.'/revisions')->assertOk()->json('data');
        $revision = collect($revisions)->firstWhere('sections_count', 15);
        $this->postJson($path.'/revisions/'.$revision['id'].'/restore')->assertOk()->assertJsonCount(15, 'data.sections');
        $other = $this->makeStore('hair-foreign');
        $this->asOwner($other);
        $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertNotFound();
        $this->assertDatabaseCount('store_order_items', 0);
    }

    public function test_litter_robot_preserves_nested_specifications_and_independent_publication_in_both_locales(): void
    {
        $byKey = collect($this->getJson('/api/v1/my-store/funnels/templates')->assertOk()->json('data'))->keyBy('key');
        foreach (['ar', 'en'] as $locale) {
            $this->assertSame('/funnel-templates/litter-robot-'.$locale.'?preview=1&lang='.$locale, $byKey['litter-robot-'.$locale]['preview_url']);
            $funnel = $this->createFunnel(['template_key' => 'litter-robot-'.$locale, 'locale' => $locale, 'slug' => 'litter-'.$locale])->assertCreated()->json('data');
            $path = '/api/v1/my-store/pages/'.$funnel['page_id'];
            $page = $this->getJson($path)->assertOk()->assertJsonPath('data.native_funnel_template', 'litter-robot-'.$locale)->assertJsonCount(16, 'data.sections')->json('data');
            $this->assertSame('Canvas bag', $page['sections'][0]['settings']['heading']);
            $this->assertSame('', $page['sections'][0]['settings']['offer_value']);
            $this->assertSame('', $page['sections'][0]['settings']['expires_at']);
            $this->assertArrayHasKey('image', $page['sections'][11]['settings']);
            $schema = $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertOk()->json('sections_schema.litter-feature-rows.settings');
            $this->assertSame(['image', 'image_alt', 'items'], array_column($schema, 'id'));
            $this->assertSame(3, $schema[2]['max']);
            $this->assertSame(['heading', 'text'], array_column($schema[2]['item'], 'id'));
            $this->assertFalse($page['sections'][12]['is_visible']);
            $this->assertFalse($page['sections'][13]['is_visible']);
            $sections = $page['sections'];
            $sections[0]['settings']['heading'] = 'Own pet campaign';
            $sections[3]['is_visible'] = true;
            $sections[3]['settings']['items'] = [['image' => '/owned-product.png', 'image_alt' => 'Own product', 'labels' => array_merge([['label' => 'Width', 'value' => '30 cm']], array_fill(0, 6, ['label' => 'Detail', 'value' => 'Own specification']))]];
            $this->putJson($path.'/sections', ['sections' => $sections])->assertOk()->assertJsonPath('data.sections.3.settings.items.0.labels.0.value', '30 cm')->assertJsonCount(6, 'data.sections.3.settings.items.0.labels');
            $this->postJson($path.'/publish')->assertOk();
            $url = 'http://funnel.sellchase.com/api/v1/storefront/funnels/litter-'.$locale.'?lang='.$locale;
            $this->getJson($url)->assertOk()->assertJsonPath('data.native_funnel_template', 'litter-robot-'.$locale)->assertJsonPath('data.funnel_product_slug', 'canvas-bag')->assertJsonPath('data.sections.0.settings.heading', 'Own pet campaign');
            $sections[0]['settings']['heading'] = 'Draft change';
            $this->putJson($path.'/sections', ['sections' => $sections])->assertOk();
            $this->getJson($url)->assertOk()->assertJsonPath('data.sections.0.settings.heading', 'Own pet campaign');
            $copy = $this->postJson('/api/v1/my-store/funnels/'.$funnel['id'].'/duplicate')->assertCreated()->json('data');
            $this->getJson('/api/v1/my-store/pages/'.$copy['page_id'])->assertOk()->assertJsonCount(16, 'data.sections')->assertJsonPath('data.native_funnel_template', 'litter-robot-'.$locale)->assertJsonPath('data.sections.0.settings.heading', 'Draft change')->assertJsonPath('data.sections.3.settings.items.0.labels.0.label', 'Width');
            $this->assertDatabaseMissing('store_page_publications', ['store_page_id' => $copy['page_id']]);
        }
    }

    public function test_litter_robot_cannot_spoof_schema_cross_tenants_or_duplicate_checkout_and_restores_revisions(): void
    {
        $this->getJson('/api/v1/my-store/pages/schema?template_key=litter-robot-en')->assertOk()->assertJsonMissingPath('sections_schema.litter-purchase');
        $funnel = $this->createFunnel(['template_key' => 'litter-robot-en'])->assertCreated()->json('data');
        $path = '/api/v1/my-store/pages/'.$funnel['page_id'];
        $version = StoreTheme::where('store_id', $this->store->id)->where('status', 'active')->value('theme_version_id');
        ThemeVersion::whereKey($version)->update(['sections_schema' => []]);
        Cache::flush();
        $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertOk()->assertJsonStructure(['sections_schema' => ['litter-hero', 'litter-specifications', 'litter-feature-orbit', 'litter-faq']]);
        $this->putJson($path.'/sections', ['sections' => [['type' => 'litter-purchase'], ['type' => 'litter-purchase']]])->assertUnprocessable();
        $this->getJson($path)->assertOk()->assertJsonCount(16, 'data.sections');
        $this->putJson($path.'/sections', ['sections' => [['type' => 'litter-editorial', 'settings' => ['heading' => 'Changed']]]])->assertOk();
        $revisions = $this->getJson($path.'/revisions')->assertOk()->json('data');
        $revision = collect($revisions)->firstWhere('sections_count', 16);
        $this->postJson($path.'/revisions/'.$revision['id'].'/restore')->assertOk()->assertJsonCount(16, 'data.sections');
        $other = $this->makeStore('litter-foreign');
        $this->asOwner($other);
        $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertNotFound();
        $this->assertDatabaseCount('store_order_items', 0);
    }

    public function test_electric_biker_preserves_all_panels_and_publication_in_both_locales(): void
    {
        $byKey = collect($this->getJson('/api/v1/my-store/funnels/templates')->assertOk()->json('data'))->keyBy('key');
        foreach (['ar', 'en'] as $locale) {
            $this->assertSame('/funnel-templates/electric-biker-'.$locale.'?preview=1&lang='.$locale, $byKey['electric-biker-'.$locale]['preview_url']);
            $funnel = $this->createFunnel(['template_key' => 'electric-biker-'.$locale, 'locale' => $locale, 'slug' => 'electric-'.$locale])->assertCreated()->json('data');
            $path = '/api/v1/my-store/pages/'.$funnel['page_id'];
            $page = $this->getJson($path)->assertOk()->assertJsonPath('data.native_funnel_template', 'electric-biker-'.$locale)->assertJsonCount(11, 'data.sections')->json('data');
            $this->assertSame('Canvas bag', $page['sections'][0]['settings']['heading']);
            $this->assertSame('', $page['sections'][0]['settings']['expires_at']);
            $this->assertStringNotContainsString('electric-biker-hero', json_encode($page));
            $this->assertFalse($page['sections'][2]['is_visible']);
            $this->assertFalse($page['sections'][7]['is_visible']);
            $this->assertFalse($page['sections'][8]['is_visible']);
            $sections = $page['sections'];
            $sections[0]['settings']['heading'] = 'Own cycling campaign';
            $sections[8]['is_visible'] = true;
            $sections[8]['settings']['items'] = array_fill(0, 8, ['image' => '/owned-product.png', 'image_alt' => 'Own photograph', 'caption' => 'Owned caption']);
            $this->putJson($path.'/sections', ['sections' => $sections])->assertOk()->assertJsonCount(6, 'data.sections.8.settings.items')->assertJsonPath('data.sections.8.settings.items.0.caption', 'Owned caption');
            $this->postJson($path.'/publish')->assertOk();
            $url = 'http://funnel.sellchase.com/api/v1/storefront/funnels/electric-'.$locale.'?lang='.$locale;
            $this->getJson($url)->assertOk()->assertJsonPath('data.native_funnel_template', 'electric-biker-'.$locale)->assertJsonPath('data.funnel_product_slug', 'canvas-bag')->assertJsonPath('data.sections.0.settings.heading', 'Own cycling campaign');
            $sections[0]['settings']['heading'] = 'Draft change';
            $this->putJson($path.'/sections', ['sections' => $sections])->assertOk();
            $this->getJson($url)->assertOk()->assertJsonPath('data.sections.0.settings.heading', 'Own cycling campaign');
            $copy = $this->postJson('/api/v1/my-store/funnels/'.$funnel['id'].'/duplicate')->assertCreated()->json('data');
            $this->getJson('/api/v1/my-store/pages/'.$copy['page_id'])->assertOk()->assertJsonCount(11, 'data.sections')->assertJsonPath('data.native_funnel_template', 'electric-biker-'.$locale)->assertJsonPath('data.sections.0.settings.heading', 'Draft change')->assertJsonCount(6, 'data.sections.8.settings.items');
            $this->assertDatabaseMissing('store_page_publications', ['store_page_id' => $copy['page_id']]);
        }
    }

    public function test_electric_biker_schema_survives_theme_changes_and_rejects_spoofing_and_duplicate_checkout(): void
    {
        $this->getJson('/api/v1/my-store/pages/schema?template_key=electric-biker-en')->assertOk()->assertJsonMissingPath('sections_schema.electric-purchase');
        $funnel = $this->createFunnel(['template_key' => 'electric-biker-en'])->assertCreated()->json('data');
        $path = '/api/v1/my-store/pages/'.$funnel['page_id'];
        $version = StoreTheme::where('store_id', $this->store->id)->where('status', 'active')->value('theme_version_id');
        ThemeVersion::whereKey($version)->update(['sections_schema' => []]);
        Cache::flush();
        $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertOk()->assertJsonStructure(['sections_schema' => ['electric-hero', 'electric-stories', 'electric-mosaic', 'electric-faq']]);
        $this->putJson($path.'/sections', ['sections' => [['type' => 'electric-purchase'], ['type' => 'electric-purchase']]])->assertUnprocessable();
        $this->getJson($path)->assertOk()->assertJsonCount(11, 'data.sections');
        $this->putJson($path.'/sections', ['sections' => [['type' => 'electric-closing', 'settings' => ['heading' => 'Changed']]]])->assertOk();
        $revisions = $this->getJson($path.'/revisions')->assertOk()->json('data');
        $revision = collect($revisions)->firstWhere('sections_count', 11);
        $this->postJson($path.'/revisions/'.$revision['id'].'/restore')->assertOk()->assertJsonCount(11, 'data.sections');
        $other = $this->makeStore('electric-foreign');
        $this->asOwner($other);
        $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertNotFound();
        $this->assertDatabaseCount('store_order_items', 0);
    }

    public function test_hair_care_preserves_all_panels_and_publication_in_both_locales(): void
    {
        $byKey = collect($this->getJson('/api/v1/my-store/funnels/templates')->assertOk()->json('data'))->keyBy('key');
        foreach (['ar', 'en'] as $locale) {
            $this->assertSame('/funnel-templates/hair-care-'.$locale.'?preview=1&lang='.$locale, $byKey['hair-care-'.$locale]['preview_url']);
            $funnel = $this->createFunnel(['template_key' => 'hair-care-'.$locale, 'locale' => $locale, 'slug' => 'haircare-'.$locale])->assertCreated()->json('data');
            $path = '/api/v1/my-store/pages/'.$funnel['page_id'];
            $page = $this->getJson($path)->assertOk()->assertJsonPath('data.native_funnel_template', 'hair-care-'.$locale)->assertJsonCount(11, 'data.sections')->json('data');
            $this->assertSame('Canvas bag', $page['sections'][0]['settings']['heading']);
            $this->assertSame('', $page['sections'][0]['settings']['expires_at']);
            $this->assertStringNotContainsString('hair-care-hero', json_encode($page));
            $this->assertFalse($page['sections'][4]['is_visible']);
            $this->assertFalse($page['sections'][7]['is_visible']);
            $this->assertFalse($page['sections'][8]['is_visible']);
            $sections = $page['sections'];
            $sections[0]['settings']['heading'] = 'Own hair campaign';
            $sections[8]['is_visible'] = true;
            $sections[8]['settings']['items'] = array_fill(0, 10, ['label' => 'Owned phrase']);
            $this->putJson($path.'/sections', ['sections' => $sections])->assertOk()->assertJsonCount(8, 'data.sections.8.settings.items')->assertJsonPath('data.sections.8.settings.items.0.label', 'Owned phrase');
            $this->postJson($path.'/publish')->assertOk();
            $url = 'http://funnel.sellchase.com/api/v1/storefront/funnels/haircare-'.$locale.'?lang='.$locale;
            $this->getJson($url)->assertOk()->assertJsonPath('data.native_funnel_template', 'hair-care-'.$locale)->assertJsonPath('data.funnel_product_slug', 'canvas-bag')->assertJsonPath('data.sections.0.settings.heading', 'Own hair campaign');
            $sections[0]['settings']['heading'] = 'Draft change';
            $this->putJson($path.'/sections', ['sections' => $sections])->assertOk();
            $this->getJson($url)->assertOk()->assertJsonPath('data.sections.0.settings.heading', 'Own hair campaign');
            $copy = $this->postJson('/api/v1/my-store/funnels/'.$funnel['id'].'/duplicate')->assertCreated()->json('data');
            $this->getJson('/api/v1/my-store/pages/'.$copy['page_id'])->assertOk()->assertJsonCount(11, 'data.sections')->assertJsonPath('data.native_funnel_template', 'hair-care-'.$locale)->assertJsonPath('data.sections.0.settings.heading', 'Draft change')->assertJsonCount(8, 'data.sections.8.settings.items');
            $this->assertDatabaseMissing('store_page_publications', ['store_page_id' => $copy['page_id']]);
        }
    }

    public function test_hair_care_schema_survives_theme_changes_and_rejects_spoofing_and_duplicate_checkout(): void
    {
        $this->getJson('/api/v1/my-store/pages/schema?template_key=hair-care-en')->assertOk()->assertJsonMissingPath('sections_schema.haircare-purchase');
        $funnel = $this->createFunnel(['template_key' => 'hair-care-en'])->assertCreated()->json('data');
        $path = '/api/v1/my-store/pages/'.$funnel['page_id'];
        $version = StoreTheme::where('store_id', $this->store->id)->where('status', 'active')->value('theme_version_id');
        ThemeVersion::whereKey($version)->update(['sections_schema' => []]);
        Cache::flush();
        $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertOk()->assertJsonStructure(['sections_schema' => ['haircare-hero', 'haircare-details', 'haircare-features', 'haircare-faq']]);
        $this->putJson($path.'/sections', ['sections' => [['type' => 'haircare-purchase'], ['type' => 'haircare-purchase']]])->assertUnprocessable();
        $this->getJson($path)->assertOk()->assertJsonCount(11, 'data.sections');
        $this->putJson($path.'/sections', ['sections' => [['type' => 'haircare-intro', 'settings' => ['heading' => 'Changed']]]])->assertOk();
        $revisions = $this->getJson($path.'/revisions')->assertOk()->json('data');
        $revision = collect($revisions)->firstWhere('sections_count', 11);
        $this->postJson($path.'/revisions/'.$revision['id'].'/restore')->assertOk()->assertJsonCount(11, 'data.sections');
        $other = $this->makeStore('haircare-foreign');
        $this->asOwner($other);
        $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertNotFound();
        $this->assertDatabaseCount('store_order_items', 0);
    }

    public function test_skin_care_preserves_localized_compositions_and_independent_publication(): void
    {
        $byKey = collect($this->getJson('/api/v1/my-store/funnels/templates')->assertOk()->json('data'))->keyBy('key');
        foreach (['ar', 'en'] as $locale) {
            $count = $locale === 'en' ? 14 : 12;
            $this->assertSame('/funnel-templates/skin-care-'.$locale.'?preview=1&lang='.$locale, $byKey['skin-care-'.$locale]['preview_url']);
            $funnel = $this->createFunnel(['template_key' => 'skin-care-'.$locale, 'locale' => $locale, 'slug' => 'skincare-'.$locale])->assertCreated()->json('data');
            $path = '/api/v1/my-store/pages/'.$funnel['page_id'];
            $page = $this->getJson($path)->assertOk()->assertJsonPath('data.native_funnel_template', 'skin-care-'.$locale)->assertJsonCount($count, 'data.sections')->json('data');
            $this->assertSame('Canvas bag', $page['sections'][0]['settings']['heading']);
            $this->assertSame('', $page['sections'][0]['settings']['expires_at']);
            $this->assertSame([], $page['sections'][3]['settings']['policies']);
            $this->assertSame([], $page['sections'][7]['settings']['logos']);
            $this->assertFalse($page['sections'][7]['is_visible']);
            $this->assertStringNotContainsString('/media/funnel-campaigns/skin-care-', json_encode($page));
            $this->assertCount($locale === 'en' ? 2 : 1, array_filter($page['sections'], fn ($section) => $section['type'] === 'skincare-features'));
            $sections = $page['sections'];
            $sections[0]['settings']['heading'] = 'Own skin campaign';
            $sections[3]['settings']['policies'] = array_fill(0, 5, ['question' => 'Own policy', 'answer' => 'Actual terms']);
            $sections[5]['is_visible'] = true;
            $sections[5]['settings']['items'] = array_fill(0, 10, ['label' => 'Owned phrase']);
            $sections[6]['settings']['items'] = array_fill(0, 7, ['label' => 'First feature only']);
            $sections[7]['is_visible'] = true;
            $sections[7]['settings']['text'] = 'Own statement';
            $this->putJson($path.'/sections', ['sections' => $sections])->assertOk()->assertJsonCount(3, 'data.sections.3.settings.policies')->assertJsonCount(8, 'data.sections.5.settings.items')->assertJsonCount(5, 'data.sections.6.settings.items');
            if ($locale === 'en') {
                $this->getJson($path)->assertOk()->assertJsonPath('data.sections.8.settings.items.0.label', 'Canvas bag')->assertJsonPath('data.sections.7.settings.text', 'Own statement')->assertJsonPath('data.sections.9.settings.text', null)->assertJsonPath('data.sections.9.is_visible', false);
            }
            $this->postJson($path.'/publish')->assertOk();
            $url = 'http://funnel.sellchase.com/api/v1/storefront/funnels/skincare-'.$locale.'?lang='.$locale;
            $this->getJson($url)->assertOk()->assertJsonPath('data.native_funnel_template', 'skin-care-'.$locale)->assertJsonPath('data.funnel_product_slug', 'canvas-bag')->assertJsonPath('data.sections.0.settings.heading', 'Own skin campaign');
            $sections[0]['settings']['heading'] = 'Unpublished skin change';
            $this->putJson($path.'/sections', ['sections' => $sections])->assertOk();
            $this->getJson($url)->assertOk()->assertJsonPath('data.sections.0.settings.heading', 'Own skin campaign');
            $copy = $this->postJson('/api/v1/my-store/funnels/'.$funnel['id'].'/duplicate')->assertCreated()->json('data');
            $this->getJson('/api/v1/my-store/pages/'.$copy['page_id'])->assertOk()->assertJsonCount($count, 'data.sections')->assertJsonPath('data.native_funnel_template', 'skin-care-'.$locale)->assertJsonPath('data.sections.0.settings.heading', 'Unpublished skin change');
            $this->assertDatabaseMissing('store_page_publications', ['store_page_id' => $copy['page_id']]);
        }
        $this->assertDatabaseCount('store_order_items', 0);
    }

    public function test_skin_care_schema_is_scoped_and_independent_of_the_active_theme(): void
    {
        $this->getJson('/api/v1/my-store/pages/schema?template_key=skin-care-en')->assertOk()->assertJsonMissingPath('sections_schema.skincare-purchase');
        $funnel = $this->createFunnel(['template_key' => 'skin-care-en'])->assertCreated()->json('data');
        $path = '/api/v1/my-store/pages/'.$funnel['page_id'];
        $version = StoreTheme::where('store_id', $this->store->id)->where('status', 'active')->value('theme_version_id');
        ThemeVersion::whereKey($version)->update(['sections_schema' => []]);
        Cache::flush();
        $schema = $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertOk()->assertJsonStructure(['sections_schema' => ['skincare-hero', 'skincare-specs', 'skincare-story', 'skincare-quote', 'skincare-experiences', 'skincare-faq']])->json('sections_schema');
        $this->assertCount(11, $schema);
        $this->putJson($path.'/sections', ['sections' => [['type' => 'skincare-purchase'], ['type' => 'skincare-purchase']]])->assertUnprocessable();
        $this->getJson($path)->assertOk()->assertJsonCount(14, 'data.sections');
        $this->putJson($path.'/sections', ['sections' => [['type' => 'skincare-story', 'settings' => ['heading' => 'Changed story']]]])->assertOk();
        $revisions = $this->getJson($path.'/revisions')->assertOk()->json('data');
        $revision = collect($revisions)->firstWhere('sections_count', 14);
        $this->postJson($path.'/revisions/'.$revision['id'].'/restore')->assertOk()->assertJsonCount(14, 'data.sections');
        $other = $this->makeStore('skincare-foreign');
        $this->asOwner($other);
        $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertNotFound();
        $this->assertDatabaseCount('store_order_items', 0);
    }

    public function test_writing_board_has_independent_localized_sections_and_publication(): void
    {
        $byKey = collect($this->getJson('/api/v1/my-store/funnels/templates')->assertOk()->json('data'))->keyBy('key');
        foreach (['ar', 'en'] as $locale) {
            $this->assertSame('/funnel-templates/writing-board-'.$locale.'?preview=1&lang='.$locale, $byKey['writing-board-'.$locale]['preview_url']);
            $funnel = $this->createFunnel(['template_key' => 'writing-board-'.$locale, 'locale' => $locale, 'slug' => 'writing-'.$locale])->assertCreated()->json('data');
            $path = '/api/v1/my-store/pages/'.$funnel['page_id'];
            $page = $this->getJson($path)->assertOk()->assertJsonPath('data.native_funnel_template', 'writing-board-'.$locale)->assertJsonCount(11, 'data.sections')->json('data');
            $this->assertSame('Canvas bag', $page['sections'][0]['settings']['heading']);
            $this->assertSame('', $page['sections'][0]['settings']['expires_at']);
            $this->assertSame([], $page['sections'][5]['settings']['items']);
            $this->assertFalse($page['sections'][4]['is_visible']);
            $this->assertFalse($page['sections'][8]['is_visible']);
            $this->assertCount(6, $page['sections'][10]['settings']['items']);
            $this->assertStringNotContainsString('/media/funnel-campaigns/writing-board-', json_encode($page));
            $sections = $page['sections'];
            $sections[0]['settings']['heading'] = 'Own writing campaign';
            $sections[4]['is_visible'] = true;
            $sections[4]['settings']['items'] = array_fill(0, 10, ['label' => 'Owned phrase']);
            $sections[5]['settings']['items'] = array_fill(0, 7, ['image' => '', 'image_alt' => 'Owned detail']);
            $sections[7]['settings']['text'] = 'Actual product statement';
            $this->putJson($path.'/sections', ['sections' => $sections])->assertOk()->assertJsonCount(8, 'data.sections.4.settings.items')->assertJsonCount(4, 'data.sections.5.settings.items')->assertJsonPath('data.sections.8.is_visible', false)->assertJsonPath('data.sections.8.settings.items', []);
            $this->postJson($path.'/publish')->assertOk();
            $url = 'http://funnel.sellchase.com/api/v1/storefront/funnels/writing-'.$locale.'?lang='.$locale;
            $this->getJson($url)->assertOk()->assertJsonPath('data.native_funnel_template', 'writing-board-'.$locale)->assertJsonPath('data.funnel_product_slug', 'canvas-bag')->assertJsonPath('data.sections.0.settings.heading', 'Own writing campaign')->assertJsonPath('data.sections.7.settings.text', 'Actual product statement');
            $sections[0]['settings']['heading'] = 'Unpublished writing change';
            $this->putJson($path.'/sections', ['sections' => $sections])->assertOk();
            $this->getJson($url)->assertOk()->assertJsonPath('data.sections.0.settings.heading', 'Own writing campaign');
            $copy = $this->postJson('/api/v1/my-store/funnels/'.$funnel['id'].'/duplicate')->assertCreated()->json('data');
            $this->getJson('/api/v1/my-store/pages/'.$copy['page_id'])->assertOk()->assertJsonCount(11, 'data.sections')->assertJsonPath('data.native_funnel_template', 'writing-board-'.$locale)->assertJsonPath('data.sections.0.settings.heading', 'Unpublished writing change');
            $this->assertDatabaseMissing('store_page_publications', ['store_page_id' => $copy['page_id']]);
        }
        $this->assertDatabaseCount('store_order_items', 0);
    }

    public function test_writing_board_schema_is_scoped_and_survives_theme_changes(): void
    {
        $this->getJson('/api/v1/my-store/pages/schema?template_key=writing-board-en')->assertOk()->assertJsonMissingPath('sections_schema.writingboard-purchase');
        $funnel = $this->createFunnel(['template_key' => 'writing-board-en'])->assertCreated()->json('data');
        $path = '/api/v1/my-store/pages/'.$funnel['page_id'];
        $version = StoreTheme::where('store_id', $this->store->id)->where('status', 'active')->value('theme_version_id');
        ThemeVersion::whereKey($version)->update(['sections_schema' => []]);
        Cache::flush();
        $schema = $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertOk()->assertJsonStructure(['sections_schema' => ['writingboard-hero', 'writingboard-story', 'writingboard-features', 'writingboard-heritage', 'writingboard-statement', 'writingboard-faq']])->json('sections_schema');
        $this->assertCount(10, $schema);
        $this->putJson($path.'/sections', ['sections' => [['type' => 'writingboard-purchase'], ['type' => 'writingboard-purchase']]])->assertUnprocessable();
        $this->getJson($path)->assertOk()->assertJsonCount(11, 'data.sections');
        $this->putJson($path.'/sections', ['sections' => [['type' => 'writingboard-story', 'settings' => ['heading' => 'Changed writing story']]]])->assertOk();
        $revisions = $this->getJson($path.'/revisions')->assertOk()->json('data');
        $revision = collect($revisions)->firstWhere('sections_count', 11);
        $this->postJson($path.'/revisions/'.$revision['id'].'/restore')->assertOk()->assertJsonCount(11, 'data.sections');
        $other = $this->makeStore('writing-foreign');
        $this->asOwner($other);
        $this->getJson('/api/v1/my-store/pages/schema?page_id='.$funnel['page_id'])->assertNotFound();
        $this->assertDatabaseCount('store_order_items', 0);
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
