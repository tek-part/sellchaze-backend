<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreBrand;
use App\Models\StoreCollection;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StoreCollectionsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $other;

    private array $auth;

    private array $otherAuth;

    private string $base = '/api/v1/my-store/catalog/collections';

    private string $public = 'http://collections-shop.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Storage::fake('public');
        [$this->store, $this->auth] = $this->merchantStore('collections-shop');
        [$this->other, $this->otherAuth] = $this->merchantStore('other-shop');
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'collections-shop.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
    }

    /** @return array{0: Store, 1: array<string, string>} */
    private function merchantStore(string $slug): array
    {
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => $slug, 'slug' => $slug, 'currency' => 'EGP', 'default_locale' => 'en', 'supported_locales' => ['en', 'ar'], 'status' => 'active']);

        return [$store, ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)]];
    }

    private function product(string $name, array $extra = [], ?Store $store = null): Product
    {
        return Product::create($extra + ['store_id' => ($store ?? $this->store)->id, 'name' => $name, 'slug' => Str::slug($name), 'price' => 10, 'is_active' => true]);
    }

    private function create(array $payload, ?array $auth = null): array
    {
        return $this->postJson($this->base, $payload + ['type' => 'manual'], $auth ?? $this->auth)->assertCreated()->json('data');
    }

    /** @return list<string> product names matching the rules via the editor preview */
    private function preview(array $rules, string $match = 'all', string $sort = 'title_asc'): array
    {
        return $this->postJson($this->base.'/preview', ['rules' => $rules, 'match' => $match, 'sort_order' => $sort], $this->auth)
            ->assertOk()->json('data.*.name');
    }

    public function test_storefront_bootstrap_says_whether_the_store_has_published_collections(): void
    {
        $this->getJson($this->public)->assertOk()->assertJsonPath('store.has_collections', false);

        // A draft collection cannot resolve on /collections/{slug}.
        $draft = $this->create(['title' => 'Summer', 'published' => false]);
        $this->getJson($this->public)->assertJsonPath('store.has_collections', false);
        $this->getJson($this->public.'/collections/'.$draft['handle'])->assertNotFound();

        $this->postJson($this->base.'/bulk-update', ['ids' => [$draft['id']], 'published' => true], $this->auth)->assertOk();
        $this->getJson($this->public)->assertJsonPath('store.has_collections', true);

        // Another store's collections do not count.
        StoreCollection::withoutGlobalScopes()->create(['store_id' => $this->other->id, 'name' => 'Theirs', 'slug' => 'theirs', 'type' => 'manual', 'is_active' => true]);
        $this->postJson($this->base.'/bulk-delete', ['ids' => [$draft['id']]], $this->auth)->assertOk();
        $this->getJson($this->public)->assertJsonPath('store.has_collections', false);
    }

    public function test_home_embeds_visible_manual_collection_products_in_merchant_order(): void
    {
        $a = $this->product('Alpha', ['translations' => ['name' => ['ar' => 'ألفا']]]);
        $b = $this->product('Bravo');
        $inactive = $this->product('Inactive', ['is_active' => false]);
        $hidden = $this->product('Hidden', ['catalog_hidden' => true]);
        $row = $this->create(['title' => 'Picks', 'published' => true,
            'product_ids' => [$b->id, $inactive->id, $hidden->id, $a->id]]);
        $this->create(['title' => 'Draft', 'published' => false, 'product_ids' => [$a->id]]);
        $foreign = $this->product('Foreign', [], $this->other);
        $this->create(['title' => 'Theirs', 'published' => true, 'product_ids' => [$foreign->id]], $this->otherAuth);

        $this->getJson($this->public.'/home?lang=ar')->assertOk()
            ->assertJsonCount(1, 'data.collections')
            ->assertJsonPath('data.collections.0.id', $row['id'])
            ->assertJsonPath('data.collections.0.products_count', 2)
            ->assertJsonCount(2, 'data.collections.0.products')
            ->assertJsonPath('data.collections.0.products.0.id', $b->id)
            ->assertJsonPath('data.collections.0.products.1.id', $a->id)
            ->assertJsonPath('data.collections.0.products.1.name', 'ألفا');
        // The collection index remains metadata-only.
        $this->getJson($this->public.'/collections')->assertOk()->assertJsonMissingPath('data.0.products');
    }

    public function test_home_embeds_bounded_automated_collection_matches_and_empty_rails(): void
    {
        $expected = [];
        for ($i = 0; $i < 26; $i++) {
            $expected[] = $this->product('Match '.$i, ['price' => 100 + $i])->id;
        }
        $this->product('No match', ['price' => 1]);
        $this->product('Hidden match', ['price' => 150, 'catalog_hidden' => true]);
        $this->product('Inactive match', ['price' => 150, 'is_active' => false]);
        $this->product('Foreign match', ['price' => 150], $this->other);
        $this->create(['title' => 'Auto', 'published' => true, 'type' => 'automated',
            'sort_order' => 'price_asc', 'rules' => [['field' => 'price', 'operator' => 'gt', 'value' => 50]]]);
        $this->create(['title' => 'Empty', 'published' => true]);

        $response = $this->getJson($this->public.'/home')->assertOk()
            ->assertJsonCount(2, 'data.collections')
            ->assertJsonPath('data.collections.0.products_count', 26)
            ->assertJsonCount(24, 'data.collections.0.products')
            ->assertJsonPath('data.collections.1.products', []);
        $this->assertSame(array_slice($expected, 0, 24), $response->json('data.collections.0.products.*.id'));
    }

    public function test_manual_collection_crud_sanitizes_description_and_keeps_product_order(): void
    {
        $a = $this->product('Alpha');
        $b = $this->product('Bravo');
        $row = $this->create([
            'title' => 'Summer picks', 'description' => '<h2>Hot</h2><script>alert(1)</script>', 'published' => true,
            'seo_title' => 'Summer SEO', 'product_ids' => [$b->id, $a->id],
            'translations' => ['name' => ['ar' => 'اختيارات الصيف']],
        ]);
        $this->assertSame('summer-picks', $row['handle']);
        $this->assertSame('manual', $row['type']);
        $this->assertSame([$b->id, $a->id], array_column($row['products'], 'id'));
        $this->assertStringContainsString('<h2>Hot</h2>', $row['description']);
        $this->assertStringNotContainsString('script', $row['description']);
        $this->assertSame('اختيارات الصيف', $row['translations']['name']['ar']);
        $this->assertSame('Summer picks', $row['translations']['name']['en']);

        $this->getJson($this->base.'/'.$row['id'], $this->auth)->assertOk()->assertJsonPath('data.products_count', 2)->assertJsonPath('data.seo_title', 'Summer SEO');
        $this->putJson($this->base.'/'.$row['id'], ['title' => 'Summer edit', 'published' => false, 'sort_order' => 'price_desc'], $this->auth)
            ->assertOk()->assertJsonPath('data.title', 'Summer edit')->assertJsonPath('data.published', false)
            ->assertJsonPath('data.sort_order', 'price_desc')->assertJsonPath('data.handle', 'summer-picks');

        $image = $this->post($this->base.'/'.$row['id'], ['_method' => 'PUT', 'image' => UploadedFile::fake()->image('c.png')], $this->auth + ['Accept' => 'application/json'])->assertOk()->json('data.image');
        Storage::disk('public')->assertExists($image);

        $this->deleteJson($this->base.'/'.$row['id'], [], $this->auth)->assertOk();
        $this->getJson($this->base.'/'.$row['id'], $this->auth)->assertNotFound();
        $this->assertDatabaseMissing('store_collection_product', ['store_collection_id' => $row['id']]);
        $this->assertDatabaseHas('products', ['id' => $a->id]);
    }

    public function test_handle_is_unique_per_store_and_generated_handles_are_suffixed(): void
    {
        $this->create(['title' => 'Summer', 'handle' => 'summer']);
        $this->postJson($this->base, ['title' => 'Again', 'handle' => 'summer', 'type' => 'manual'], $this->auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['handle'])
            ->assertJsonPath('errors.handle.0', 'Another collection in this store already uses this handle.');
        $this->assertSame('summer-2', $this->create(['title' => 'Summer'])['handle']);
        $this->postJson($this->base, ['title' => 'Bad', 'handle' => 'Not A Handle!', 'type' => 'manual'], $this->auth)->assertUnprocessable()->assertJsonValidationErrors(['handle']);
        // Another store may reuse the handle.
        $this->assertSame('summer', $this->create(['title' => 'Summer', 'handle' => 'summer'], $this->otherAuth)['handle']);
        // Keeping your own handle on update is fine.
        $own = $this->create(['title' => 'Winter', 'handle' => 'winter']);
        $this->putJson($this->base.'/'.$own['id'], ['handle' => 'winter'], $this->auth)->assertOk();
        $this->putJson($this->base.'/'.$own['id'], ['handle' => 'summer'], $this->auth)->assertUnprocessable()->assertJsonValidationErrors(['handle']);
    }

    public function test_stores_cannot_see_or_edit_each_others_collections_or_products(): void
    {
        $mine = $this->create(['title' => 'Mine']);
        $theirs = $this->create(['title' => 'Theirs'], $this->otherAuth);
        $foreignProduct = $this->product('Foreign', [], $this->other);

        $this->getJson($this->base, $this->auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine['id']);
        $this->getJson($this->base.'/'.$theirs['id'], $this->auth)->assertNotFound();
        $this->putJson($this->base.'/'.$theirs['id'], ['title' => 'Hijack'], $this->auth)->assertNotFound();
        $this->deleteJson($this->base.'/'.$theirs['id'], [], $this->auth)->assertNotFound();
        $this->postJson($this->base.'/'.$theirs['id'].'/products', ['product_ids' => []], $this->auth)->assertNotFound();
        $this->postJson($this->base.'/bulk-delete', ['ids' => [$theirs['id']]], $this->auth)->assertUnprocessable();
        $this->postJson($this->base.'/bulk-update', ['ids' => [$theirs['id']], 'published' => false], $this->auth)->assertUnprocessable();
        $this->postJson($this->base.'/'.$mine['id'].'/products', ['product_ids' => [$foreignProduct->id]], $this->auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['product_ids']);
        $this->assertDatabaseHas('store_collections', ['id' => $theirs['id'], 'name' => 'Theirs', 'is_active' => true]);
        // Rules cannot reference another store's category.
        $foreignCategory = Category::create(['store_id' => $this->other->id, 'name' => 'Foreign', 'slug' => 'foreign', 'is_active' => true]);
        $this->postJson($this->base.'/preview', ['rules' => [['field' => 'category', 'operator' => 'is', 'value' => $foreignCategory->id]]], $this->auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['rules.0.value']);
    }

    public function test_manual_products_are_attached_reordered_and_shown_on_the_storefront_in_order(): void
    {
        $a = $this->product('Alpha');
        $b = $this->product('Bravo');
        $c = $this->product('Charlie');
        $hidden = $this->product('Hidden', ['is_active' => false]);
        $row = $this->create(['title' => 'Picks', 'handle' => 'picks', 'published' => true]);

        $this->postJson($this->base.'/'.$row['id'].'/products', ['product_ids' => [$c->id, $a->id, $hidden->id, $b->id]], $this->auth)
            ->assertOk()->assertJsonPath('data.products_count', 4);
        $this->getJson($this->public.'/collections/picks')->assertOk()
            ->assertJsonPath('data.name', 'Picks')
            ->assertJsonPath('products.meta.total', 3)
            ->assertJsonPath('products.data.0.id', $c->id)->assertJsonPath('products.data.1.id', $a->id)->assertJsonPath('products.data.2.id', $b->id);

        $this->postJson($this->base.'/'.$row['id'].'/products', ['product_ids' => [$b->id, $c->id]], $this->auth)->assertOk();
        $this->getJson($this->public.'/collections/picks')->assertOk()->assertJsonPath('products.meta.total', 2)
            ->assertJsonPath('products.data.0.id', $b->id)->assertJsonPath('products.data.1.id', $c->id);
        // A merchant sort order overrides the manual order; shoppers can re-sort too.
        $c->update(['price' => 1]);
        $this->putJson($this->base.'/'.$row['id'], ['sort_order' => 'price_asc'], $this->auth)->assertOk();
        $this->getJson($this->public.'/collections/picks')->assertJsonPath('products.data.0.id', $c->id);
        $this->getJson($this->public.'/collections/picks?sort=price_desc')->assertJsonPath('products.data.0.id', $b->id);

        $auto = $this->create(['title' => 'Auto', 'type' => 'automated', 'rules' => [['field' => 'price', 'operator' => 'gt', 'value' => 1]]]);
        $this->postJson($this->base.'/'.$auto['id'].'/products', ['product_ids' => [$a->id]], $this->auth)->assertUnprocessable()->assertJsonValidationErrors(['product_ids']);
    }

    public function test_each_automated_rule_type_filters_products(): void
    {
        $shoes = Category::create(['store_id' => $this->store->id, 'name' => 'Shoes', 'slug' => 'shoes', 'is_active' => true]);
        $bags = Category::create(['store_id' => $this->store->id, 'name' => 'Bags', 'slug' => 'bags', 'is_active' => true]);
        $nike = StoreBrand::create(['store_id' => $this->store->id, 'name' => 'Nike', 'slug' => 'nike', 'is_active' => true]);
        $this->product('Red Runner', ['price' => 150, 'category_id' => $shoes->id, 'store_brand_id' => $nike->id, 'tags' => ['summer', 'sport'], 'track_inventory' => true, 'stock_quantity' => 5]);
        $this->product('Blue Tote', ['price' => 40, 'category_id' => $bags->id, 'tags' => ['winter'], 'track_inventory' => true, 'stock_quantity' => 0]);
        $old = $this->product('Old Red Clog', ['price' => 80, 'track_inventory' => false]);
        DB::table('products')->where('id', $old->id)->update(['created_at' => now()->subDays(60)]);

        $this->assertSame(['Old Red Clog', 'Red Runner'], $this->preview([['field' => 'title', 'operator' => 'contains', 'value' => 'red']]));
        $this->assertSame(['Blue Tote'], $this->preview([['field' => 'title', 'operator' => 'not_contains', 'value' => 'red']]));
        $this->assertSame(['Blue Tote'], $this->preview([['field' => 'title', 'operator' => 'starts_with', 'value' => 'Blue']]));
        $this->assertSame(['Blue Tote'], $this->preview([['field' => 'title', 'operator' => 'equals', 'value' => 'Blue Tote']]));
        $this->assertSame(['Old Red Clog', 'Red Runner'], $this->preview([['field' => 'price', 'operator' => 'gt', 'value' => 50]]));
        $this->assertSame(['Blue Tote'], $this->preview([['field' => 'price', 'operator' => 'lt', 'value' => 50]]));
        $this->assertSame(['Old Red Clog'], $this->preview([['field' => 'price', 'operator' => 'eq', 'value' => '80']]));
        $this->assertSame(['Red Runner'], $this->preview([['field' => 'category', 'operator' => 'is', 'value' => $shoes->id]]));
        $this->assertSame(['Blue Tote', 'Old Red Clog'], $this->preview([['field' => 'category', 'operator' => 'is_not', 'value' => $shoes->id]]));
        $this->assertSame(['Red Runner'], $this->preview([['field' => 'brand', 'operator' => 'is', 'value' => $nike->id]]));
        $this->assertSame(['Blue Tote', 'Old Red Clog'], $this->preview([['field' => 'brand', 'operator' => 'is_not', 'value' => $nike->id]]));
        $this->assertSame(['Red Runner'], $this->preview([['field' => 'tag', 'operator' => 'is', 'value' => 'summer']]));
        $this->assertSame(['Blue Tote', 'Old Red Clog'], $this->preview([['field' => 'tag', 'operator' => 'is_not', 'value' => 'summer']]));
        $this->assertSame(['Old Red Clog', 'Red Runner'], $this->preview([['field' => 'inventory', 'operator' => 'is', 'value' => 'in_stock']]));
        $this->assertSame(['Blue Tote'], $this->preview([['field' => 'inventory', 'operator' => 'is', 'value' => 'out_of_stock']]));
        $this->assertSame(['Blue Tote', 'Red Runner'], $this->preview([['field' => 'created_at', 'operator' => 'within_days', 'value' => 30]]));

        $options = $this->getJson($this->base.'/rule-options', $this->auth)->assertOk();
        $this->assertSame(['sport', 'summer', 'winter'], $options->json('data.tags'));
        $this->assertSame([$nike->id], array_column($options->json('data.brands'), 'id'));
        $this->assertCount(2, $options->json('data.categories'));
    }

    public function test_all_versus_any_matching_and_rule_validation(): void
    {
        $this->product('Red Runner', ['price' => 150]);
        $this->product('Red Cap', ['price' => 20]);
        $this->product('Blue Boot', ['price' => 200]);
        $rules = [['field' => 'title', 'operator' => 'contains', 'value' => 'Red'], ['field' => 'price', 'operator' => 'gt', 'value' => 100]];
        $this->assertSame(['Red Runner'], $this->preview($rules, 'all'));
        $this->assertSame(['Blue Boot', 'Red Cap', 'Red Runner'], $this->preview($rules, 'any'));

        $this->postJson($this->base, ['title' => 'Empty', 'type' => 'automated'], $this->auth)
            ->assertUnprocessable()->assertJsonPath('errors.rules.0', 'Add at least one condition to an automated collection.');
        $this->postJson($this->base, ['title' => 'Bad', 'type' => 'automated', 'rules' => [['field' => 'price', 'operator' => 'contains', 'value' => 'x']]], $this->auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['rules.0.operator']);
        $this->postJson($this->base, ['title' => 'Bad', 'type' => 'automated', 'rules' => [['field' => 'price', 'operator' => 'gt', 'value' => 'cheap']]], $this->auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['rules.0.value']);
        $this->postJson($this->base, ['title' => 'Bad', 'type' => 'automated', 'rules' => [['field' => 'colour', 'operator' => 'is', 'value' => 'red']]], $this->auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['rules.0.field']);
        $this->postJson($this->base, ['title' => 'Bad', 'type' => 'automated', 'match' => 'some', 'rules' => $rules], $this->auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['match']);

        $row = $this->create(['title' => 'Red or premium', 'handle' => 'red-or-premium', 'type' => 'automated', 'match' => 'any', 'rules' => $rules, 'sort_order' => 'price_desc', 'published' => true]);
        $this->assertSame('automated', $row['type']);
        $this->assertSame(3, $row['products_count']);
        $this->assertEquals(['field' => 'price', 'operator' => 'gt', 'value' => 100], $row['rules'][1]);
        $this->getJson($this->public.'/collections/red-or-premium')->assertOk()->assertJsonPath('products.meta.total', 3)
            ->assertJsonPath('products.data.0.name', 'Blue Boot')->assertJsonPath('products.data.2.name', 'Red Cap');
        // Switching to "all" narrows the storefront immediately; manual -> automated requires rules.
        $this->putJson($this->base.'/'.$row['id'], ['match' => 'all'], $this->auth)->assertOk()->assertJsonPath('data.products_count', 1);
        $this->getJson($this->public.'/collections/red-or-premium')->assertJsonPath('products.meta.total', 1);
        $manual = $this->create(['title' => 'Manual']);
        $this->putJson($this->base.'/'.$manual['id'], ['type' => 'automated'], $this->auth)->assertUnprocessable()->assertJsonValidationErrors(['rules']);
    }

    public function test_storefront_lists_only_published_collections_with_paginated_products(): void
    {
        foreach (range(1, 5) as $i) {
            $this->product("Item {$i}", ['price' => $i]);
        }
        $this->product('Inactive item', ['is_active' => false, 'price' => 9]);
        $this->product('Hidden item', ['catalog_hidden' => true, 'price' => 9]);
        $this->create(['title' => 'Cheap', 'handle' => 'cheap', 'type' => 'automated', 'rules' => [['field' => 'price', 'operator' => 'lt', 'value' => 100]], 'sort_order' => 'price_asc', 'published' => true, 'description' => '<p>All <strong>cheap</strong></p>']);
        $this->create(['title' => 'Draft', 'handle' => 'draft', 'published' => false]);

        $this->getJson($this->public.'/collections')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'cheap')->assertJsonPath('data.0.products_count', 5);
        $this->getJson($this->public.'/collections/draft')->assertNotFound();
        $page = $this->getJson($this->public.'/collections/cheap?per_page=2&page=2')->assertOk()
            ->assertJsonPath('data.description', '<p>All <strong>cheap</strong></p>')
            ->assertJsonPath('products.meta.total', 5)->assertJsonPath('products.meta.last_page', 3)->assertJsonPath('products.meta.current_page', 2);
        $this->assertSame(['Item 3', 'Item 4'], $page->json('products.data.*.name'));
        $this->getJson($this->public.'/collections/cheap?per_page=2&page=3')->assertJsonCount(1, 'products.data');
    }

    public function test_list_filters_and_bulk_publish_and_delete(): void
    {
        $manual = $this->create(['title' => 'Manual one', 'published' => true]);
        $auto = $this->create(['title' => 'Auto one', 'type' => 'automated', 'rules' => [['field' => 'price', 'operator' => 'gt', 'value' => 0]], 'published' => false]);
        $this->product('Thing');

        $this->getJson($this->base.'?type=automated', $this->auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $auto['id'])->assertJsonPath('data.0.products_count', 1);
        $this->getJson($this->base.'?published=yes', $this->auth)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $manual['id']);
        $this->getJson($this->base.'?search=auto', $this->auth)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $auto['id']);
        $this->getJson($this->base.'?sort_by=title&sort_direction=asc', $this->auth)->assertJsonPath('data.0.id', $auto['id']);
        $this->getJson($this->base.'?type=smart', $this->auth)->assertUnprocessable();

        $this->postJson($this->base.'/bulk-update', ['ids' => [$manual['id'], $auto['id']], 'published' => true], $this->auth)->assertOk()->assertJsonPath('meta.updated', 2);
        $this->getJson($this->base.'?published=no', $this->auth)->assertJsonCount(0, 'data');

        Role::findByName('Merchant', 'web')->revokePermissionTo('categories-delete');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->postJson($this->base.'/bulk-delete', ['ids' => [$manual['id']]], $this->auth)->assertForbidden();
        Role::findByName('Merchant', 'web')->givePermissionTo('categories-delete');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->postJson($this->base.'/bulk-delete', ['ids' => [$manual['id'], $auto['id']]], $this->auth)->assertOk()->assertJsonPath('meta.deleted', 2);
        $this->assertSame(0, StoreCollection::withoutGlobalScopes()->where('store_id', $this->store->id)->count());
    }

    public function test_legacy_automated_collections_without_rules_keep_their_pivot_products(): void
    {
        $product = $this->product('Seeded');
        $legacy = StoreCollection::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'name' => 'Best sellers', 'slug' => 'best-sellers', 'type' => 'best-sellers', 'is_active' => true, 'is_automated' => true]);
        DB::table('store_collection_product')->insert(['store_id' => $this->store->id, 'store_collection_id' => $legacy->id, 'store_product_id' => $product->id, 'position' => 0]);

        $this->getJson($this->public.'/collections')->assertOk()->assertJsonPath('data.0.products_count', 1)->assertJsonPath('data.0.type', 'best-sellers');
        $this->getJson($this->public.'/collections/best-sellers')->assertOk()->assertJsonPath('products.data.0.id', $product->id);
        $this->getJson($this->base.'/'.$legacy->id, $this->auth)->assertOk()->assertJsonPath('data.type', 'automated')
            ->assertJsonPath('data.uses_rules', false)->assertJsonPath('data.merchandising_type', 'best-sellers')->assertJsonPath('data.products_count', 1);
    }
}
