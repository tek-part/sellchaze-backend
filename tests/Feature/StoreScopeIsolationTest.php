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
 * Proves the StoreScope global scope + BelongsToStore trait isolate store-owned
 * models. Store A must never see Store B's products/categories.
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
        $cat = Category::create(['store_id' => $store->id, 'name' => 'Shoes', 'slug' => 'shoes', 'is_active' => true]);
        for ($i = 1; $i <= $count; $i++) {
            Product::create([
                'store_id' => $store->id, 'category_id' => $cat->id,
                'name' => "{$store->slug} product {$i}", 'slug' => "p{$i}",
                'price' => 10 * $i, 'is_active' => true,
            ]);
        }
    }

    public function test_scope_limits_queries_to_the_current_store(): void
    {
        app(CurrentStore::class)->set($this->a);
        $this->assertSame(3, Product::query()->count());
        $this->assertSame(1, Category::query()->count());

        app(CurrentStore::class)->set($this->b);
        $this->assertSame(5, Product::query()->count());
    }

    public function test_store_a_cannot_fetch_store_b_product_by_id(): void
    {
        $bProduct = Product::query()->forStore($this->b)->first();

        app(CurrentStore::class)->set($this->a);
        $this->assertNull(Product::query()->find($bProduct->id), 'Store A resolved a Store B product');
    }

    public function test_trait_autofills_store_id_from_current_store_on_create(): void
    {
        app(CurrentStore::class)->set($this->a);
        $product = Product::create(['name' => 'New', 'slug' => 'new', 'price' => 5, 'is_active' => true]);

        $this->assertSame($this->a->id, (int) $product->store_id);
    }

    public function test_explicit_for_store_scope_isolates_without_context(): void
    {
        app(CurrentStore::class)->forget();
        $this->assertSame(3, Product::query()->forStore($this->a)->count());
        $this->assertSame(5, Product::query()->forStore($this->b)->count());
    }

    public function test_fail_closed_returns_nothing_without_a_current_store(): void
    {
        app(CurrentStore::class)->forget();

        // No tenant -> no rows at all (fail-closed), despite 8 products existing.
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, Category::query()->count());

        // But the FK-scoped store relation still works without a context.
        $this->assertSame(3, $this->a->products()->count());
        $this->assertSame(5, $this->b->products()->count());
    }
}
