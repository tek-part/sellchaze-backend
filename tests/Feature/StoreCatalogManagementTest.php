<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner-management surface: authenticated CRUD, per-store slugs, and — critically —
 * that a store owner cannot read or write another store's catalog.
 */
class StoreCatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);

        [$this->ownerA, $this->storeA] = $this->makeMerchantWithStore('nike');
        [, $this->storeB] = $this->makeMerchantWithStore('adidas');
    }

    private function makeMerchantWithStore(string $slug): array
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $store = Store::create([
            'owner_user_id' => $user->id, 'owner_type' => 'merchant',
            'name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'USD', 'status' => 'active',
        ]);

        return [$user, $store];
    }

    private function actingAsOwnerA(): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->ownerA));
    }

    public function test_owner_can_create_product_with_generated_unique_slug(): void
    {
        $this->actingAsOwnerA()
            ->postJson("/api/v1/stores/{$this->storeA->id}/catalog/products", ['name' => 'Air Max', 'price' => 199])
            ->assertCreated()->assertJsonPath('data.slug', 'air-max');

        $this->actingAsOwnerA()
            ->postJson("/api/v1/stores/{$this->storeA->id}/catalog/products", ['name' => 'Air Max', 'price' => 199])
            ->assertCreated()->assertJsonPath('data.slug', 'air-max-2');
    }

    public function test_owner_cannot_access_another_stores_catalog(): void
    {
        $this->actingAsOwnerA()
            ->getJson("/api/v1/stores/{$this->storeB->id}/catalog/products")
            ->assertForbidden();
    }

    public function test_owner_cannot_fetch_a_product_belonging_to_another_store(): void
    {
        $bProduct = Product::create([
            'store_id' => $this->storeB->id, 'name' => 'Ultraboost', 'slug' => 'ultraboost', 'price' => 10, 'is_active' => true,
        ]);

        // Route is scoped to storeA; the storeB product id must 404 under storeA.
        $this->actingAsOwnerA()
            ->getJson("/api/v1/stores/{$this->storeA->id}/catalog/products/{$bProduct->id}")
            ->assertNotFound();
    }

    public function test_product_cannot_be_assigned_a_category_from_another_store(): void
    {
        $bCategory = Category::create(['store_id' => $this->storeB->id, 'name' => 'Shoes', 'slug' => 'shoes', 'is_active' => true]);

        $this->actingAsOwnerA()
            ->postJson("/api/v1/stores/{$this->storeA->id}/catalog/products", [
                'name' => 'Cross', 'price' => 5, 'category_id' => $bCategory->id,
            ])
            ->assertStatus(422)->assertJsonValidationErrors('category_id');
    }

    // ---- Phase 7 prep: SKU + pricing fields ----

    public function test_owner_can_create_product_with_sku_and_pricing_fields(): void
    {
        $this->actingAsOwnerA()
            ->postJson("/api/v1/stores/{$this->storeA->id}/catalog/products", [
                'name' => 'Tee', 'price' => 20, 'compare_price' => 25, 'sku' => 'TEE-1',
                'barcode' => '0001', 'short_description' => 'A soft tee',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sku', 'TEE-1')
            ->assertJsonPath('data.compare_price', '25.00')
            ->assertJsonPath('data.short_description', 'A soft tee');
    }

    public function test_product_sku_must_be_unique_within_a_store_but_free_across_stores(): void
    {
        $this->actingAsOwnerA()
            ->postJson("/api/v1/stores/{$this->storeA->id}/catalog/products", ['name' => 'A', 'sku' => 'DUP'])
            ->assertCreated();

        // Same SKU, same store -> rejected.
        $this->actingAsOwnerA()
            ->postJson("/api/v1/stores/{$this->storeA->id}/catalog/products", ['name' => 'B', 'sku' => 'DUP'])
            ->assertStatus(422)->assertJsonValidationErrors('sku');

        // Same SKU, different store -> allowed.
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->storeB->owner))
            ->postJson("/api/v1/stores/{$this->storeB->id}/catalog/products", ['name' => 'C', 'sku' => 'DUP'])
            ->assertCreated();
    }

    public function test_owner_can_crud_categories(): void
    {
        $create = $this->actingAsOwnerA()
            ->postJson("/api/v1/stores/{$this->storeA->id}/catalog/categories", ['name' => 'Shoes'])
            ->assertCreated()->assertJsonPath('data.slug', 'shoes');
        $id = $create->json('data.id');

        $this->actingAsOwnerA()->getJson("/api/v1/stores/{$this->storeA->id}/catalog/categories")
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->actingAsOwnerA()->putJson("/api/v1/stores/{$this->storeA->id}/catalog/categories/{$id}", ['name' => 'Footwear'])
            ->assertOk()->assertJsonPath('data.name', 'Footwear');

        $this->actingAsOwnerA()->deleteJson("/api/v1/stores/{$this->storeA->id}/catalog/categories/{$id}")->assertOk();
    }
}
