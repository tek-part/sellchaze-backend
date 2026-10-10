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
}
