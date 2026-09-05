<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreBrand;
use App\Models\StoreCollection;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** E3: `translations` json on products/categories/collections/brands/variants, mirrored into base columns. */
class CatalogTranslationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Supplier');
        $this->store = Store::create([
            'owner_user_id' => $this->owner->id, 'owner_type' => 'supplier', 'name' => 'Nike', 'slug' => 'nike',
            'currency' => 'USD', 'status' => 'active', 'default_locale' => 'en', 'supported_locales' => ['en', 'ar'],
        ]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'nike.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
    }

    private function api(): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
    }

    private function category(): Category
    {
        return Category::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name_en' => 'Shoes', 'name_ar' => 'أحذية', 'slug' => 'shoes', 'is_active' => true]);
    }

    public function test_trait_mirrors_default_locale_into_base_columns_and_seeds_from_legacy_writes(): void
    {
        app(CurrentStore::class)->set($this->store);
        $cat = $this->category();

        $product = new Product(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'category_id' => $cat->id, 'slug' => 'air-max', 'price' => 10, 'is_active' => true]);
        $product->fillTranslations(['name' => ['en' => 'Air Max', 'ar' => 'اير ماكس'], 'description' => ['ar' => 'وصف']]);
        $product->save();
        $product->refresh();

        $this->assertSame('Air Max', $product->name);
        $this->assertSame('اير ماكس', $product->translated('name', 'ar'));
        $this->assertSame('Air Max', $product->translated('name', 'fr'));      // fallback to store default
        $this->assertSame('وصف', $product->translated('description', 'ar'));
        $this->assertSame('وصف', $product->translated('description', 'en'));  // no en/default entry → first non-empty

        // Legacy write to the base column seeds the default-locale entry.
        $product->update(['name' => 'Air Max 90']);
        $product->refresh();
        $this->assertSame('Air Max 90', $product->translations['name']['en']);
        $this->assertSame('اير ماكس', $product->translations['name']['ar']);

        // Explicit translations win over a simultaneous base write.
        $product->name = 'Ignored';
        $product->setTranslations('name', ['en' => 'Air Max 95', 'ar' => 'اير ماكس ٩٥']);
        $product->save();
        $this->assertSame('Air Max 95', $product->fresh()->name);
    }

    public function test_category_keeps_name_en_name_ar_in_sync_with_translations(): void
    {
        app(CurrentStore::class)->set($this->store);
        $cat = $this->category()->fresh();
        $this->assertSame(['en' => 'Shoes', 'ar' => 'أحذية'], $cat->translations['name']);
        $this->assertSame('أحذية', $cat->translated('name', 'ar'));

        $cat->setTranslations('name', ['en' => 'Footwear', 'ar' => 'الأحذية']);
        $cat->save();
        $cat->refresh();
        $this->assertSame('Footwear', $cat->name_en);
        $this->assertSame('الأحذية', $cat->name_ar);
        $this->assertSame('Footwear', $cat->name);

        $cat->update(['name_ar' => 'حذاء']);
        $this->assertSame('حذاء', $cat->fresh()->translations['name']['ar']);
        $this->assertSame('Footwear', $cat->fresh()->translations['name']['en']);
    }

    public function test_product_api_validates_and_round_trips_translations_including_multipart_json(): void
    {
        $cat = $this->category();

        $created = $this->api()->postJson('/api/v1/products', [
            'name' => 'Air Max', 'category_id' => $cat->id,
            'translations' => ['name' => ['en' => 'Air Max', 'ar' => 'اير ماكس'], 'description' => ['ar' => 'وصف']],
        ])->assertCreated()
            ->assertJsonPath('data.translations.name.ar', 'اير ماكس')
            ->assertJsonPath('data.translations.description.ar', 'وصف');
        $id = $created->json('data.id');

        // multipart: translations as a JSON string
        $this->api()->put("/api/v1/products/{$id}", [
            'name' => 'Air Max', 'category_id' => $cat->id,
            'translations' => json_encode(['name' => ['en' => 'Air Max', 'ar' => 'اير ماكس ٢']]),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.translations.name.ar', 'اير ماكس ٢');

        $this->api()->putJson("/api/v1/products/{$id}", [
            'name' => 'Air Max', 'category_id' => $cat->id,
            'translations' => ['name' => ['fr' => 'Nope']],
        ])->assertStatus(422)->assertJsonValidationErrors(['translations.name']);

        $this->api()->putJson("/api/v1/products/{$id}", [
            'name' => 'Air Max', 'category_id' => $cat->id,
            'translations' => ['price' => ['en' => 'x']],
        ])->assertStatus(422)->assertJsonValidationErrors(['translations']);
    }

    public function test_category_api_accepts_translations_and_syncs_legacy_columns(): void
    {
        $res = $this->api()->postJson('/api/v1/categories', [
            'translations' => ['name' => ['en' => 'Bags', 'ar' => 'حقائب']],
        ])->assertCreated()
            ->assertJsonPath('data.name_en', 'Bags')
            ->assertJsonPath('data.name_ar', 'حقائب')
            ->assertJsonPath('data.translations.name.ar', 'حقائب');

        $this->api()->putJson('/api/v1/categories/'.$res->json('data.id'), [
            'name_en' => 'Bags', 'name_ar' => 'الحقائب',
        ])->assertOk()->assertJsonPath('data.translations.name.ar', 'الحقائب');
    }

    public function test_storefront_emits_translated_names_for_the_request_locale(): void
    {
        app(CurrentStore::class)->set($this->store);
        $cat = $this->category();
        $product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'category_id' => $cat->id, 'name' => 'Air Max', 'slug' => 'air-max', 'price' => 10, 'is_active' => true, 'is_featured' => true]);
        $product->setTranslations('name', ['en' => 'Air Max', 'ar' => 'اير ماكس'])->setTranslations('description', ['en' => 'Runs', 'ar' => 'يجري'])->save();
        $brand = StoreBrand::create(['store_id' => $this->store->id, 'name' => 'Nike', 'slug' => 'nike', 'is_active' => true, 'translations' => ['name' => ['en' => 'Nike', 'ar' => 'نايكي']]]);
        $product->update(['store_brand_id' => $brand->id]);
        ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $product->id, 'name' => 'Red', 'is_active' => true, 'translations' => ['name' => ['en' => 'Red', 'ar' => 'أحمر']]]);
        $collection = StoreCollection::create(['store_id' => $this->store->id, 'name' => 'Summer', 'slug' => 'summer', 'type' => 'manual', 'is_active' => true, 'translations' => ['name' => ['en' => 'Summer', 'ar' => 'صيف']]]);
        $collection->products()->attach($product->id, ['position' => 0, 'store_id' => $this->store->id]);
        app(CurrentStore::class)->forget();

        $this->getJson('http://nike.sellchase.com/api/v1/storefront/products?lang=ar')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('data.0.name', 'اير ماكس')
            ->assertJsonPath('data.0.category.name', 'أحذية');

        $this->getJson('http://nike.sellchase.com/api/v1/storefront/products/air-max?lang=ar')
            ->assertOk()
            ->assertJsonPath('data.name', 'اير ماكس')
            ->assertJsonPath('data.description', 'يجري')
            ->assertJsonPath('data.brand', 'نايكي')
            ->assertJsonPath('data.variants.0.name', 'أحمر')
            ->assertJsonPath('seo.json_ld.name', 'اير ماكس');

        $this->getJson('http://nike.sellchase.com/api/v1/storefront/products/air-max')
            ->assertJsonPath('data.name', 'Air Max')->assertJsonPath('data.brand', 'Nike');

        $this->getJson('http://nike.sellchase.com/api/v1/storefront/categories?lang=ar')->assertJsonPath('data.0.name', 'أحذية');
        $this->getJson('http://nike.sellchase.com/api/v1/storefront/collections?lang=ar')->assertJsonPath('data.0.name', 'صيف');
        $this->getJson('http://nike.sellchase.com/api/v1/storefront/brands?lang=ar')->assertJsonPath('data.0.name', 'نايكي');

        // Homepage is cached per locale: Arabic after English must not serve English names.
        $this->getJson('http://nike.sellchase.com/api/v1/storefront')->assertJsonPath('homepage.featured_products.0.name', 'Air Max');
        $this->getJson('http://nike.sellchase.com/api/v1/storefront?lang=ar')
            ->assertJsonPath('homepage.featured_products.0.name', 'اير ماكس')
            ->assertJsonPath('homepage.categories.0.name', 'أحذية');
        $this->getJson('http://nike.sellchase.com/api/v1/storefront/context?template=category&slug=shoes&lang=ar')
            ->assertOk()->assertJsonPath('data.category.name', 'أحذية')->assertJsonPath('seo.json_ld.name', 'أحذية');
    }
}
