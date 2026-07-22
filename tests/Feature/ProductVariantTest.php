<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 7 prep — product variant CRUD, SKU uniqueness, effective-price fallback,
 * and tenant/ownership isolation.
 */
class ProductVariantTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;

    private Store $storeA;

    private Store $storeB;

    private Product $productA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);

        [$this->ownerA, $this->storeA] = $this->makeStore('nike');
        [, $this->storeB] = $this->makeStore('adidas');
        $this->productA = Product::create(['store_id' => $this->storeA->id, 'name' => 'Tee', 'slug' => 'tee', 'price' => '20.00', 'is_active' => true]);
    }

    /** @return array{0:User,1:Store} */
    private function makeStore(string $slug): array
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $store = Store::create(['owner_user_id' => $user->id, 'owner_type' => 'merchant', 'name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'USD', 'status' => 'active']);

        return [$user, $store];
    }

    private function ownerA(): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->ownerA));
    }

    private function variantsUrl(?int $productId = null, ?int $variantId = null): string
    {
        $pid = $productId ?? $this->productA->id;
        $base = "/api/v1/stores/{$this->storeA->id}/catalog/products/{$pid}/variants";

        return $variantId ? "{$base}/{$variantId}" : $base;
    }

    public function test_owner_can_crud_variants(): void
    {
        $created = $this->ownerA()->postJson($this->variantsUrl(), [
            'name' => 'Red / L', 'sku' => 'TEE-RL', 'price_override' => 24.5, 'options' => ['color' => 'Red', 'size' => 'L'], 'weight' => 0.3,
        ])->assertCreated()
            ->assertJsonPath('data.sku', 'TEE-RL')
            ->assertJsonPath('data.effective_price', '24.50')
            ->assertJsonPath('data.options.color', 'Red');
        $vid = $created->json('data.id');

        $this->ownerA()->getJson($this->variantsUrl())->assertOk()->assertJsonCount(1, 'data');

        $this->ownerA()->putJson($this->variantsUrl(null, $vid), ['name' => 'Red / XL', 'price_override' => 26])
            ->assertOk()->assertJsonPath('data.name', 'Red / XL')->assertJsonPath('data.effective_price', '26.00');

        $this->ownerA()->deleteJson($this->variantsUrl(null, $vid))->assertOk();
        $this->assertDatabaseMissing('store_product_variants', ['id' => $vid]);
    }

    public function test_effective_price_falls_back_to_product_price(): void
    {
        $this->ownerA()->postJson($this->variantsUrl(), ['name' => 'Blue / M']) // no override
            ->assertCreated()->assertJsonPath('data.effective_price', '20.00'); // product price
    }

    public function test_variant_sku_must_be_unique_within_a_store(): void
    {
        $this->ownerA()->postJson($this->variantsUrl(), ['name' => 'V1', 'sku' => 'DUP'])->assertCreated();
        $this->ownerA()->postJson($this->variantsUrl(), ['name' => 'V2', 'sku' => 'DUP'])
            ->assertStatus(422)->assertJsonValidationErrors('sku');
    }

    public function test_cannot_add_a_variant_to_another_stores_product(): void
    {
        $productB = Product::create(['store_id' => $this->storeB->id, 'name' => 'Cap', 'slug' => 'cap', 'price' => '10.00', 'is_active' => true]);

        // storeB's product id under storeA's route -> product not found (StoreScope) -> 404.
        $this->ownerA()->postJson($this->variantsUrl($productB->id), ['name' => 'X'])->assertNotFound();
    }

    public function test_variant_of_another_stores_product_is_not_reachable(): void
    {
        $productB = Product::create(['store_id' => $this->storeB->id, 'name' => 'Cap', 'slug' => 'cap', 'price' => '10.00', 'is_active' => true]);
        $variantB = ProductVariant::create(['store_id' => $this->storeB->id, 'store_product_id' => $productB->id, 'name' => 'B', 'is_active' => true]);

        // Even under storeA's own product, storeB's variant id must 404.
        $this->ownerA()->putJson($this->variantsUrl($this->productA->id, $variantB->id), ['name' => 'hack'])->assertNotFound();
    }

    public function test_a_foreign_owner_cannot_touch_the_catalog(): void
    {
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->storeB->owner))
            ->getJson($this->variantsUrl())->assertForbidden(); // storeA route, not storeB's owner
    }
}
