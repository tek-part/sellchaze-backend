<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Store;
use App\Models\StoreCatalogOperation;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\StoreCatalog\CatalogExchangeReader;
use App\Services\StoreCatalog\ProductLandingPages;
use App\Services\StoreCatalog\StorefrontProductService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StoreCatalogTransferTest extends TestCase
{
    use RefreshDatabase;

    private array $auth;

    private Store $store;

    private string $base = '/api/v1/my-store/catalog/products';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Storage::fake('public');
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Transfer', 'slug' => 'transfer', 'currency' => 'EGP', 'status' => 'active']);
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner), 'Accept' => 'application/json'];
    }

    private function file(array $data): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('products.json', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function imported(array $row = []): int
    {
        return $this->post($this->base.'/import', ['file' => $this->file([array_merge(['name' => 'Sample bag', 'price' => 100], $row)]), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertCreated()->json('meta.ids.0');
    }

    private function target(int $id): array
    {
        return ['id' => $id, 'version' => $this->getJson($this->base.'/'.$id, $this->auth)->assertOk()->json('data.transfer_version')];
    }

    public function test_external_json_preserves_prices_options_images_categories_and_audited_stock_without_foreign_identities(): void
    {
        $row = ['id' => 9876, 'store_id' => 9999, 'name' => 'Custom bag', 'price' => 300, 'sale_price' => 240, 'sku' => 'IMPORT-1',
            'quantity' => 7, 'track_stock' => true, 'thumb' => 'https://example.com/bag.jpg', 'images' => ['https://example.com/detail.jpg'],
            'description' => '<p>Safe</p><script>alert(1)</script>', 'categories' => [['id' => 123, 'name' => 'Bags'], ['name' => 'Gifts']],
            'features' => [['position' => 2, 'advanced_text' => '<h2>Details</h2>']],
            'variations' => [['id' => 'axis-1', 'name' => 'Color', 'type' => 'color', 'props' => [['id' => 'blue', 'name' => 'Blue', 'value' => '#0000ff']]]],
            'variants' => [['id' => 'foreign-variant', 'price' => 320, 'sale_price' => 250, 'quantity' => 5, 'variation_props' => [['variation' => 'Color', 'variation_prop' => 'Blue']]]]];
        $file = $this->file([$row]);
        $this->post($this->base.'/import/preview', ['file' => $file], $this->auth)->assertOk()->assertJsonPath('data.products.0.variants', 1)->assertJsonCount(2, 'data.products.0.categories');
        $this->assertDatabaseCount('products', 0);
        $id = $this->post($this->base.'/import', ['file' => $this->file([$row]), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertCreated()->json('meta.ids.0');
        $product = Product::query()->findOrFail($id);
        $this->assertNotEquals(9876, $id);
        $this->assertEquals($this->store->id, $product->store_id);
        $this->assertEquals(240, $product->price);
        $this->assertEquals(300, $product->compare_price);
        $this->assertStringNotContainsString('<script', $product->description);
        $this->assertStringContainsString('Details', $product->description);
        $this->assertEquals(['Color' => 'Blue'], $product->variants->first()->options);
        $this->assertEquals(5, $product->variants->first()->stock_quantity);
        $this->assertEquals(0, $product->stock_quantity);
        $this->assertCount(2, $product->additionalCategories);
        $this->assertDatabaseCount('store_inventory_movements', 1);
        $this->assertDatabaseCount('store_product_media', 2);
        $raw = DB::table('store_catalog_operations')->value('source_payload');
        $this->assertStringNotContainsString('Custom bag', $raw);
        $this->assertEquals($row, StoreCatalogOperation::first()->source_payload[0]);
        foreach ($product->additionalCategories as $category) {
            $this->getJson($this->base.'?category_id='.$category->id, $this->auth)->assertOk()->assertJsonPath('meta.total', 1);
            $this->assertEquals(1, Category::query()->withCatalogProductCount(true)->find($category->id)->products_count);
        }
    }

    public function test_import_replays_exactly_and_conflicting_keys_or_duplicate_skus_cannot_partially_write(): void
    {
        $rows = [['name' => 'One', 'price' => 10, 'sku' => 'ONE']];
        $key = (string) Str::uuid();
        $first = $this->post($this->base.'/import', ['file' => $this->file($rows), 'idempotency_key' => $key], $this->auth)->assertCreated()->json('meta');
        $this->post($this->base.'/import', ['file' => $this->file($rows), 'idempotency_key' => $key], $this->auth)->assertCreated()->assertJsonPath('meta', $first);
        $this->post($this->base.'/import', ['file' => $this->file([['name' => 'Different', 'price' => 5]]), 'idempotency_key' => $key], $this->auth)->assertConflict();
        $this->post($this->base.'/import', ['file' => $this->file([['name' => 'Two', 'price' => 10, 'sku' => 'TWO'], $rows[0]]), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertUnprocessable();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('store_catalog_operations', 1);
    }

    public function test_xlsx_supports_combined_text_color_image_dropdown_and_customer_personalization(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog-test-');
        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->addRow(Row::fromValues(CatalogExchangeReader::HEADERS));
            $writer->addRow(Row::fromValues(['gift', 'Gift sample', 100, 80, 'GIFT', 'Gifts, Seasonal', '', '', 9, 1, 1, '<p>Gift</p>', '',
                'Size: S, L', 'Color(color): Blue=#0000ff', 'Wrap(dropdown): Paper, Fabric', 'Pattern(image): Stars=https://example.com/stars.jpg', 'Message(user_text): Customer message', 'Photo(user_image): Customer photo']));
            $writer->close();
            $id = $this->post($this->base.'/import', ['file' => new UploadedFile($path, 'catalog.xlsx', null, null, true), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertCreated()->json('meta.ids.0');
            $data = $this->getJson($this->base.'/'.$id, $this->auth)->assertOk()->json('data');
            $this->assertCount(4, $data['variants']);
            $this->assertCount(4, $data['option_display']);
            $this->assertCount(2, $data['personalization_fields']);
            $this->assertEquals(['text', 'image'], array_column($data['personalization_fields'], 'type'));
            $this->assertEquals('https://example.com/stars.jpg', $data['media'][0]['url']);
            $this->assertNotNull($data['option_display'][3]['values'][0]['media_id']);
            $this->assertEquals(9, $data['variants'][0]['stock_quantity']);
        } finally {
            @unlink($path);
        }
    }

    public function test_malformed_documents_invalid_options_currency_urls_and_late_errors_are_atomic(): void
    {
        foreach ([
            ['name' => 'Bad', 'price' => -1], ['name' => 'Bad', 'price' => 2, 'thumb' => ['x']],
            ['name' => 'Bad', 'price' => 2, 'categories' => [12]], ['name' => 'Bad', 'price' => 2, 'sale_price' => 1, 'features' => [1]],
            ['name' => 'Bad', 'price' => 2, 'variants' => ['bad']], ['name' => 'Bad', 'price' => 2, 'custom_currency' => 'USD'],
            ['name' => 'Bad', 'price' => 2, 'images' => ['javascript:alert(1)']],
            ['name' => 'Bad', 'price' => 2, 'sale_price' => 1, 'variations' => [['id' => ['invalid'], 'name' => 'Color', 'props' => [['id' => 'blue', 'name' => 'Blue']]]]],
            ['name' => 'Bad', 'price' => 2, 'variants' => [['name' => 'A', 'options' => ['Size' => 'S']], ['name' => 'B', 'options' => ['size' => 's']]]],
        ] as $row) {
            $this->post($this->base.'/import', ['file' => $this->file([['name' => 'Valid', 'price' => 3], $row]), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertUnprocessable();
            $this->assertDatabaseCount('products', 0);
            $this->assertDatabaseCount('store_catalog_operations', 0);
        }
    }

    public function test_json_export_round_trips_options_and_available_stock_without_delivery_credentials_or_reserved_units(): void
    {
        $id = $this->imported(['sku' => 'ORIGINAL', 'stock_quantity' => 8, 'track_inventory' => true,
            'variants' => [['name' => 'Small', 'options' => ['Size' => 'S'], 'stock_quantity' => 8, 'track_inventory' => true]],
            'option_display' => [['name' => 'Size', 'type' => 'buttons', 'values' => [['value' => 'S']]]]]);
        Product::query()->find($id)->variants()->first()->update(['reserved_quantity' => 3]);
        $document = $this->postJson($this->base.'/export', ['products' => [$this->target($id)]], $this->auth)->assertOk()->json();
        $this->assertEquals(5, $document['products'][0]['variants'][0]['stock_quantity']);
        $this->assertArrayNotHasKey('digital_url', $document['products'][0]);
        unset($document['products'][0]['sku']);
        $this->post($this->base.'/import', ['file' => $this->file($document), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertCreated();
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('store_product_variants', 2);
    }

    public function test_preferences_priority_and_catalog_visibility_survive_export_import_and_duplicate(): void
    {
        $settings = ['is_skip_cart' => true, 'is_fixed_bottom_buy' => false, 'is_one_page_checkout' => true, 'pixel_price' => '0.00'];
        $id = $this->imported(['product_preferences' => $settings, 'display_priority' => 77, 'catalog_hidden' => true]);
        $product = Product::withoutGlobalScopes()->findOrFail($id);
        $this->assertEquals($settings, $product->product_preferences);
        $target = $this->target($id);
        $document = $this->postJson($this->base.'/export', ['products' => [$target]], $this->auth)->assertOk()->json();
        $copy = $this->postJson($this->base.'/'.$id.'/duplicate', ['version' => $target['version'], 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertCreated()->json('meta.id');
        $duplicate = Product::withoutGlobalScopes()->findOrFail($copy);
        $this->assertEquals($settings, $duplicate->product_preferences);
        $this->assertSame(77, $duplicate->display_priority);
        $this->assertTrue($duplicate->catalog_hidden);
        $this->assertFalse($duplicate->is_active);
        unset($document['products'][0]['slug'], $document['products'][0]['sku']);
        $imported = $this->post($this->base.'/import', ['file' => $this->file($document), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertCreated()->json('meta.ids.0');
        $this->assertEquals($settings, Product::withoutGlobalScopes()->findOrFail($imported)->product_preferences);
        $this->post($this->base.'/import/preview', ['file' => $this->file([['name' => 'Bad counter range', 'price' => 10, 'product_preferences' => ['fake_visitors_min' => 90, 'fake_visitors_max' => 20]]])], $this->auth)->assertUnprocessable();
        $this->assertDatabaseCount('products', 3);
    }

    public function test_landing_layout_round_trips_with_portable_owned_media_and_copy_versions_cover_it(): void
    {
        $id = $this->imported(['images' => ['https://example.com/landing.jpg']]);
        $product = Product::withoutGlobalScopes()->findOrFail($id);
        $media = $product->media()->firstOrFail();
        $target = $this->target($id);
        $doc = ['schema' => 1, 'mode' => 'standalone', 'sections' => [['id' => (string) Str::uuid(), 'type' => 'image', 'settings' => ['image' => ['media_id' => $media->id], 'background_image' => ['media_id' => $media->id]]], ['id' => (string) Str::uuid(), 'type' => 'custom_reviews', 'settings' => ['variant' => 'testimonials-11', 'items' => [['author' => 'Own review', 'caption' => 'Own location', 'date' => '2026-10-16', 'platform' => 'Own source', 'text' => 'Own review text']]]]]];
        $product->landing_page = ProductLandingPages::canonical($doc, $product);
        $product->save();
        $this->postJson($this->base.'/export', ['products' => [$target]], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('products');
        $target = $this->target($id);
        $document = $this->postJson($this->base.'/export', ['products' => [$target]], $this->auth)->assertOk()->json();
        $this->assertSame('https://example.com/landing.jpg', $document['products'][0]['landing_page']['sections'][0]['settings']['image']['url']);
        $this->assertArrayNotHasKey('media_id', $document['products'][0]['landing_page']['sections'][0]['settings']['image']);
        $copy = $this->postJson($this->base.'/'.$id.'/duplicate', ['version' => $target['version'], 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertCreated()->json('meta.id');
        $copyProduct = Product::withoutGlobalScopes()->findOrFail($copy);
        $this->assertNotSame($media->id, $copyProduct->landing_page['sections'][0]['settings']['image']['media_id']);
        $this->assertSame($copyProduct->landing_page['sections'][0]['settings']['image']['media_id'], $copyProduct->landing_page['sections'][0]['settings']['background_image']['media_id']);
        $this->assertEquals($document['products'][0]['landing_page'], ProductLandingPages::portablePayload($copyProduct));
        unset($document['products'][0]['slug'], $document['products'][0]['sku']);
        $imported = $this->post($this->base.'/import', ['file' => $this->file($document), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertCreated()->json('meta.ids.0');
        $this->assertEquals($document['products'][0]['landing_page'], Product::withoutGlobalScopes()->findOrFail($imported)->landing_page);
        $document['products'][0]['landing_page']['sections'][0]['settings']['image'] = ['media_id' => $media->id];
        $this->post($this->base.'/import', ['file' => $this->file($document), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertUnprocessable();
        $this->assertDatabaseCount('products', 3);
    }

    public function test_duplicate_is_an_inactive_zero_stock_copy_and_shared_media_survives_source_deletion(): void
    {
        $id = $this->imported(['stock_quantity' => 7, 'track_inventory' => true, 'images' => ['https://example.com/bag.jpg']]);
        Storage::disk('public')->put('store-catalog/shared.jpg', 'synthetic');
        $source = Product::query()->findOrFail($id);
        $source->update(['image' => 'store-catalog/shared.jpg']);
        $source->media()->first()->update(['path' => 'store-catalog/shared.jpg']);
        $key = (string) Str::uuid();
        $data = ['version' => $this->target($id)['version'], 'idempotency_key' => $key];
        $copy = $this->postJson($this->base.'/'.$id.'/duplicate', $data, $this->auth)->assertCreated()->json('meta.id');
        $this->postJson($this->base.'/'.$id.'/duplicate', $data, $this->auth)->assertCreated()->assertJsonPath('meta.id', $copy);
        $this->assertDatabaseHas('products', ['id' => $copy, 'is_active' => false, 'stock_quantity' => 0, 'sku' => null]);
        app(StorefrontProductService::class)->delete($source);
        // RefreshDatabase wraps tests in a transaction: run after-commit cleanup explicitly.
        $service = new \ReflectionMethod(StorefrontProductService::class, 'deleteImage');
        $service->invoke(app(StorefrontProductService::class), 'store-catalog/shared.jpg');
        Storage::disk('public')->assertExists('store-catalog/shared.jpg');
        $this->assertDatabaseHas('store_product_media', ['store_product_id' => $copy, 'path' => 'store-catalog/shared.jpg']);
    }

    public function test_bulk_delete_is_versioned_atomic_reservation_safe_and_retryable(): void
    {
        $one = $this->imported();
        $two = $this->imported(['name' => 'Second']);
        $targets = [$this->target($one), $this->target($two)];
        Product::query()->whereKey($two)->update(['reserved_quantity' => 1]);
        $key = (string) Str::uuid();
        $this->postJson($this->base.'/bulk-delete', ['products' => $targets, 'idempotency_key' => $key], $this->auth)->assertUnprocessable();
        $this->assertDatabaseCount('products', 2);
        Product::query()->whereKey($two)->update(['reserved_quantity' => 0, 'name' => 'Changed']);
        $this->postJson($this->base.'/bulk-delete', ['products' => $targets, 'idempotency_key' => $key], $this->auth)->assertUnprocessable();
        $targets[1] = $this->target($two);
        $this->postJson($this->base.'/bulk-delete', ['products' => $targets, 'idempotency_key' => $key], $this->auth)->assertOk()->assertJsonPath('meta.deleted', 2);
        $this->postJson($this->base.'/bulk-delete', ['products' => $targets, 'idempotency_key' => $key], $this->auth)->assertOk()->assertJsonPath('meta.deleted', 2);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_permissions_tenant_isolation_and_template_contract(): void
    {
        $this->get($this->base.'/import-template', $this->auth)->assertOk()->assertDownload('sellchaze-products-template.xlsx');
        $id = $this->imported();
        app(CurrentStore::class)->forget();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Foreign', 'slug' => 'foreign-transfer', 'status' => 'active']);
        $foreign = Product::create(['store_id' => $other->id, 'name' => 'Foreign', 'slug' => 'foreign', 'price' => 2]);
        $targets = [$this->target($id), ['id' => $foreign->id, 'version' => str_repeat('a', 64)]];
        $this->postJson($this->base.'/export', ['products' => $targets], $this->auth)->assertUnprocessable();
        $this->postJson($this->base.'/bulk-delete', ['products' => $targets, 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertUnprocessable();
        Role::findByName('Merchant', 'web')->revokePermissionTo('products-create');
        $this->post($this->base.'/import/preview', ['file' => $this->file([['name' => 'No', 'price' => 1]])], $this->auth)->assertForbidden();
        $this->postJson($this->base.'/'.$id.'/duplicate', ['version' => $targets[0]['version'], 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertForbidden();
    }

    public function test_destructive_selection_detects_changed_description_media_categories_and_variant_prices_but_not_stock(): void
    {
        $id = $this->imported(['variants' => [['name' => 'Small', 'options' => ['Size' => 'S'], 'stock_quantity' => 8, 'track_inventory' => true]]]);
        $target = $this->target($id);
        Product::query()->findOrFail($id)->variants()->first()->update(['stock_quantity' => 9]);
        $this->assertSame($target['version'], $this->target($id)['version']);
        Product::query()->whereKey($id)->update(['description' => 'Changed description']);
        $this->postJson($this->base.'/bulk-delete', ['products' => [$target], 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertUnprocessable();
        $target = $this->target($id);
        Product::query()->findOrFail($id)->variants()->first()->update(['price_override' => 99]);
        $this->postJson($this->base.'/export', ['products' => [$target]], $this->auth)->assertUnprocessable();
        $target = $this->target($id);
        ProductMedia::create(['store_id' => $this->store->id, 'store_product_id' => $id, 'type' => 'gallery', 'path' => 'https://example.com/new.jpg']);
        $this->postJson($this->base.'/'.$id.'/duplicate', ['version' => $target['version'], 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertUnprocessable();
        $this->assertDatabaseCount('products', 1);
    }

    public function test_duplicate_preserves_native_rich_fields_and_remaps_option_and_variant_media(): void
    {
        $id = $this->imported(['images' => ['https://example.com/blue.jpg'], 'variants' => [['name' => 'Blue', 'options' => ['Color' => 'Blue'], 'image_url' => 'https://example.com/blue.jpg', 'stock_quantity' => 6, 'track_inventory' => true]],
            'option_display' => [['name' => 'Color', 'type' => 'image', 'values' => [['value' => 'Blue', 'image_url' => 'https://example.com/blue.jpg']]]]]);
        $source = Product::query()->findOrFail($id);
        $source->update(['highlights' => ['Original benefit'], 'material' => 'Cotton', 'specifications' => ['finish' => 'Matte'], 'sales_count' => 55]);
        ProductMedia::create(['store_id' => $this->store->id, 'store_product_id' => $id, 'type' => 'video', 'path' => 'https://example.com/video.mp4', 'mime' => 'video/mp4']);
        $target = $this->target($id);
        $copyId = $this->postJson($this->base.'/'.$id.'/duplicate', ['version' => $target['version'], 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertCreated()->json('meta.id');
        $copy = Product::query()->findOrFail($copyId);
        $this->assertEquals(['Original benefit'], $copy->highlights);
        $this->assertEquals(['finish' => 'Matte'], $copy->specifications);
        $this->assertEquals('Cotton', $copy->material);
        $this->assertEquals(0, $copy->sales_count);
        $mediaId = $copy->option_display[0]['values'][0]['media_id'];
        $this->assertNotEquals($source->media->first()->id, $mediaId);
        $this->assertEquals($copy->id, ProductMedia::findOrFail($mediaId)->store_product_id);
        $this->assertEquals($mediaId, $copy->variants->first()->image_media_id);
        $this->assertEquals(0, $copy->variants->first()->stock_quantity);
        $this->assertEquals(1, $copy->media()->where('type', 'video')->count());
    }

    public function test_category_memberships_are_editable_and_missing_category_create_permission_prevents_partial_import(): void
    {
        $id = $this->imported(['categories' => ['One', 'Two']]);
        $data = $this->getJson($this->base.'/'.$id, $this->auth)->assertOk()->json('data');
        $this->assertCount(2, $data['category_ids']);
        $this->post($this->base.'/'.$id, ['_method' => 'PUT', 'name' => 'Renamed', 'category_id' => $data['category_ids'][1], 'category_ids' => json_encode([$data['category_ids'][1]])], $this->auth)->assertOk()->assertJsonCount(1, 'data.category_ids');
        $this->putJson($this->base.'/'.$id, ['category_id' => null, 'category_ids' => []], $this->auth)->assertOk()->assertJsonCount(0, 'data.category_ids');
        Role::findByName('Merchant', 'web')->revokePermissionTo('categories-create');
        $this->post($this->base.'/import', ['file' => $this->file([['name' => 'Blocked', 'price' => 1, 'categories' => ['New category']]]), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertForbidden();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('categories', 2);
        $this->imported(['categories' => ['Two']]);
    }

    public function test_digital_exports_exclude_delivery_secrets_without_changing_product_type_and_copies_remain_private_drafts(): void
    {
        $id = $this->imported(['digital_type' => 'link', 'digital_url' => 'https://example.com/private-delivery']);
        $target = $this->target($id);
        $document = $this->postJson($this->base.'/export', ['products' => [$target]], $this->auth)->assertOk()->json();
        $this->assertSame('link', $document['products'][0]['digital_type']);
        $this->assertFalse($document['products'][0]['is_active']);
        $this->assertStringNotContainsString('private-delivery', json_encode($document));
        $this->post($this->base.'/import/preview', ['file' => $this->file($document)], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('products.0.digital_url');
        $copyId = $this->postJson($this->base.'/'.$id.'/duplicate', ['version' => $target['version'], 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertCreated()->json('meta.id');
        $this->assertDatabaseHas('products', ['id' => $copyId, 'digital_type' => 'link', 'is_active' => false]);
        $this->assertSame('https://example.com/private-delivery', Product::query()->findOrFail($copyId)->digital_url);
        $this->assertDatabaseCount('products', 2);
    }

    public function test_external_identity_references_and_exact_untracked_duplicates_are_supported_without_merging_tracked_or_conflicting_options(): void
    {
        $row = ['name' => 'External duplicate', 'price' => 20, 'sale_price' => 10, 'track_stock' => false, 'quantity' => -1,
            'variations' => [['id' => 'size', 'name' => 'Size', 'type' => 'buttons', 'props' => [['id' => 'small', 'name' => 'S']]]],
            'variants' => [['id' => 'first', 'quantity' => -1, 'variation_props' => [['variation' => 'size', 'variation_prop' => 'small']]],
                ['id' => 'second', 'quantity' => -1, 'variation_props' => [['variation' => 'Size', 'variation_prop' => 'S']]]]];
        $this->post($this->base.'/import/preview', ['file' => $this->file([$row])], $this->auth)->assertOk()->assertJsonPath('data.products.0.variants', 1)->assertJsonPath('data.products.0.warnings.0', 'merged_duplicate_options');
        $id = $this->post($this->base.'/import', ['file' => $this->file([$row]), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertCreated()->json('meta.ids.0');
        $this->assertEquals(0, Product::query()->findOrFail($id)->variants->first()->stock_quantity);
        $row['track_stock'] = true;
        $row['quantity'] = 2;
        foreach ($row['variants'] as &$variant) {
            $variant['quantity'] = 2;
        }
        unset($variant);
        $this->post($this->base.'/import/preview', ['file' => $this->file([$row])], $this->auth)->assertUnprocessable();
        $row['variations'][0]['type'] = 'color';
        $row['variations'][0]['props'] = [['id' => 'small', 'name' => 'S', 'value' => '#0000ff'], ['id' => 'small-other', 'name' => 'S', 'value' => '#ff0000']];
        $this->post($this->base.'/import/preview', ['file' => $this->file([$row])], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('products.0');
        $this->assertDatabaseCount('products', 1);
    }

    public function test_explicit_imported_copy_wins_over_stale_default_locale_translations(): void
    {
        $this->store->update(['default_locale' => 'ar', 'supported_locales' => ['ar', 'en']]);
        $id = $this->imported(['name' => 'Edited canonical name', 'description' => '<p>Edited copy</p>', 'translations' => ['name' => ['ar' => 'Stale name', 'en' => 'English name'], 'description' => ['ar' => '<p>Stale copy</p>']]]);
        $data = $this->getJson($this->base.'/'.$id, $this->auth)->assertOk()->json('data');
        $this->assertSame('Edited canonical name', $data['name']);
        $this->assertSame('Edited canonical name', $data['translations']['name']['ar']);
        $this->assertSame('English name', $data['translations']['name']['en']);
        $this->assertSame('<p>Edited copy</p>', $data['description']);
        $this->assertSame('<p>Edited copy</p>', $data['translations']['description']['ar']);
    }

    public function test_reimported_public_media_urls_keep_managed_files_until_the_final_reference_is_removed(): void
    {
        $id = $this->imported(['images' => ['https://example.com/original.jpg']]);
        $path = 'store-catalog/portable-photo.jpg';
        Storage::disk('public')->put($path, 'synthetic photo');
        $source = Product::query()->findOrFail($id);
        $source->update(['image' => $path]);
        $source->media()->first()->update(['path' => $path]);
        $document = $this->postJson($this->base.'/export', ['products' => [$this->target($id)]], $this->auth)->assertOk()->json();
        $copyId = $this->post($this->base.'/import', ['file' => $this->file($document), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertCreated()->json('meta.ids.0');
        $this->assertStringContainsString('/storage/'.$path, Product::query()->findOrFail($copyId)->image);
        $service = app(StorefrontProductService::class);
        $cleanup = new \ReflectionMethod(StorefrontProductService::class, 'deleteImage');
        $service->delete($source);
        // Simulate after-commit file cleanup inside the test transaction.
        $cleanup->invoke($service, $path);
        Storage::disk('public')->assertExists($path);
        $service->delete(Product::query()->findOrFail($copyId));
        $cleanup->invoke($service, $path);
        Storage::disk('public')->assertMissing($path);
    }
}
