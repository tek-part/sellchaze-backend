<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\StoreCatalog\ProductLandingPages;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
}
