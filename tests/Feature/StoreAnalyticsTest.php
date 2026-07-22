<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 6F — merchant analytics: revenue, order/sales metrics, top products &
 * categories, customer/coupon/review analytics, authorization, and isolation.
 * Orders are seeded directly (bypassing the checkout flow) so each metric's
 * inputs are deterministic.
 */
class StoreAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;

    private Store $alpha;

    private Store $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        [$this->ownerA, $this->alpha] = $this->makeStore('alpha');
        [, $this->beta] = $this->makeStore('beta');
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

        return [$user, $store];
    }

    private function owner(User $user): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    private function url(string $suffix): string
    {
        return "/api/v1/stores/{$this->alpha->id}/analytics/{$suffix}";
    }

    private function customer(Store $store, string $email, ?Carbon $created = null): StoreCustomer
    {
        // created_at is not mass-assignable; set it explicitly when back-dating.
        $customer = StoreCustomer::create([
            'store_id' => $store->id, 'name' => 'C', 'email' => $email, 'password' => Hash::make('password123'),
        ]);
        if ($created !== null) {
            $customer->created_at = $created;
            $customer->save();
        }

        return $customer;
    }

    /** @param array<int,array{product:Product,price:string,qty:int}> $items */
    private function order(Store $store, string $status, string $grand, ?StoreCustomer $customer = null, array $items = [], ?Carbon $placedAt = null): StoreOrder
    {
        $order = StoreOrder::create([
            'store_id' => $store->id, 'store_customer_id' => $customer?->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)), 'status' => $status, 'currency' => 'USD',
            'customer_name' => 'C', 'customer_email' => 'c@example.com',
            'subtotal' => $grand, 'shipping_total' => '0.00', 'discount_total' => '0.00', 'grand_total' => $grand,
            'placed_at' => $placedAt ?? now(),
        ]);
        foreach ($items as $it) {
            $order->items()->create([
                'store_id' => $store->id, 'store_product_id' => $it['product']->id, 'name' => $it['product']->name,
                'unit_price' => $it['price'], 'quantity' => $it['qty'], 'line_total' => bcmul($it['price'], (string) $it['qty'], 2),
            ]);
        }

        return $order;
    }

    private function product(Store $store, string $slug, string $price, ?Category $cat = null): Product
    {
        return Product::create([
            'store_id' => $store->id, 'category_id' => $cat?->id,
            'name' => ucfirst($slug), 'slug' => $slug, 'price' => $price, 'is_active' => true,
        ]);
    }

    // ---------------------------------------------------- revenue + orders

    public function test_overview_revenue_and_order_metrics(): void
    {
        $p = $this->product($this->alpha, 'tee', '50.00');

        // delivered: counts toward revenue
        $this->order($this->alpha, 'delivered', '100.00', null, [['product' => $p, 'price' => '50.00', 'qty' => 2]]);
        $this->order($this->alpha, 'delivered', '50.00', null, [['product' => $p, 'price' => '50.00', 'qty' => 1]], now()->subDays(10));
        // non-delivered: excluded from revenue
        $this->order($this->alpha, 'pending', '999.00', null, [['product' => $p, 'price' => '50.00', 'qty' => 1]]);
        $this->order($this->alpha, 'cancelled', '999.00');
        // other store: never leaks
        $this->order($this->beta, 'delivered', '777.00');

        $r = $this->owner($this->ownerA)->getJson($this->url('overview'))->assertOk();

        $r->assertJsonPath('data.revenue.total_revenue', '150.00')  // 100 + 50
            ->assertJsonPath('data.revenue.today_revenue', '100.00') // only the first
            ->assertJsonPath('data.orders.delivered_orders', 2)
            ->assertJsonPath('data.orders.pending_orders', 1)
            ->assertJsonPath('data.orders.cancelled_orders', 1)
            ->assertJsonPath('data.sales.total_orders', 4)          // all of store A
            ->assertJsonPath('data.sales.total_items_sold', 3)      // delivered items 2 + 1
            ->assertJsonPath('data.sales.average_order_value', '75.00'); // 150 / 2
    }

    // ------------------------------------------------------ products/categories

    public function test_top_products_and_categories(): void
    {
        $shoes = Category::create(['store_id' => $this->alpha->id, 'name' => 'Shoes', 'slug' => 'shoes', 'is_active' => true]);
        $hats = Category::create(['store_id' => $this->alpha->id, 'name' => 'Hats', 'slug' => 'hats', 'is_active' => true]);
        $boot = $this->product($this->alpha, 'boot', '80.00', $shoes);
        $cap = $this->product($this->alpha, 'cap', '20.00', $hats);

        $this->order($this->alpha, 'delivered', '260.00', null, [
            ['product' => $boot, 'price' => '80.00', 'qty' => 3], // 240
            ['product' => $cap, 'price' => '20.00', 'qty' => 1],  // 20
        ]);

        $this->owner($this->ownerA)->getJson($this->url('products'))->assertOk()
            ->assertJsonPath('data.0.name', 'Boot')
            ->assertJsonPath('data.0.quantity_sold', 3)
            ->assertJsonPath('data.0.revenue_generated', '240.00');

        $this->owner($this->ownerA)->getJson($this->url('categories'))->assertOk()
            ->assertJsonPath('data.0.category', 'Shoes')
            ->assertJsonPath('data.0.quantity_sold', 3);
    }

    // -------------------------------------------------------------- customers

    public function test_customer_analytics_new_vs_returning(): void
    {
        $old = $this->customer($this->alpha, 'old@example.com', now()->subMonths(2));
        $new = $this->customer($this->alpha, 'new@example.com', now());

        // "old" is returning (2 delivered orders); "new" has 1
        $this->order($this->alpha, 'delivered', '10.00', $old);
        $this->order($this->alpha, 'delivered', '20.00', $old);
        $this->order($this->alpha, 'delivered', '30.00', $new);

        $this->owner($this->ownerA)->getJson($this->url('overview'))->assertOk()
            ->assertJsonPath('data.customers.total_customers', 2)
            ->assertJsonPath('data.customers.returning_customers', 1)
            ->assertJsonPath('data.customers.new_customers_this_month', 1);

        $this->owner($this->ownerA)->getJson($this->url('customers'))->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.returning_customers', 1);
    }

    // ---------------------------------------------------------------- coupons

    public function test_coupon_analytics(): void
    {
        $customer = $this->customer($this->alpha, 'cp@example.com');
        $order = $this->order($this->alpha, 'delivered', '90.00', $customer);
        $coupon = Coupon::create(['store_id' => $this->alpha->id, 'code' => 'PCT10', 'type' => 'percentage', 'value' => '10.00', 'is_active' => true]);
        CouponUsage::create([
            'store_id' => $this->alpha->id, 'coupon_id' => $coupon->id, 'store_customer_id' => $customer->id,
            'store_order_id' => $order->id, 'discount_amount' => '10.00', 'used_at' => now(),
        ]);

        $this->owner($this->ownerA)->getJson($this->url('overview'))->assertOk()
            ->assertJsonPath('data.coupons.coupon_redemptions', 1)
            ->assertJsonPath('data.coupons.total_coupon_usage', '10.00')
            ->assertJsonPath('data.coupons.top_coupons.0.code', 'PCT10')
            ->assertJsonPath('data.coupons.top_coupons.0.redemptions', 1);
    }

    // ---------------------------------------------------------------- reviews

    public function test_review_analytics(): void
    {
        $p = $this->product($this->alpha, 'tee', '50.00');
        foreach ([5, 3] as $i => $rating) {
            $c = $this->customer($this->alpha, "rev{$i}@example.com");
            ProductReview::create([
                'store_id' => $this->alpha->id, 'store_customer_id' => $c->id, 'store_product_id' => $p->id,
                'rating' => $rating, 'status' => 'approved',
            ]);
        }

        $res = $this->owner($this->ownerA)->getJson($this->url('overview'))->assertOk()
            ->assertJsonPath('data.reviews.total_reviews', 2)
            ->assertJsonPath('data.reviews.review_distribution.5', 1)
            ->assertJsonPath('data.reviews.review_distribution.3', 1);
        $this->assertEqualsWithDelta(4.0, (float) $res->json('data.reviews.average_rating'), 0.001); // (5 + 3) / 2
    }

    // ----------------------------------------------------------- authorization

    public function test_analytics_are_owner_only(): void
    {
        foreach (['overview', 'products', 'categories', 'customers'] as $endpoint) {
            $this->owner($this->beta->owner)->getJson($this->url($endpoint))->assertStatus(403);
        }
    }
}
