<?php

namespace Tests\Feature;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreCatalogEditorTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private array $auth;

    private string $base = '/api/v1/my-store/catalog/products';

    private string $public = 'http://editor.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Queue::fake([BridgeStorefrontOrderJob::class]);
        Storage::fake('public');
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Editor', 'slug' => 'editor', 'currency' => 'EGP', 'default_locale' => 'ar', 'supported_locales' => ['ar', 'en'], 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'editor.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
    }

    private function create(array $extra = []): int
    {
        return $this->postJson($this->base, array_merge(['name' => 'حقيبة', 'slug' => 'bag', 'price' => 125, 'is_active' => true], $extra), $this->auth)->assertCreated()->json('data.id');
    }

    public function test_option_display_round_trips_and_localizes_without_changing_variant_identity(): void
    {
        $id = $this->create();
        $variant = $this->postJson($this->base.'/'.$id.'/variants', ['name' => 'Blue S', 'options' => ['Color' => 'Blue', 'Size' => 'S']], $this->auth)->assertCreated()->json('data.id');
        $media = $this->post($this->base.'/'.$id, ['_method' => 'PUT', 'gallery' => [UploadedFile::fake()->image('blue.png')]], $this->auth + ['Accept' => 'application/json'])->assertOk()->json('data.media.0.id');
        $display = [['name' => 'Color', 'type' => 'image', 'labels' => ['ar' => 'اللون', 'en' => 'Colour'], 'values' => [['value' => 'Blue', 'labels' => ['ar' => 'أزرق'], 'color' => '#0055ff', 'media_id' => $media]]]];
        $this->putJson($this->base.'/'.$id, ['option_display' => $display], $this->auth)->assertOk()->assertJsonPath('data.option_display', $display);
        $public = $this->getJson($this->public.'/products/bag?lang=ar')->assertOk()->assertJsonPath('data.option_display.0.label', 'اللون')->assertJsonPath('data.option_display.0.values.0.label', 'أزرق')->assertJsonPath('data.variants.0.id', $variant)->assertJsonMissingPath('data.option_display.0.labels');
        $this->assertStringContainsString('/storage/store-catalog/', $public->json('data.option_display.0.values.0.image_url'));
        $this->getJson($this->public.'/products/bag?lang=en')->assertOk()->assertJsonPath('data.option_display.0.label', 'Colour')->assertJsonPath('data.option_display.0.values.0.label', 'Blue');
        $this->putJson($this->base.'/'.$id, ['price' => 130], $this->auth)->assertOk()->assertJsonPath('data.option_display', $display);
        $this->putJson($this->base.'/'.$id, ['remove_media_ids' => [$media]], $this->auth)->assertOk();
        $this->getJson($this->public.'/products/bag')->assertOk()->assertJsonPath('data.option_display.0.values.0.image_url', null);
        $this->putJson($this->base.'/'.$id, ['option_display' => []], $this->auth)->assertOk()->assertJsonPath('data.option_display', []);
    }

    public function test_option_display_rejects_unknown_duplicate_unsafe_and_foreign_values_atomically(): void
    {
        $id = $this->create();
        $this->postJson($this->base.'/'.$id.'/variants', ['name' => 'Blue', 'options' => ['Color' => 'Blue']], $this->auth)->assertCreated();
        $other = $this->create(['slug' => 'other']);
        $media = $this->post($this->base.'/'.$other, ['_method' => 'PUT', 'gallery' => [UploadedFile::fake()->image('other.png')]], $this->auth + ['Accept' => 'application/json'])->assertOk()->json('data.media.0.id');
        $axis = ['name' => 'Color', 'type' => 'color', 'values' => [['value' => 'Blue', 'color' => '#123456']]];
        $bad = [
            [$axis, array_merge($axis, ['name' => ' COLOR '])],
            [array_merge($axis, ['name' => 'Missing'])],
            [array_merge($axis, ['type' => 'html'])],
            [array_merge($axis, ['values' => [['value' => 'Green']]])],
            [array_merge($axis, ['values' => [['value' => 'Blue'], ['value' => ' blue ']]])],
            [array_merge($axis, ['values' => [['value' => 'Blue', 'color' => 'url(https://bad.test)']]])],
            [array_merge($axis, ['values' => [['value' => 'Blue', 'media_id' => $media]]])],
        ];
        foreach ($bad as $display) {
            $this->putJson($this->base.'/'.$id, ['name' => 'Do not save', 'option_display' => $display], $this->auth)->assertUnprocessable();
        }
        $this->getJson($this->base.'/'.$id, $this->auth)->assertOk()->assertJsonPath('data.name', 'حقيبة')->assertJsonPath('data.option_display', []);
    }

    public function test_editor_round_trips_translations_cost_seo_and_zero_price_without_public_cost_leak(): void
    {
        $id = $this->create(['cost' => 70, 'compare_price' => 160, 'weight' => 0.45, 'seo_title' => 'عنوان البحث', 'seo_description' => 'وصف البحث',
            'translations' => ['name' => ['ar' => 'حقيبة', 'en' => 'Bag'], 'description' => ['ar' => 'وصف عربي', 'en' => 'English copy']]]);
        $this->getJson($this->base.'/'.$id.'?lang=en', $this->auth)->assertOk()->assertJsonPath('data.name', 'حقيبة')->assertJsonPath('data.translations.name.en', 'Bag')->assertJsonPath('data.cost', '70.00')->assertJsonPath('data.seo_title', 'عنوان البحث');
        $this->getJson($this->public.'/products/bag?lang=en')->assertOk()->assertJsonPath('data.name', 'Bag')->assertJsonMissingPath('data.cost')->assertJsonMissingPath('data.translations');
        $this->putJson($this->base.'/'.$id, ['price' => 0, 'is_active' => false], $this->auth)->assertOk()->assertJsonPath('data.price', '0.00');
        $this->getJson($this->public.'/products/bag')->assertNotFound();
        $this->getJson($this->base.'?status=draft', $this->auth)->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_variant_image_is_owned_image_only_and_clears_when_gallery_media_is_removed(): void
    {
        $id = $this->create();
        $path = $this->base.'/'.$id.'/variants';
        $media = $this->post($this->base.'/'.$id, ['_method' => 'PUT', 'gallery' => [UploadedFile::fake()->image('variant.png'), UploadedFile::fake()->create('video.mp4', 5, 'video/mp4')]], $this->auth + ['Accept' => 'application/json'])->assertOk()->json('data.media');
        $created = $this->postJson($path, ['name' => 'Blue', 'image_media_id' => $media[0]['id']], $this->auth)->assertCreated()->assertJsonPath('data.image_url', $media[0]['url'])->json('data');
        $this->getJson($this->public.'/products/bag')->assertOk()->assertJsonPath('data.variants.0.image_url', $media[0]['url'])->assertJsonMissingPath('data.variants.0.edit_version');
        $this->putJson($path.'/'.$created['id'], ['name' => 'Unchanged', 'image_media_id' => $media[1]['id']], $this->auth)->assertUnprocessable();
        $this->putJson($this->base.'/'.$id, ['remove_media_ids' => [$media[0]['id']]], $this->auth)->assertOk();
        $this->getJson($path, $this->auth)->assertOk()->assertJsonPath('data.0.image_media_id', null)->assertJsonPath('data.0.image_url', null)->assertJsonPath('data.0.name', 'Blue');
        $this->putJson($path.'/'.$created['id'], ['name' => 'Stale edit', 'edit_version' => $created['edit_version']], $this->auth)->assertUnprocessable();
    }

    public function test_bulk_variant_edits_change_only_requested_fields_and_preserve_inventory(): void
    {
        $id = $this->create();
        $path = $this->base.'/'.$id.'/variants';
        $one = $this->postJson($path, ['name' => 'Blue', 'price_override' => 50, 'cost' => 10], $this->auth)->assertCreated()->json('data');
        $two = $this->postJson($path, ['name' => 'Red', 'price_override' => 60, 'cost' => 20], $this->auth)->assertCreated()->json('data');
        $targets = array_map(fn ($row) => ['id' => $row['id'], 'version' => $row['edit_version']], [$one, $two]);
        $this->putJson('/api/v1/my-store/catalog/inventory/'.$id, ['variant_id' => $one['id'], 'track_inventory' => true, 'stock_quantity' => 4, 'expected_stock' => 0, 'expected_reserved' => 0, 'expected_tracking' => false], $this->auth)->assertOk();
        $this->putJson($path.'/bulk', ['variants' => $targets, 'changes' => ['price_override' => 0, 'is_active' => false]], $this->auth)->assertOk()->assertJsonPath('meta.updated', 2);
        $this->assertDatabaseHas('store_product_variants', ['id' => $one['id'], 'name' => 'Blue', 'cost' => 10, 'price_override' => 0, 'stock_quantity' => 4, 'is_active' => false]);
        $this->assertDatabaseHas('store_product_variants', ['id' => $two['id'], 'cost' => 20, 'price_override' => 0, 'is_active' => false]);
        $rows = $this->getJson($path, $this->auth)->assertOk()->json('data');
        $targets = array_map(fn ($row) => ['id' => $row['id'], 'version' => $row['edit_version']], $rows);
        $this->putJson($path.'/bulk', ['variants' => $targets, 'changes' => ['price_override' => null, 'is_active' => true]], $this->auth)->assertOk();
        $this->getJson($path, $this->auth)->assertOk()->assertJsonPath('data.0.effective_price', '125.00')->assertJsonPath('data.1.effective_price', '125.00');
    }

    public function test_bulk_edit_rolls_back_when_last_variant_is_stale_or_foreign_and_rejects_inventory_fields(): void
    {
        $id = $this->create();
        $path = $this->base.'/'.$id.'/variants';
        $one = $this->postJson($path, ['name' => 'Blue', 'price_override' => 50], $this->auth)->assertCreated()->json('data');
        $two = $this->postJson($path, ['name' => 'Red', 'price_override' => 60], $this->auth)->assertCreated()->json('data');
        $targets = array_map(fn ($row) => ['id' => $row['id'], 'version' => $row['edit_version']], [$one, $two]);
        $this->putJson($path.'/'.$two['id'], ['name' => 'New red', 'edit_version' => $two['edit_version']], $this->auth)->assertOk();
        $this->putJson($path.'/bulk', ['variants' => $targets, 'changes' => ['price_override' => 99]], $this->auth)->assertUnprocessable();
        $this->assertDatabaseHas('store_product_variants', ['id' => $one['id'], 'price_override' => 50]);
        $this->assertDatabaseHas('store_product_variants', ['id' => $two['id'], 'name' => 'New red', 'price_override' => 60]);
        $other = $this->create(['slug' => 'other']);
        $foreign = $this->postJson($this->base.'/'.$other.'/variants', ['name' => 'Other'], $this->auth)->assertCreated()->json('data');
        $targets[1] = ['id' => $foreign['id'], 'version' => $foreign['edit_version']];
        $this->putJson($path.'/bulk', ['variants' => $targets, 'changes' => ['price_override' => 99]], $this->auth)->assertUnprocessable();
        foreach ([['stock_quantity' => 900], ['reserved_quantity' => 0], ['name' => 'Rename all'], [], ['price_override' => -1]] as $changes) {
            $this->putJson($path.'/bulk', ['variants' => [$targets[0]], 'changes' => $changes], $this->auth)->assertUnprocessable();
        }
        $this->assertDatabaseHas('store_product_variants', ['id' => $one['id'], 'price_override' => 50, 'stock_quantity' => 0]);
    }

    public function test_variant_versions_are_stable_across_json_key_order_and_foreign_images_are_rejected(): void
    {
        $id = $this->create();
        $path = $this->base.'/'.$id.'/variants';
        $row = $this->postJson($path, ['name' => 'Blue S', 'options' => ['Color' => 'Blue', 'Size' => 'S']], $this->auth)->assertCreated()->json('data');
        $this->putJson($path.'/'.$row['id'], ['name' => 'Blue S', 'options' => ['Size' => 'S', 'Color' => 'Blue'], 'edit_version' => $row['edit_version']], $this->auth)->assertOk()->assertJsonPath('data.edit_version', $row['edit_version']);
        $other = $this->create(['slug' => 'other-image']);
        $media = $this->post($this->base.'/'.$other, ['_method' => 'PUT', 'gallery' => [UploadedFile::fake()->image('other.png')]], $this->auth + ['Accept' => 'application/json'])->assertOk()->json('data.media.0.id');
        $this->putJson($path.'/bulk', ['variants' => [['id' => $row['id'], 'version' => $row['edit_version']]], 'changes' => ['price_override' => 99, 'image_media_id' => $media]], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('image_media_id');
        $this->getJson($path, $this->auth)->assertOk()->assertJsonPath('data.0.price_override', null)->assertJsonPath('data.0.image_media_id', null);
    }

    public function test_uploaded_gallery_and_cover_are_returned_to_editor_and_storefront(): void
    {
        $response = $this->post($this->base, ['name' => 'صور', 'slug' => 'images', 'price' => '12.50', 'is_active' => '1',
            'translations' => json_encode(['name' => ['ar' => 'صور', 'en' => 'Images']]),
            'image' => UploadedFile::fake()->image('cover.png'), 'gallery' => [UploadedFile::fake()->image('one.png'), UploadedFile::fake()->image('two.png')]], $this->auth + ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(2, 'data.media');
        $id = $response->json('data.id');
        Storage::disk('public')->assertExists($response->json('data.image'));
        $this->getJson($this->public.'/products/images')->assertOk()->assertJsonCount(2, 'data.images');
        $this->putJson($this->base.'/'.$id, ['remove_image' => true, 'remove_media_ids' => [$response->json('data.media.0.id')]], $this->auth)->assertOk()->assertJsonPath('data.image_url', null)->assertJsonCount(1, 'data.media');
    }

    public function test_foreign_category_media_and_product_cannot_be_edited(): void
    {
        $id = $this->create();
        app(CurrentStore::class)->forget();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-editor', 'status' => 'active']);
        $foreign = Product::create(['store_id' => $other->id, 'name' => 'Other', 'slug' => 'other', 'price' => 4]);
        $category = Category::create(['store_id' => $other->id, 'name' => 'Other', 'slug' => 'other']);
        $media = ProductMedia::create(['store_id' => $other->id, 'store_product_id' => $foreign->id, 'type' => 'gallery', 'path' => 'foreign.png', 'disk' => 'public']);
        $this->putJson($this->base.'/'.$id, ['category_id' => $category->id], $this->auth)->assertUnprocessable();
        $this->putJson($this->base.'/'.$id, ['name' => 'Should roll back', 'remove_media_ids' => [$media->id]], $this->auth)->assertUnprocessable();
        $this->getJson($this->base.'/'.$foreign->id, $this->auth)->assertNotFound();
        $this->assertDatabaseHas('products', ['id' => $id, 'name' => 'حقيبة']);
        $this->assertDatabaseHas('store_product_media', ['id' => $media->id]);
    }

    public function test_variant_editor_supports_translations_inherited_and_free_prices_and_inventory(): void
    {
        $id = $this->create();
        $path = $this->base.'/'.$id.'/variants';
        $created = $this->postJson($path, ['name' => 'أزرق', 'translations' => ['name' => ['ar' => 'أزرق', 'en' => 'Blue']], 'options' => ['Color' => 'Blue', 'Size' => 'M'], 'price_override' => null, 'cost' => 50, 'compare_price' => 170, 'is_active' => true], $this->auth)->assertCreated()->assertJsonPath('data.effective_price', '125.00')->assertJsonPath('data.cost', '50.00');
        $variantId = $created->json('data.id');
        $this->putJson($path.'/'.$variantId, ['name' => 'أزرق', 'price_override' => 0], $this->auth)->assertOk()->assertJsonPath('data.effective_price', '0.00');
        $this->putJson('/api/v1/my-store/catalog/inventory/'.$id, ['variant_id' => $variantId, 'track_inventory' => true, 'stock_quantity' => 4, 'expected_stock' => 0, 'expected_reserved' => 0, 'expected_tracking' => false], $this->auth)->assertOk();
        $this->getJson($path, $this->auth)->assertOk()->assertJsonPath('data.0.stock_quantity', 4)->assertJsonPath('data.0.translations.name.en', 'Blue');
        $this->getJson($this->public.'/products/bag?lang=en')->assertOk()->assertJsonPath('data.variants.0.name', 'Blue')->assertJsonPath('data.variants.0.stock', 4)->assertJsonMissingPath('data.variants.0.cost');
        $this->postJson($path, ['name' => 'Bad price', 'price_override' => 1.999], $this->auth)->assertUnprocessable();
        $this->postJson($path, ['name' => 'Bad options', 'options' => ['color' => ['nested']]], $this->auth)->assertUnprocessable();
        $this->postJson($path, ['name' => 'Ambiguous options', 'options' => ['Color' => 'Blue', 'color' => 'Red']], $this->auth)->assertUnprocessable();
    }

    public function test_reserved_base_product_cannot_be_converted_to_options(): void
    {
        $id = $this->create();
        $this->putJson('/api/v1/my-store/catalog/inventory/'.$id, ['track_inventory' => true, 'stock_quantity' => 2, 'expected_stock' => 0, 'expected_reserved' => 0, 'expected_tracking' => false], $this->auth)->assertOk();
        $this->postJson($this->public.'/checkout', ['customer_name' => 'Local buyer', 'customer_email' => 'local@example.test', 'items' => [['product_id' => $id, 'quantity' => 1]]])->assertCreated();
        $this->postJson($this->base.'/'.$id.'/variants', ['name' => 'New option'], $this->auth)->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $id, 'reserved_quantity' => 1]);
        $this->assertDatabaseCount('store_product_variants', 0);
    }

    public function test_generation_is_additive_and_repeatable_without_overwriting_stock_or_price(): void
    {
        $id = $this->create();
        $path = $this->base.'/'.$id.'/variants';
        $variantId = $this->postJson($path, ['name' => 'Existing', 'options' => ['Color' => 'Blue', 'Size' => 'S'], 'price_override' => 0], $this->auth)->assertCreated()->json('data.id');
        $this->putJson('/api/v1/my-store/catalog/inventory/'.$id, ['variant_id' => $variantId, 'track_inventory' => true, 'stock_quantity' => 4, 'expected_stock' => 0, 'expected_reserved' => 0, 'expected_tracking' => false], $this->auth)->assertOk();
        $axes = [['name' => 'color', 'values' => ['blue', 'Red']], ['name' => 'Size', 'values' => ['S', 'M']]];
        $this->postJson($path.'/generate', ['axes' => $axes], $this->auth)->assertOk()->assertJsonPath('meta.created', 3)->assertJsonPath('meta.existing', 1);
        $this->postJson($path.'/generate', ['axes' => array_reverse($axes)], $this->auth)->assertOk()->assertJsonPath('meta.created', 0)->assertJsonPath('meta.existing', 4);
        $rows = $this->getJson($path, $this->auth)->assertOk()->assertJsonCount(4, 'data')->json('data');
        $this->assertDatabaseHas('store_product_variants', ['id' => $variantId, 'name' => 'Existing', 'price_override' => 0, 'stock_quantity' => 4]);
        foreach ($rows as $row) {
            if ($row['id'] !== $variantId) {
                $this->assertTrue($row['track_inventory']);
                $this->assertSame(0, $row['stock_quantity']);
                $this->assertSame('125.00', $row['effective_price']);
            }
        }
        $this->postJson($path, ['name' => 'Duplicate', 'options' => ['Size' => 'S', 'Color' => 'BLUE']], $this->auth)->assertUnprocessable();
        $this->putJson($path.'/'.$rows[1]['id'], ['name' => 'Duplicate edit', 'options' => ['Size' => 'S', 'Color' => 'Blue']], $this->auth)->assertUnprocessable();
    }

    public function test_generator_rejects_duplicate_axes_values_and_unbounded_combinations(): void
    {
        $id = $this->create();
        $path = $this->base.'/'.$id.'/variants/generate';
        foreach ([[], [['name' => 'Color', 'values' => ['Blue', 'blue']]],
            [['name' => 'Color', 'values' => ['Blue']], ['name' => 'color', 'values' => ['Red']]],
            [['name' => '123', 'values' => ['Value']]],
            [['name' => 'Color', 'values' => array_map('strval', range(1, 50))], ['name' => 'Size', 'values' => ['a', 'b', 'c', 'd', 'e']]],
        ] as $axes) {
            $this->postJson($path, ['axes' => $axes], $this->auth)->assertUnprocessable();
        }
        $this->assertDatabaseCount('store_product_variants', 0);
    }

    public function test_generation_rolls_back_all_rows_when_a_combination_name_is_too_long(): void
    {
        $id = $this->create();
        $axes = [['name' => 'One', 'values' => ['short', str_repeat('x', 120)]], ['name' => 'Two', 'values' => [str_repeat('y', 120)]], ['name' => 'Three', 'values' => [str_repeat('z', 40)]]];
        $this->postJson($this->base.'/'.$id.'/variants/generate', ['axes' => $axes], $this->auth)->assertUnprocessable();
        $this->assertDatabaseCount('store_product_variants', 0);
    }

    public function test_base_stock_must_be_reconciled_before_first_option_and_generation_is_tenant_scoped(): void
    {
        $id = $this->create();
        $this->putJson('/api/v1/my-store/catalog/inventory/'.$id, ['track_inventory' => true, 'stock_quantity' => 2, 'expected_stock' => 0, 'expected_reserved' => 0, 'expected_tracking' => false], $this->auth)->assertOk();
        $axes = [['name' => 'Color', 'values' => ['Blue']]];
        $this->postJson($this->base.'/'.$id.'/variants/generate', ['axes' => $axes], $this->auth)->assertUnprocessable();
        $this->postJson($this->base.'/'.$id.'/variants', ['name' => 'Blue'], $this->auth)->assertUnprocessable();
        $this->putJson('/api/v1/my-store/catalog/inventory/'.$id, ['track_inventory' => true, 'stock_quantity' => 0, 'expected_stock' => 2, 'expected_reserved' => 0, 'expected_tracking' => true], $this->auth)->assertOk();
        $this->postJson($this->base.'/'.$id.'/variants/generate', ['axes' => $axes], $this->auth)->assertOk()->assertJsonPath('meta.created', 1);
        app(CurrentStore::class)->forget();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-options', 'status' => 'active']);
        $foreign = Product::create(['store_id' => $other->id, 'name' => 'Foreign', 'slug' => 'foreign-options', 'price' => 1]);
        $this->postJson($this->base.'/'.$foreign->id.'/variants/generate', ['axes' => $axes], $this->auth)->assertNotFound();
        $this->assertDatabaseCount('store_product_variants', 1);
    }

    public function test_rich_description_edits_replace_legacy_copy_and_sanitize_every_locale(): void
    {
        $id = $this->create();
        Product::withoutGlobalScopes()->whereKey($id)->update(['long_description' => '<h2>Legacy detail</h2>']);
        $this->getJson($this->base.'/'.$id, $this->auth)->assertOk()->assertJsonPath('data.description', '<h2>Legacy detail</h2>');
        $this->putJson($this->base.'/'.$id, ['price' => 99], $this->auth)->assertOk();
        $this->assertDatabaseHas('products', ['id' => $id, 'long_description' => '<h2>Legacy detail</h2>']);
        $this->putJson($this->base.'/'.$id, ['description' => '<h2>وصف جديد</h2><script>alert(1)</script>',
            'translations' => ['description' => ['ar' => '<h2>وصف جديد</h2>', 'en' => '<h2>New description</h2><img src="/storage/safe.png" onerror="alert(1)">']]], $this->auth)->assertOk()->assertJsonPath('data.long_description', null);
        $public = $this->getJson($this->public.'/products/bag?lang=en')->assertOk()->json('data');
        $this->assertStringContainsString('<h2>New description</h2>', $public['description']);
        $this->assertStringNotContainsString('onerror', $public['description']);
        $this->assertDatabaseHas('products', ['id' => $id, 'description' => '<h2>وصف جديد</h2>', 'long_description' => null]);
    }

    public function test_video_upload_order_and_removal_keep_legacy_images_contract(): void
    {
        $response = $this->post($this->base, ['name' => 'Media', 'slug' => 'media', 'price' => 1, 'is_active' => true,
            'gallery' => [UploadedFile::fake()->image('one.png')->size(10240), UploadedFile::fake()->create('clip.mp4', 51200, 'video/mp4')]], $this->auth + ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(2, 'data.media');
        $id = $response->json('data.id');
        $media = $response->json('data.media');
        $this->assertSame('video', $media[1]['type']);
        $videoPath = ProductMedia::withoutGlobalScopes()->findOrFail($media[1]['id'])->path;
        Storage::disk('public')->assertExists($videoPath);
        $this->putJson($this->base.'/'.$id, ['media_order' => [$media[1]['id'], $media[0]['id']]], $this->auth)->assertOk()->assertJsonPath('data.media.0.type', 'video');
        $this->getJson($this->public.'/products/media')->assertOk()->assertJsonCount(1, 'data.images')->assertJsonPath('data.media.0.type', 'video');
        $this->putJson($this->base.'/'.$id, ['name' => 'Must roll back', 'media_order' => [999999]], $this->auth)->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $id, 'name' => 'Media']);
        $this->putJson($this->base.'/'.$id, ['remove_media_ids' => [$media[1]['id']], 'media_order' => [$media[0]['id']]], $this->auth)->assertOk()->assertJsonCount(1, 'data.media');
        Storage::disk('public')->assertMissing($videoPath);
        $imagePath = ProductMedia::withoutGlobalScopes()->findOrFail($media[0]['id'])->path;
        $this->deleteJson($this->base.'/'.$id, [], $this->auth)->assertOk();
        Storage::disk('public')->assertMissing($imagePath);
    }

    public function test_media_upload_limits_and_disguised_files_are_rejected_atomically(): void
    {
        foreach ([UploadedFile::fake()->image('large.png')->size(10241), UploadedFile::fake()->create('large.mp4', 51201, 'video/mp4'),
            UploadedFile::fake()->createWithContent('fake.mp4', '<html><script>alert(1)</script></html>'),
            UploadedFile::fake()->create('disguised.html', 10, 'video/mp4'), UploadedFile::fake()->create('clip.webm', 10, 'video/webm'),
        ] as $file) {
            $this->post($this->base, ['name' => 'Invalid', 'price' => 1, 'gallery' => [$file]], $this->auth + ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('store_product_media', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
