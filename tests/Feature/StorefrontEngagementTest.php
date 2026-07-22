<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6 — customer engagement (wishlist, reviews, change password). Focuses on
 * correctness plus store/customer isolation.
 */
class StorefrontEngagementTest extends TestCase
{
    use RefreshDatabase;

    private Store $alpha;

    private Store $beta;

    private Product $tee;      // alpha

    private Product $mug;      // beta

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->makeStore('alpha');
        $this->beta = $this->makeStore('beta');
        $this->tee = $this->makeProduct($this->alpha, 'tee', '20.00');
        $this->mug = $this->makeProduct($this->beta, 'mug', '9.00');
    }

    private function makeStore(string $slug): Store
    {
        $user = User::factory()->create();
        $store = Store::create([
            'owner_user_id' => $user->id, 'owner_type' => 'merchant',
            'name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'USD', 'status' => 'active',
        ]);
        StoreDomain::create(['store_id' => $store->id, 'host' => "{$slug}.sellchase.com", 'type' => 'subdomain', 'is_primary' => true]);

        return $store;
    }

    private function makeProduct(Store $store, string $slug, string $price): Product
    {
        return Product::create([
            'store_id' => $store->id, 'name' => ucfirst($slug), 'slug' => $slug, 'price' => $price, 'is_active' => true,
        ]);
    }

    private function host(Store $store): string
    {
        return "http://{$store->slug}.sellchase.com";
    }

    /** @return array{0:string} bearer token */
    private function register(Store $store, string $email, string $password = 'password123'): string
    {
        return $this->postJson($this->host($store).'/api/v1/storefront/auth/register', [
            'name' => 'C '.$email, 'email' => $email, 'password' => $password,
        ])->assertCreated()->json('token');
    }

    // ------------------------------------------------------------- wishlist

    public function test_wishlist_add_is_idempotent_and_removable(): void
    {
        $auth = ['Authorization' => 'Bearer '.$this->register($this->alpha, 'w@example.com')];

        $this->postJson($this->host($this->alpha).'/api/v1/storefront/wishlist', ['store_product_id' => $this->tee->id], $auth)->assertCreated();
        // adding again must not create a duplicate
        $this->postJson($this->host($this->alpha).'/api/v1/storefront/wishlist', ['store_product_id' => $this->tee->id], $auth)->assertCreated();

        $this->getJson($this->host($this->alpha).'/api/v1/storefront/wishlist', $auth)->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson($this->host($this->alpha)."/api/v1/storefront/wishlist/{$this->tee->id}", [], $auth)->assertOk();
        $this->getJson($this->host($this->alpha).'/api/v1/storefront/wishlist', $auth)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cannot_wishlist_a_foreign_store_product(): void
    {
        $auth = ['Authorization' => 'Bearer '.$this->register($this->alpha, 'w2@example.com')];
        $this->postJson($this->host($this->alpha).'/api/v1/storefront/wishlist', ['store_product_id' => $this->mug->id], $auth)
            ->assertStatus(422);
    }

    public function test_wishlist_requires_auth(): void
    {
        $this->getJson($this->host($this->alpha).'/api/v1/storefront/wishlist')->assertStatus(401);
    }

    // -------------------------------------------------------------- reviews

    public function test_reviews_create_aggregate_and_prevent_duplicates(): void
    {
        $a = ['Authorization' => 'Bearer '.$this->register($this->alpha, 'r1@example.com')];
        $b = ['Authorization' => 'Bearer '.$this->register($this->alpha, 'r2@example.com')];

        $this->postJson($this->host($this->alpha).'/api/v1/storefront/products/tee/reviews', ['rating' => 5, 'title' => 'Great'], $a)->assertCreated();
        // duplicate by same customer -> 422
        $this->postJson($this->host($this->alpha).'/api/v1/storefront/products/tee/reviews', ['rating' => 4], $a)->assertStatus(422);
        // second customer
        $this->postJson($this->host($this->alpha).'/api/v1/storefront/products/tee/reviews', ['rating' => 3], $b)->assertCreated();

        // public listing + average (5 and 3 => 4.0), no auth required
        $res = $this->getJson($this->host($this->alpha).'/api/v1/storefront/products/tee/reviews')
            ->assertOk()
            ->assertJsonPath('summary.count', 2);
        $this->assertEqualsWithDelta(4.0, (float) $res->json('summary.average'), 0.001);
    }

    public function test_customer_can_only_modify_their_own_review(): void
    {
        $a = ['Authorization' => 'Bearer '.$this->register($this->alpha, 'own@example.com')];
        $b = ['Authorization' => 'Bearer '.$this->register($this->alpha, 'other@example.com')];

        $reviewId = $this->postJson($this->host($this->alpha).'/api/v1/storefront/products/tee/reviews', ['rating' => 2], $a)
            ->assertCreated()->json('data.id');

        // other customer cannot touch it
        $this->putJson($this->host($this->alpha)."/api/v1/storefront/products/tee/reviews/{$reviewId}", ['rating' => 5], $b)->assertNotFound();
        $this->deleteJson($this->host($this->alpha)."/api/v1/storefront/products/tee/reviews/{$reviewId}", [], $b)->assertNotFound();

        // owner can update
        $this->putJson($this->host($this->alpha)."/api/v1/storefront/products/tee/reviews/{$reviewId}", ['rating' => 4], $a)
            ->assertOk()->assertJsonPath('data.rating', 4);
    }

    public function test_reviews_are_store_isolated(): void
    {
        // Store B has no product 'tee' -> public reviews for that slug 404 on Store B.
        $this->getJson($this->host($this->beta).'/api/v1/storefront/products/tee/reviews')->assertNotFound();
    }

    // ------------------------------------------------------- change password

    public function test_customer_can_change_password(): void
    {
        $token = $this->register($this->alpha, 'pw@example.com', 'password123');
        $auth = ['Authorization' => "Bearer {$token}"];

        // wrong current password -> 422
        $this->putJson($this->host($this->alpha).'/api/v1/storefront/account/password', [
            'current_password' => 'WRONG', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ], $auth)->assertStatus(422);

        // correct current -> 200
        $this->putJson($this->host($this->alpha).'/api/v1/storefront/account/password', [
            'current_password' => 'password123', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ], $auth)->assertOk();

        // new password works, old fails
        $this->postJson($this->host($this->alpha).'/api/v1/storefront/auth/login', ['email' => 'pw@example.com', 'password' => 'newpass123'])->assertOk();
        $this->postJson($this->host($this->alpha).'/api/v1/storefront/auth/login', ['email' => 'pw@example.com', 'password' => 'password123'])->assertStatus(422);
    }
}
