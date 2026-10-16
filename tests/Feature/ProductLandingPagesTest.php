<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StorePage;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\StoreCatalog\ProductLandingPages;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProductLandingPagesTest extends TestCase
{
    use RefreshDatabase;

    private array $auth;

    private Store $store;

    private array $product;

    private string $base = '/api/v1/my-store/catalog/products';

    private string $public = 'http://landing.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Storage::fake('public');
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Landing review', 'slug' => 'landing', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'landing.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->product = $this->postJson($this->base, ['name' => 'Landing bag', 'slug' => 'landing-bag', 'price' => 100, 'cost' => 20, 'is_active' => true], $this->auth)->assertCreated()->json('data');
    }

    public function test_landing_image_upload_is_owned_and_does_not_publish_or_rewrite_the_document(): void
    {
        $file = UploadedFile::fake()->image('own.gif', 20, 20);
        $uploaded = $this->post($this->endpoint().'/media', ['file' => $file], $this->auth)->assertCreated()->json('data');
        $this->assertSame('image/gif', $uploaded['mime']);
        $media = ProductMedia::withoutGlobalScopes()->findOrFail($uploaded['id']);
        $this->assertSame($this->product['id'], $media->store_product_id);
        Storage::disk('public')->assertExists($media->path);
        $this->assertNull(Product::withoutGlobalScopes()->findOrFail($this->product['id'])->landing_page);
        $this->post($this->endpoint().'/media', ['file' => UploadedFile::fake()->createWithContent('bad.svg', '<svg onload="bad()"/>')], $this->auth + ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('store_product_media', 1);
    }

    private function endpoint(): string
    {
        return $this->base.'/'.$this->product['id'].'/landing-page';
    }

    private function block(string $type = 'heading', array $settings = []): array
    {
        return ['id' => (string) Str::uuid(), 'type' => $type, 'settings' => $settings + ['title' => 'Own review heading']];
    }

    private function document(array $sections, string $mode = 'standalone'): array
    {
        return ['schema' => 1, 'mode' => $mode, 'sections' => $sections];
    }

    private function save(array $document, ?string $version = null): TestResponse
    {
        return $this->putJson($this->endpoint(), ['document' => $document, 'version' => $version ?? $this->product['landing_version']], $this->auth);
    }

    public function test_versioned_landing_saves_sanitize_static_html_and_preserve_all_product_and_stock_fields(): void
    {
        $this->getJson($this->endpoint(), $this->auth)->assertOk()->assertJsonPath('data.mode', 'standard')->assertJsonCount(0, 'data.sections');
        $document = $this->document([$this->block('html', ['html' => '<section class="own" style="padding:24px"><h2>Own heading</h2><img src="https://images.example.test/own.png" onerror="bad()"><script>bad()</script><form><input value="secret"></form></section>', 'css' => '.own { display:flex; gap:24px; }']), $this->block('faq', ['items' => [['title' => 'Delivery?', 'text' => 'Tracked delivery.']]])]);
        $saved = $this->save($document)->assertOk()->json();
        $html = $saved['data']['sections'][0]['settings']['html'];
        $this->assertStringContainsString('class="own"', $html);
        $this->assertStringContainsString('padding:24px', $html);
        foreach (['<script', 'onerror', '<form', '<input'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $html);
        }
        $this->getJson($this->public.'/products/landing-bag')->assertOk()->assertJsonCount(2, 'data.landing_page.sections')->assertJsonMissingPath('data.landing_version');
        $row = Product::withoutGlobalScopes()->findOrFail($this->product['id']);
        $this->assertSame('100.00', $row->price);
        $this->assertSame('20.00', $row->cost);
        $this->assertSame(0, $row->reserved_quantity);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('store_inventory_movements', 0);
        $this->postJson($this->public.'/cart/catalog', ['product_ids' => [$row->id]])->assertOk()->assertJsonPath('data.0.landing_page', null);
    }

    public function test_exact_retry_is_safe_and_stale_different_edits_cannot_overwrite_a_saved_page(): void
    {
        $document = $this->document([$this->block()]);
        $first = $this->save($document)->assertOk()->json();
        $this->save($document)->assertOk()->assertJsonPath('version', $first['version']);
        $changed = $document;
        $changed['sections'][0]['settings']['title'] = 'A stale overwrite';
        $this->save($changed)->assertConflict();
        $this->putJson($this->base.'/'.$this->product['id'], ['landing_mode' => 'below', 'landing_version' => $this->product['landing_version'], 'price' => 1], $this->auth)->assertConflict();
        $this->getJson($this->base.'/'.$this->product['id'], $this->auth)->assertOk()->assertJsonPath('data.price', '100.00');
        $this->save($changed, $first['version'])->assertOk()->assertJsonPath('data.sections.0.settings.title', 'A stale overwrite');
    }

    public function test_disabling_landing_display_retains_its_content_and_legacy_products_remain_unconfigured(): void
    {
        $saved = $this->save($this->document([$this->block()]))->assertOk()->json();
        $this->putJson($this->base.'/'.$this->product['id'], ['landing_mode' => 'standard', 'landing_version' => $saved['version']], $this->auth)->assertOk()->assertJsonPath('data.has_landing_content', true)->assertJsonPath('data.landing_mode', 'standard');
        $this->getJson($this->public.'/products/landing-bag')->assertOk()->assertJsonPath('data.landing_page', null);
        $this->getJson($this->endpoint(), $this->auth)->assertOk()->assertJsonCount(1, 'data.sections');
        $legacy = Product::create(['name' => 'Legacy', 'price' => 33]);
        $this->assertNull($legacy->landing_page);
        $migration = require database_path('migrations/2026_10_16_030000_add_product_landing_pages.php');
        try {
            $migration->down();
            $this->fail('Retain configured landing pages.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Preserve', $error->getMessage());
        }
    }

    public function test_invalid_or_oversized_documents_and_foreign_media_cannot_partially_write(): void
    {
        $same = $this->block();
        $bad = [$this->document([$this->block('script')]), $this->document([$same, $same]), $this->document([$this->block('countdown', ['hours' => 0])]), $this->document([$this->block('image', ['image' => ['url' => 'javascript:bad()']])]), $this->document([$this->block('image', ['image' => ['media_id' => 9999]])]), $this->document([$this->block('video', ['video' => 'https://untrusted.example.test/frame.html'])]), $this->document([$this->block('html', ['html' => str_repeat('x', 20001)])]), $this->document([$this->block('heading', ['unknown' => true])])];
        foreach ($bad as $document) {
            $this->save($document)->assertUnprocessable();
        }
        $this->save($this->document([]) + ['other' => true])->assertUnprocessable();
        $this->assertNull(Product::withoutGlobalScopes()->findOrFail($this->product['id'])->landing_page);
    }

    public function test_owned_media_resolves_publicly_and_cannot_be_deleted_while_referenced(): void
    {
        $media = ProductMedia::create(['store_id' => $this->store->id, 'store_product_id' => $this->product['id'], 'type' => 'gallery', 'disk' => 'public', 'path' => 'landing/own.jpg', 'position' => 0]);
        Storage::disk('public')->put('landing/own.jpg', 'own-review-fixture');
        $this->save($this->document([$this->block('image', ['image' => ['media_id' => $media->id, 'alt' => 'Own image']])]))->assertOk();
        $this->getJson($this->public.'/products/landing-bag')->assertOk()->assertJsonPath('data.landing_page.sections.0.settings.image.url', $media->url())->assertJsonMissingPath('data.landing_page.sections.0.settings.image.media_id');
        $this->putJson($this->base.'/'.$this->product['id'], ['remove_media_ids' => [$media->id]], $this->auth)->assertUnprocessable();
        $this->assertDatabaseHas('store_product_media', ['id' => $media->id]);
        Storage::disk('public')->assertExists('landing/own.jpg');
        $this->assertTrue(ProductLandingPages::referencesMedia(Product::withoutGlobalScopes()->findOrFail($this->product['id']), [$media->id]));
    }

    public function test_all_testimonial_designs_and_backgrounds_round_trip_without_fabricating_review_data(): void
    {
        $media = ProductMedia::create(['store_id' => $this->store->id, 'store_product_id' => $this->product['id'], 'type' => 'gallery', 'disk' => 'public', 'path' => 'landing/review-background.png']);
        $sections = array_map(fn ($id) => $this->block('custom_reviews', ['variant' => 'testimonials-'.$id, 'accent' => '#b8204c', 'card_background' => '#f4f3f8', 'background_image' => ['media_id' => $media->id], 'items' => [['author' => 'Own fixture reviewer', 'title' => 'Own review', 'text' => 'Own review content', 'caption' => 'Own city', 'date' => '2026-10-16', 'platform' => 'Own source']]]), range(1, 11));
        $saved = $this->save($this->document($sections))->assertOk()->json('data.sections');
        $this->assertCount(11, $saved);
        $this->getJson($this->public.'/products/landing-bag')->assertOk()->assertJsonPath('data.landing_page.sections.10.settings.variant', 'testimonials-11')->assertJsonPath('data.landing_page.sections.0.settings.background_image.url', $media->url())->assertJsonMissingPath('data.landing_page.sections.0.settings.background_image.media_id')->assertJsonMissingPath('data.landing_page.sections.0.settings.items.0.rating')->assertJsonMissingPath('data.landing_page.sections.0.settings.items.0.verified');
        $this->putJson($this->base.'/'.$this->product['id'], ['remove_media_ids' => [$media->id]], $this->auth)->assertUnprocessable();
        $before = ProductLandingPages::version(Product::withoutGlobalScopes()->findOrFail($this->product['id']));
        foreach ([$this->block('custom_reviews', ['variant' => 'testimonials-12']), $this->block('heading', ['variant' => 'testimonials-1']), $this->block('custom_reviews', ['background_image' => ['media_id' => 99999]]), $this->block('custom_reviews', ['items' => [['date' => '2026-99-99']]])] as $invalid) {
            $this->save($this->document([$invalid]), $before)->assertUnprocessable();
        }
        $this->assertSame($before, ProductLandingPages::version(Product::withoutGlobalScopes()->findOrFail($this->product['id'])));
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_optional_editor_text_and_avatar_alt_can_be_cleared_through_json_requests(): void
    {
        $doc = $this->document([$this->block('custom_reviews', ['variant' => 'testimonials-1', 'description' => '', 'background_image' => ['url' => 'https://example.test/own.png', 'alt' => ''], 'items' => [['author' => '', 'title' => '', 'text' => '', 'caption' => '', 'platform' => '', 'date' => null]]])]);
        $this->save($doc)->assertOk()->assertJsonPath('data.sections.0.settings.description', '')->assertJsonPath('data.sections.0.settings.items.0.author', '')->assertJsonPath('data.sections.0.settings.background_image.alt', '')->assertJsonPath('data.sections.0.settings.items.0.date', null);
    }

    public function test_editor_is_tenant_and_permission_scoped(): void
    {
        $stranger = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $stranger->assignRole('Merchant');
        Store::create(['owner_user_id' => $stranger->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-landing', 'status' => 'active']);
        $auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($stranger)];
        $this->getJson($this->endpoint())->assertUnauthorized();
        $this->getJson($this->endpoint(), $auth)->assertNotFound();
        $this->putJson($this->endpoint(), ['version' => $this->product['landing_version'], 'document' => $this->document([$this->block()])], $auth)->assertNotFound();
        $this->assertNull(Product::withoutGlobalScopes()->findOrFail($this->product['id'])->landing_page);
    }

    public function test_nine_banner_designs_keep_owned_links_portable_and_follow_current_public_routes(): void
    {
        $category = Category::create(['store_id' => $this->store->id, 'name' => 'Own category', 'slug' => 'own-category', 'is_active' => true, 'is_hidden' => false]);
        $page = StorePage::create(['store_id' => $this->store->id, 'title' => 'Private draft title', 'slug' => 'draft-link', 'published_slug' => 'public-link', 'template' => 'page', 'locale' => 'en', 'status' => 'published']);
        $snapshot = json_encode(['page' => ['title' => 'Own published title'], 'sections' => []]);
        DB::table('store_page_publications')->insert(['store_id' => $this->store->id, 'store_page_id' => $page->id, 'version' => 1, 'snapshot' => $snapshot, 'checksum' => hash('sha256', $snapshot), 'published_at' => now()]);
        $destinations = [['type' => 'product', 'id' => $this->product['id']], ['type' => 'category', 'id' => $category->id], ['type' => 'page', 'id' => $page->id]];
        $sections = array_map(fn ($id) => $this->block('promo_banners', ['variant' => 'banners-'.$id, 'items' => array_map(fn ($destination) => ['image' => ['url' => '/own-banner.png'], 'destination' => $destination, 'url' => 'https://example.test/stale'], $destinations)]), range(1, 9));
        $saved = $this->save($this->document($sections))->assertOk()->assertJsonMissingPath('data.sections.0.settings.items.0.url')->assertJsonPath('destination_urls.product:'.$this->product['id'], '/products/landing-bag')->json();
        $this->getJson($this->public.'/products/landing-bag')->assertOk()->assertJsonPath('data.landing_page.sections.0.settings.items.0.url', '/products/landing-bag')->assertJsonPath('data.landing_page.sections.8.settings.items.1.url', '/collections/own-category')->assertJsonPath('data.landing_page.sections.8.settings.items.2.url', '/pages/public-link')->assertJsonMissingPath('data.landing_page.sections.0.settings.items.0.destination');
        $this->getJson($this->endpoint().'/destinations?type=page&search=published', $this->auth)->assertOk()->assertJsonPath('data.0.name', 'Own published title')->assertJsonMissing(['name' => 'Private draft title']);
        $category->update(['slug' => 'renamed-category']);
        $product = Product::withoutGlobalScopes()->findOrFail($this->product['id']);
        $this->assertSame('/collections/renamed-category', ProductLandingPages::portablePayload($product)['sections'][0]['settings']['items'][1]['url']);
        $category->update(['is_active' => false]);
        $this->assertArrayNotHasKey('url', ProductLandingPages::portablePayload($product)['sections'][0]['settings']['items'][1]);
        $this->putJson($this->endpoint(), ['version' => $saved['version'], 'document' => $saved['data']], $this->auth)->assertOk();
        foreach ([$this->block('custom_reviews', ['variant' => 'banners-1']), $this->block('promo_banners', ['variant' => 'banners-10']), $this->block('promo_banners', ['items' => [['destination' => []]]]), $this->block('heading', ['items' => [['destination' => $destinations[0]]]])] as $invalid) {
            $this->save($this->document([$invalid]), $saved['version'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_all_feature_designs_round_trip_owned_content_colors_and_motion_without_touching_commerce(): void
    {
        $media = ProductMedia::create(['store_id' => $this->store->id, 'store_product_id' => $this->product['id'], 'type' => 'gallery', 'disk' => 'public', 'path' => 'landing/own-feature.png']);
        $item = ['title' => 'Own benefit', 'text' => 'Own policy', 'html' => '<p>Own static detail</p><script>bad()</script>', 'icon' => 'shield', 'accent' => '#112233', 'background' => 'transparent', 'foreground' => '#334455', 'image' => ['media_id' => $media->id], 'url' => '/pages/own-policy'];
        $sections = array_map(fn ($id) => $this->block('store_features', ['variant' => 'features-'.$id, 'items' => [$item], 'motion' => false, 'icon_color' => '#ff00ff', 'icon_background' => '#abcdef', 'hover_background' => '#001122', 'hover_foreground' => '#ddeeff', 'secondary_foreground' => '#556677', 'hover_secondary_foreground' => '#aabbcc']), range(1, 45));
        $saved = $this->save($this->document($sections))->assertOk()->assertJsonCount(45, 'data.sections')->assertJsonPath('data.sections.44.settings.variant', 'features-45')->assertJsonPath('data.sections.44.settings.motion', false)->json();
        $this->getJson($this->endpoint(), $this->auth)->assertOk()->assertJsonPath('data.sections.34.settings.items.0.icon', 'shield')->assertJsonPath('data.sections.34.settings.items.0.accent', '#112233');
        $this->getJson($this->public.'/products/landing-bag')->assertOk()->assertJsonPath('data.landing_page.sections.41.settings.items.0.image.url', $media->url())->assertJsonPath('data.landing_page.sections.0.settings.hover_background', '#001122')->assertJsonMissingPath('data.landing_page.sections.0.settings.items.0.image.media_id');
        $this->assertStringNotContainsString('<script', $saved['data']['sections'][0]['settings']['items'][0]['html']);
        $this->save($saved['data'], $saved['version'])->assertOk()->assertJsonPath('version', $saved['version']);
        foreach ([$this->block('store_features', ['variant' => 'features-46']), $this->block('checkout', ['variant' => 'features-1']), $this->block('store_features', ['variant' => 'banners-1']), $this->block('store_features', ['motion' => 'yes']), $this->block('store_features', ['hover_background' => 'url(evil)']), $this->block('store_features', ['items' => [['accent' => 'red;position:fixed']]]), $this->block('store_features', ['items' => [['image' => ['media_id' => 999999]]]])] as $invalid) {
            $this->save($this->document([$invalid]), $saved['version'])->assertUnprocessable();
        }
        $this->assertSame($saved['version'], ProductLandingPages::version(Product::withoutGlobalScopes()->findOrFail($this->product['id'])));
        $this->assertDatabaseHas('products', ['id' => $this->product['id'], 'price' => 100, 'cost' => 20, 'reserved_quantity' => 0]);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('store_inventory_movements', 0);
    }

    public function test_document_save_preserves_untouched_plain_text_spacing_including_a_return_to_the_original_version(): void
    {
        $document = $this->document([$this->block('faq', ['variant' => 'faq-1', 'title' => ' Own heading ', 'description' => "\nOwn intro\n", 'items' => [['title' => ' Own question ', 'text' => "  Own answer\nSecond line  "]]])]);
        $saved = $this->save($document)->assertOk()->assertJsonPath('data.sections.0.settings.title', ' Own heading ')->assertJsonPath('data.sections.0.settings.description', "\nOwn intro\n")->assertJsonPath('data.sections.0.settings.items.0.text', "  Own answer\nSecond line  ")->json();
        $edited = $saved['data'];
        $edited['sections'][0]['settings']['items'][0]['text'] = 'Edited own answer';
        $changed = $this->save($edited, $saved['version'])->assertOk()->json();
        $this->save($saved['data'], $changed['version'])->assertOk()->assertJsonPath('version', $saved['version']);
    }

    public function test_all_faq_designs_keep_answers_owned_media_and_colors_through_versioned_saves(): void
    {
        $media = ProductMedia::create(['store_id' => $this->store->id, 'store_product_id' => $this->product['id'], 'type' => 'gallery', 'disk' => 'public', 'path' => 'landing/own-answer.png']);
        $items = array_map(fn ($id) => ['title' => 'Own question '.$id, 'text' => "Own answer\nSecond line", 'html' => '<p>Own rich answer</p><script>bad()</script>', 'image' => ['media_id' => $media->id], 'url' => '/pages/own-policy'], range(1, 9));
        $sections = array_map(fn ($id) => $this->block('faq', ['variant' => 'faq-'.$id, 'items' => $items, 'background_image' => ['media_id' => $media->id], 'question_background' => '#f6e6ff', 'card_background' => 'transparent', 'accent' => '#112233', 'foreground' => '#334455', 'motion' => false]), range(1, 35));
        $saved = $this->save($this->document($sections))->assertOk()->assertJsonCount(35, 'data.sections')->assertJsonCount(9, 'data.sections.34.settings.items')->assertJsonPath('data.sections.34.settings.variant', 'faq-35')->assertJsonPath('data.sections.34.settings.motion', false)->json();
        $this->getJson($this->endpoint(), $this->auth)->assertOk()->assertJsonPath('data.sections.33.settings.items.8.title', 'Own question 9')->assertJsonPath('data.sections.0.settings.question_background', '#f6e6ff');
        $this->getJson($this->public.'/products/landing-bag')->assertOk()->assertJsonPath('data.landing_page.sections.0.settings.items.8.image.url', $media->url())->assertJsonMissingPath('data.landing_page.sections.0.settings.items.8.image.media_id');
        $this->assertStringNotContainsString('<script', $saved['data']['sections'][0]['settings']['items'][0]['html']);
        $this->save($saved['data'], $saved['version'])->assertOk()->assertJsonPath('version', $saved['version']);
        foreach ([$this->block('faq', ['variant' => 'faq-36']), $this->block('faq', ['variant' => 'features-1']), $this->block('checkout', ['variant' => 'faq-1']), $this->block('faq', ['question_background' => 'url(evil)']), $this->block('faq', ['items' => [['image' => ['media_id' => 999999]]]])] as $invalid) {
            $this->save($this->document([$invalid]), $saved['version'])->assertUnprocessable();
        }
        $this->assertSame($saved['version'], ProductLandingPages::version(Product::withoutGlobalScopes()->findOrFail($this->product['id'])));
        $this->assertDatabaseHas('products', ['id' => $this->product['id'], 'price' => 100, 'cost' => 20, 'reserved_quantity' => 0]);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('store_inventory_movements', 0);
    }

    public function test_video_designs_preserve_owned_content_and_reject_invalid_family_or_provider_without_mutation(): void
    {
        $media = ProductMedia::create(['store_id' => $this->store->id, 'store_product_id' => $this->product['id'], 'type' => 'gallery', 'disk' => 'public', 'path' => 'landing/own-video-benefit.png']);
        $items = array_map(fn ($id) => ['title' => 'Own benefit '.$id, 'text' => "Own explanation\n ", 'icon' => 'shield', 'image' => ['media_id' => $media->id], 'url' => '/pages/own-video', 'html' => '<p>Own details</p><script>bad()</script>'], range(1, 7));
        $sections = array_map(fn ($id) => $this->block('video', ['variant' => 'video-'.$id, 'title' => 'Own video '.$id, 'description' => 'Own subtitle', 'video' => 'https://www.youtube.com/shorts/abcdefghijk', 'button_text' => '', 'items' => $items, 'accent' => '#112233', 'card_background' => '#fafafa', 'motion' => false, 'css' => '.own-video { color: blue; }']), range(1, 5));
        $saved = $this->save($this->document($sections))->assertOk()->assertJsonCount(5, 'data.sections')->assertJsonCount(7, 'data.sections.4.settings.items')->assertJsonPath('data.sections.3.settings.button_text', '')->assertJsonPath('data.sections.4.settings.motion', false)->json();
        $this->getJson($this->public.'/products/landing-bag')->assertOk()->assertJsonPath('data.landing_page.sections.2.settings.items.6.image.url', $media->url())->assertJsonMissingPath('data.landing_page.sections.2.settings.items.6.image.media_id')->assertJsonPath('data.landing_page.sections.4.settings.variant', 'video-5');
        $portable = ProductLandingPages::portablePayload(Product::withoutGlobalScopes()->findOrFail($this->product['id']));
        $this->assertSame('https://www.youtube.com/shorts/abcdefghijk', $portable['sections'][0]['settings']['video']);
        $this->assertSame("Own explanation\n ", $saved['data']['sections'][0]['settings']['items'][0]['text']);
        $this->assertStringNotContainsString('<script', $portable['sections'][0]['settings']['items'][0]['html']);
        $this->save($saved['data'], $saved['version'])->assertOk()->assertJsonPath('version', $saved['version']);
        $changed = $saved['data'];
        $changed['sections'][0]['settings']['variant'] = 'video-5';
        $changed['sections'][0]['settings']['video'] = '/storage/own-video.mp4';
        $next = $this->save($changed, $saved['version'])->assertOk()->json();
        $this->save($saved['data'], $next['version'])->assertOk()->assertJsonPath('version', $saved['version']);
        foreach ([$this->block('video', ['variant' => 'video-6']), $this->block('youtube', ['variant' => 'video-1']), $this->block('faq', ['variant' => 'video-3']), $this->block('video', ['variant' => 'faq-1']), $this->block('video', ['variant' => 'video-1', 'video' => 'https://evil.test/embed/abcdefghijk']), $this->block('video', ['variant' => 'video-3', 'items' => [['image' => ['media_id' => 999999]]]])] as $invalid) {
            $this->save($this->document([$invalid]), $saved['version'])->assertUnprocessable();
        }
        $this->assertSame($saved['version'], ProductLandingPages::version(Product::withoutGlobalScopes()->findOrFail($this->product['id'])));
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('store_inventory_movements', 0);
    }

    public function test_howto_designs_round_trip_owned_steps_headers_media_and_whitespace_without_commerce_changes(): void
    {
        $media = ProductMedia::create(['store_id' => $this->store->id, 'store_product_id' => $this->product['id'], 'type' => 'gallery', 'disk' => 'public', 'path' => 'landing/own-howto.png']);
        $items = array_map(fn ($id) => ['title' => 'Own step '.$id, 'caption' => ' Own caption ', 'text' => " Own instructions\n ", 'icon' => 'gear', 'image' => ['media_id' => $media->id], 'url' => '/pages/own-instructions', 'html' => '<p>Own detail</p><script>bad()</script>'], range(1, 7));
        $sections = array_map(fn ($id) => $this->block('how_to', ['variant' => 'howto-'.$id, 'title' => 'Own how-to '.$id, 'eyebrow' => ' Own intro ', 'description' => 'Own subtitle', 'image' => ['media_id' => $media->id], 'button_text' => ' Own action ', 'items' => $items, 'accent' => '#112233', 'card_background' => '#fafafa', 'motion' => false]), range(1, 6));
        $saved = $this->save($this->document($sections))->assertOk()->assertJsonCount(6, 'data.sections')->assertJsonCount(7, 'data.sections.5.settings.items')->assertJsonPath('data.sections.0.settings.eyebrow', ' Own intro ')->assertJsonPath('data.sections.5.settings.items.6.caption', ' Own caption ')->assertJsonPath('data.sections.5.settings.button_text', ' Own action ')->json();
        $this->getJson($this->endpoint(), $this->auth)->assertOk()->assertJsonPath('data.sections.5.settings.variant', 'howto-6');
        $this->getJson($this->public.'/products/landing-bag')->assertOk()->assertJsonPath('data.landing_page.sections.5.settings.items.6.image.url', $media->url())->assertJsonPath('data.landing_page.sections.1.settings.image.url', $media->url())->assertJsonMissingPath('data.landing_page.sections.0.settings.image.media_id');
        $portable = ProductLandingPages::portablePayload(Product::withoutGlobalScopes()->findOrFail($this->product['id']));
        $this->assertSame(' Own intro ', $portable['sections'][0]['settings']['eyebrow']);
        $this->assertSame(" Own instructions\n ", $portable['sections'][0]['settings']['items'][0]['text']);
        $this->assertStringNotContainsString('<script', $portable['sections'][0]['settings']['items'][0]['html']);
        $this->save($saved['data'], $saved['version'])->assertOk()->assertJsonPath('version', $saved['version']);
        $changed = $saved['data'];
        $changed['sections'][0]['settings']['variant'] = 'howto-6';
        $changed['sections'][0]['settings']['eyebrow'] = null;
        $changed['sections'][0]['settings']['items'][0]['caption'] = null;
        $changed['sections'][0]['settings']['button_text'] = null;
        $next = $this->save($changed, $saved['version'])->assertOk()->assertJsonPath('data.sections.0.settings.eyebrow', '')->assertJsonPath('data.sections.0.settings.items.0.caption', '')->assertJsonPath('data.sections.0.settings.button_text', '')->json();
        $this->save($saved['data'], $next['version'])->assertOk()->assertJsonPath('version', $saved['version']);
        foreach ([$this->block('how_to', ['variant' => 'howto-7']), $this->block('how_to', ['variant' => 'video-1']), $this->block('video', ['variant' => 'howto-1']), $this->block('checkout', ['variant' => 'howto-6']), $this->block('how_to', ['eyebrow' => str_repeat('a', 301)]), $this->block('how_to', ['image' => ['media_id' => 999999]])] as $invalid) {
            $this->save($this->document([$invalid]), $saved['version'])->assertUnprocessable();
        }
        $this->assertSame($saved['version'], ProductLandingPages::version(Product::withoutGlobalScopes()->findOrFail($this->product['id'])));
        $this->assertDatabaseHas('products', ['id' => $this->product['id'], 'price' => 100, 'reserved_quantity' => 0]);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('store_inventory_movements', 0);
    }

    public function test_banner_destination_picker_is_scoped_bounded_and_never_returns_drafts_or_commerce_fields(): void
    {
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other banners', 'slug' => 'other-banners', 'status' => 'active']);
        $foreign = Category::create(['store_id' => $other->id, 'name' => 'Foreign private category', 'slug' => 'foreign-category', 'is_active' => true, 'is_hidden' => false]);
        Category::create(['store_id' => $this->store->id, 'name' => 'Own 100% category', 'slug' => 'own-percent', 'is_active' => true, 'is_hidden' => false]);
        StorePage::create(['store_id' => $this->store->id, 'title' => 'Draft secret', 'slug' => 'draft-secret', 'template' => 'page', 'locale' => 'en', 'status' => 'draft']);
        $this->getJson($this->endpoint().'/destinations?type=category&search=%25&selected='.$foreign->id, $this->auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('selected', null)->assertJsonPath('data.0.name', 'Own 100% category');
        for ($i = 0; $i < 31; $i++) {
            Category::create(['store_id' => $this->store->id, 'name' => 'Own category '.$i, 'slug' => 'own-paged-'.$i, 'is_active' => true, 'is_hidden' => false]);
        }
        $this->getJson($this->endpoint().'/destinations?type=category', $this->auth)->assertOk()->assertJsonCount(30, 'data')->assertJsonPath('meta.has_more', true);
        $this->getJson($this->endpoint().'/destinations?type=category&page=2', $this->auth)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.has_more', false);
        $this->getJson($this->endpoint().'/destinations?type=page', $this->auth)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->endpoint().'/destinations?type=product', $this->auth)->assertOk()->assertJsonMissingPath('data.0.cost')->assertJsonMissingPath('data.0.stock_quantity');
        $this->save($this->document([$this->block('promo_banners', ['items' => [['destination' => ['type' => 'category', 'id' => $foreign->id]]]])]))->assertUnprocessable();
        $this->getJson($this->endpoint().'/destinations?type=product')->assertUnauthorized();
        $this->getJson($this->endpoint().'/destinations?type=unknown', $this->auth)->assertUnprocessable();
        $this->getJson($this->endpoint().'/destinations?type=product&page=0', $this->auth)->assertUnprocessable();
        $this->assertNull(Product::withoutGlobalScopes()->findOrFail($this->product['id'])->landing_page);
    }
}
