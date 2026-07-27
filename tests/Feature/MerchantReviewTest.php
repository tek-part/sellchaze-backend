<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 6H — merchant review moderation: list, approve/reject/hide, delete,
 * public-visibility effects, and ownership isolation.
 */
class MerchantReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;

    private Store $alpha;

    private Store $beta;

    private Product $tee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        [$this->ownerA, $this->alpha] = $this->makeStore('alpha');
        [, $this->beta] = $this->makeStore('beta');
        $this->tee = Product::create(['user_id' => $this->alpha->owner_user_id, 'name' => 'Tee', 'slug' => 'tee', 'price' => '50.00', 'is_active' => true]);
    }

    /** @return array{0:User,1:Store} */
    private function makeStore(string $slug): array
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $store = Store::create(['owner_user_id' => $user->id, 'owner_type' => 'merchant', 'name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'USD', 'status' => 'active']);

        return [$user, $store];
    }

    private function owner(User $user): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    private function review(string $status = 'approved'): ProductReview
    {
        $customer = StoreCustomer::create(['store_id' => $this->alpha->id, 'name' => 'C', 'email' => 'c'.uniqid().'@example.com', 'password' => Hash::make('password123')]);

        return ProductReview::create([
            'store_id' => $this->alpha->id, 'store_customer_id' => $customer->id, 'store_product_id' => $this->tee->id,
            'rating' => 5, 'title' => 'Great', 'body' => 'Nice', 'status' => $status,
        ]);
    }

    private function reviewsUrl(?int $id = null, string $suffix = ''): string
    {
        return "/api/v1/stores/{$this->alpha->id}/reviews".($id ? "/{$id}" : '').$suffix;
    }

    public function test_merchant_lists_and_moderates_reviews(): void
    {
        $r = $this->review('approved');

        $this->owner($this->ownerA)->getJson($this->reviewsUrl())->assertOk()->assertJsonPath('meta.total', 1);

        // hide it
        $this->owner($this->ownerA)->patchJson($this->reviewsUrl($r->id, '/status'), ['status' => 'hidden'])
            ->assertOk()->assertJsonPath('data.status', 'hidden');

        // hidden review disappears from the public storefront list
        $this->getJson("http://{$this->alpha->slug}.sellchase.com/api/v1/storefront/products/tee/reviews")
            ->assertOk()->assertJsonPath('summary.count', 0);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $r = $this->review('approved');
        $this->owner($this->ownerA)->patchJson($this->reviewsUrl($r->id, '/status'), ['status' => 'bogus'])->assertStatus(422);
    }

    public function test_merchant_can_delete_a_review(): void
    {
        $r = $this->review('approved');
        $this->owner($this->ownerA)->deleteJson($this->reviewsUrl($r->id))->assertOk();
        $this->assertDatabaseMissing('product_reviews', ['id' => $r->id]);
    }

    public function test_reviews_are_owner_only(): void
    {
        $r = $this->review('approved');
        $this->owner($this->beta->owner)->getJson($this->reviewsUrl())->assertStatus(403);
        $this->owner($this->beta->owner)->patchJson($this->reviewsUrl($r->id, '/status'), ['status' => 'hidden'])->assertStatus(403);
    }
}
