<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 6D — coupon system. Covers merchant CRUD + ownership, every rejection
 * rule, fixed/percentage discounts, checkout integration, usage recording, and
 * a no-coupon regression check.
 */
class StorefrontCouponTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;

    private Store $alpha;

    private Store $beta;

    private Product $tee; // alpha, 100.00

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);

        [$this->ownerA, $this->alpha] = $this->makeStore('alpha');
        [, $this->beta] = $this->makeStore('beta');
        $this->tee = Product::create([
            'user_id' => $this->alpha->owner_user_id, 'name' => 'Tee', 'slug' => 'tee', 'price' => '100.00', 'is_active' => true,
        ]);
    }

    /** @return array{0:User,1:Store} */
    private function makeStore(string $slug): array
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $store = Store::create([
            'owner_user_id' => $user->id, 'owner_type' => 'merchant',
            'name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'USD', 'status' => 'active',
        ]);
        StoreDomain::create(['store_id' => $store->id, 'host' => "{$slug}.sellchase.com", 'type' => 'subdomain', 'is_primary' => true]);

        return [$user, $store];
    }

    private function owner(User $user): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    private function host(Store $store): string
    {
        return "http://{$store->slug}.sellchase.com";
    }

    private function register(Store $store, string $email): string
    {
        return $this->postJson($this->host($store).'/api/v1/storefront/auth/register', [
            'name' => 'C', 'email' => $email, 'password' => 'password123',
        ])->assertCreated()->json('token');
    }

    private function coupon(Store $store, array $attrs): Coupon
    {
        return Coupon::create(array_merge([
            'store_id' => $store->id, 'code' => 'SAVE', 'type' => 'fixed', 'value' => '10.00', 'is_active' => true,
        ], $attrs));
    }

    private function addTee(Store $store, array $auth): void
    {
        $this->postJson($this->host($store).'/api/v1/storefront/cart/items', [
            'store_product_id' => $this->tee->id, 'quantity' => 1,
        ], $auth)->assertCreated();
    }

    private function checkout(Store $store, array $auth): TestResponse
    {
        return $this->postJson($this->host($store).'/api/v1/storefront/checkout', [
            'customer_name' => 'C', 'customer_email' => 'c@example.com',
        ], $auth);
    }

    // -------------------------------------------------------- merchant CRUD

    public function test_merchant_can_crud_coupons_and_only_owner_has_access(): void
    {
        $create = $this->owner($this->ownerA)->postJson("/api/v1/stores/{$this->alpha->id}/coupons", [
            'code' => 'save10', 'type' => 'percentage', 'value' => 10,
        ])->assertCreated()->assertJsonPath('data.code', 'SAVE10');

        $id = $create->json('data.id');
        $this->owner($this->ownerA)->getJson("/api/v1/stores/{$this->alpha->id}/coupons")->assertOk()->assertJsonPath('meta.total', 1);

        // duplicate code in the same store -> 422
        $this->owner($this->ownerA)->postJson("/api/v1/stores/{$this->alpha->id}/coupons", [
            'code' => 'save10', 'type' => 'fixed', 'value' => 5,
        ])->assertStatus(422);

        // a different owner cannot touch store A's coupons -> 403
        $this->owner($this->beta->owner)->getJson("/api/v1/stores/{$this->alpha->id}/coupons/{$id}")->assertStatus(403);

        $this->owner($this->ownerA)->deleteJson("/api/v1/stores/{$this->alpha->id}/coupons/{$id}")->assertOk();
    }

    // ---------------------------------------------------- discounts & checkout

    public function test_percentage_coupon_applies_and_records_usage_at_checkout(): void
    {
        $this->coupon($this->alpha, ['code' => 'PCT10', 'type' => 'percentage', 'value' => '10.00']);
        $auth = ['Authorization' => 'Bearer '.$this->register($this->alpha, 'p@example.com')];
        $this->addTee($this->alpha, $auth); // subtotal 100.00

        $this->postJson($this->host($this->alpha).'/api/v1/storefront/checkout/coupon/apply', ['code' => 'pct10'], $auth)
            ->assertOk()
            ->assertJsonPath('totals.discount_total', '10.00')
            ->assertJsonPath('totals.grand_total', '90.00');

        $order = $this->checkout($this->alpha, $auth)->assertCreated()
            ->assertJsonPath('data.discount_total', '10.00')
            ->assertJsonPath('data.grand_total', '90.00');

        $this->assertDatabaseHas('coupon_usages', [
            'store_order_id' => $order->json('data.id'),
            'discount_amount' => '10.00',
        ]);
    }

    public function test_fixed_coupon_applies(): void
    {
        $this->coupon($this->alpha, ['code' => 'FIX15', 'type' => 'fixed', 'value' => '15.00']);
        $auth = ['Authorization' => 'Bearer '.$this->register($this->alpha, 'f@example.com')];
        $this->addTee($this->alpha, $auth);

        $this->postJson($this->host($this->alpha).'/api/v1/storefront/checkout/coupon/apply', ['code' => 'FIX15'], $auth)
            ->assertOk()
            ->assertJsonPath('totals.discount_total', '15.00')
            ->assertJsonPath('totals.grand_total', '85.00');
    }

    // ----------------------------------------------------------- rejections

    public function test_expired_coupon_is_rejected(): void
    {
        $this->coupon($this->alpha, ['code' => 'OLD', 'expires_at' => now()->subDay()]);
        $this->assertApplyRejected('OLD');
    }

    public function test_not_started_coupon_is_rejected(): void
    {
        $this->coupon($this->alpha, ['code' => 'SOON', 'starts_at' => now()->addDay()]);
        $this->assertApplyRejected('SOON');
    }

    public function test_inactive_coupon_is_rejected(): void
    {
        $this->coupon($this->alpha, ['code' => 'OFF', 'is_active' => false]);
        $this->assertApplyRejected('OFF');
    }

    public function test_below_minimum_amount_is_rejected(): void
    {
        $this->coupon($this->alpha, ['code' => 'MIN', 'minimum_order_amount' => '200.00']);
        $this->assertApplyRejected('MIN'); // subtotal 100 < 200
    }

    public function test_wrong_store_coupon_is_rejected(): void
    {
        $this->coupon($this->beta, ['code' => 'BETAONLY']); // belongs to Store B
        $this->assertApplyRejected('BETAONLY'); // applied on Store A
    }

    public function test_global_usage_limit_is_enforced(): void
    {
        $this->coupon($this->alpha, ['code' => 'ONCE', 'type' => 'fixed', 'value' => '5.00', 'max_uses' => 1]);

        // First customer redeems it.
        $a = ['Authorization' => 'Bearer '.$this->register($this->alpha, 'a@example.com')];
        $this->addTee($this->alpha, $a);
        $this->postJson($this->host($this->alpha).'/api/v1/storefront/checkout/coupon/apply', ['code' => 'ONCE'], $a)->assertOk();
        $this->checkout($this->alpha, $a)->assertCreated();

        // Second customer now blocked.
        $b = ['Authorization' => 'Bearer '.$this->register($this->alpha, 'b@example.com')];
        $this->assertApplyRejected('ONCE', $b);
    }

    public function test_per_customer_usage_limit_is_enforced(): void
    {
        $this->coupon($this->alpha, ['code' => 'SOLO', 'type' => 'fixed', 'value' => '5.00', 'max_uses_per_customer' => 1]);

        $auth = ['Authorization' => 'Bearer '.$this->register($this->alpha, 'solo@example.com')];
        $this->addTee($this->alpha, $auth);
        $this->postJson($this->host($this->alpha).'/api/v1/storefront/checkout/coupon/apply', ['code' => 'SOLO'], $auth)->assertOk();
        $this->checkout($this->alpha, $auth)->assertCreated();

        // Same customer, second time -> blocked.
        $this->assertApplyRejected('SOLO', $auth);
    }

    // ------------------------------------------------------------ regression

    public function test_checkout_revalidation_prevents_double_redemption_of_a_single_use_coupon(): void
    {
        $this->coupon($this->alpha, ['code' => 'SINGLE', 'type' => 'fixed', 'value' => '5.00', 'max_uses' => 1]);

        $a = ['Authorization' => 'Bearer '.$this->register($this->alpha, 'ra@example.com')];
        $b = ['Authorization' => 'Bearer '.$this->register($this->alpha, 'rb@example.com')];

        // Both shoppers add to cart and APPLY the coupon while usage is still 0
        // (simulates two concurrent holders of the same last-use coupon).
        $this->addTee($this->alpha, $a);
        $this->addTee($this->alpha, $b);
        $this->postJson($this->host($this->alpha).'/api/v1/storefront/checkout/coupon/apply', ['code' => 'SINGLE'], $a)->assertOk();
        $this->postJson($this->host($this->alpha).'/api/v1/storefront/checkout/coupon/apply', ['code' => 'SINGLE'], $b)->assertOk();

        // A checks out first -> records the single allowed usage.
        $this->checkout($this->alpha, $a)->assertCreated();

        // B checks out -> the lockForUpdate re-validation at order time sees the
        // limit reached and rejects (fail-closed). No double redemption.
        $this->checkout($this->alpha, $b)->assertStatus(422);

        $this->assertDatabaseCount('coupon_usages', 1);
    }

    public function test_checkout_without_a_coupon_is_unchanged(): void
    {
        $auth = ['Authorization' => 'Bearer '.$this->register($this->alpha, 'plain@example.com')];
        $this->addTee($this->alpha, $auth);

        $this->checkout($this->alpha, $auth)->assertCreated()
            ->assertJsonPath('data.discount_total', '0.00')
            ->assertJsonPath('data.grand_total', '100.00');
    }

    private function assertApplyRejected(string $code, ?array $auth = null): void
    {
        $auth ??= ['Authorization' => 'Bearer '.$this->register($this->alpha, 'x'.uniqid().'@example.com')];
        $this->addTee($this->alpha, $auth); // ensure a non-empty cart exists
        $this->postJson($this->host($this->alpha).'/api/v1/storefront/checkout/coupon/apply', ['code' => $code], $auth)
            ->assertStatus(422);
    }
}
