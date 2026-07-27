<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the unified per-owner catalog isolation (ProductScope + HasStoreTenancy).
 *
 * The catalog is store-less (store_id = NULL) and owned by user_id. A store
 * surfaces exactly its owner's catalog; two stores with different owners can
 * never resolve each other's rows.
 */
class StoreScopeIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->makeStore('nike');
        $this->b = $this->makeStore('adidas');

        $this->seedCatalog($this->a, 3);
        $this->seedCatalog($this->b, 5);
    }

    private function makeStore(string $slug): Store
    {
        return Store::create([
            'owner_user_id' => User::factory()->create()->id,
            'owner_type' => 'merchant',
            'name' => ucfirst($slug),
            'slug' => $slug,
            'currency' => 'USD',
            'status' => 'active',
        ]);
    }

    private function seedCatalog(Store $store, int $count): void
    {
        $ownerId = $store->owner_user_id;
        $cat = Category::create(['user_id' => $ownerId, 'name' => 'Shoes', 'slug' => "shoes-{$store->slug}", 'is_active' => true]);
        for ($i = 1; $i <= $count; $i++) {
            Product::create([
                'user_id' => $ownerId, 'category_id' => $cat->id,
                'name' => "{$store->slug} product {$i}", 'slug' => "{$store->slug}-p{$i}",
                'price' => 10 * $i, 'is_active' => true,
            ]);
        }
    }

    public function test_scope_limits_queries_to_the_current_stores_owner(): void
    {
        app(CurrentStore::class)->set($this->a);
        $this->assertSame(3, Product::query()->count());
        $this->assertSame(1, Category::query()->count());

        app(CurrentStore::class)->set($this->b);
        $this->assertSame(5, Product::query()->count());
    }

    public function test_store_a_cannot_fetch_store_b_product_by_id(): void
    {
        $bProduct = $this->b->products()->first();

        app(CurrentStore::class)->set($this->a);
        $this->assertNull(Product::query()->find($bProduct->id), 'Store A resolved a Store B product');
    }

    public function test_trait_autofills_user_id_from_current_stores_owner_on_create(): void
    {
        app(CurrentStore::class)->set($this->a);
        $product = Product::create(['name' => 'New', 'slug' => 'new', 'price' => 5, 'is_active' => true]);

        // Ownership follows the store's owner; the row stays store-less.
        $this->assertSame((int) $this->a->owner_user_id, (int) $product->user_id);
        $this->assertNull($product->store_id);
    }

    public function test_no_context_spans_the_store_less_b2b_catalog(): void
    {
        app(CurrentStore::class)->forget();

        // With no tenant the store-less catalog is visible (the B2B surface);
        // the controllers narrow it by user_id themselves.
        $this->assertSame(8, Product::query()->count());
        $this->assertSame(2, Category::query()->count());
    }

    public function test_store_relation_resolves_its_owner_catalog_without_context(): void
    {
        app(CurrentStore::class)->forget();
        $this->assertSame(3, $this->a->products()->count());
        $this->assertSame(5, $this->b->products()->count());
        $this->assertSame(1, $this->a->categories()->count());
    }
}
