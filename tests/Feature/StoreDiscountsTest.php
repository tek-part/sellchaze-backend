<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Commerce\DiscountEngine;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Discounts v2: automatic discounts, free shipping, buy X get Y, product/category scope and
 * minimum quantity — through the live quote, the cart and the placed order.
 */
class StoreDiscountsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private Store $other;

    private Product $tee;   // 100.00, category Tops

    private Product $cap;   // 20.00, no category

    private Product $sock;  // 10.00, category Tops

    private Category $tops;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        [$this->owner, $this->store] = $this->makeStore('alpha');
        [, $this->other] = $this->makeStore('beta');
        $this->tops = Category::create(['store_id' => $this->store->id, 'name' => 'Tops', 'slug' => 'tops']);
        $this->tee = $this->product('tee', '100.00', $this->tops->id);
        $this->cap = $this->product('cap', '20.00');
        $this->sock = $this->product('sock', '10.00', $this->tops->id);
        $this->store->update(['shipping_enabled' => true, 'shipping_flat_rate' => 30]);
    }

    /** @return array{0: User, 1: Store} */
    private function makeStore(string $slug): array
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $store = Store::create(['owner_user_id' => $user->id, 'owner_type' => 'merchant', 'name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'USD', 'status' => 'active']);
        StoreDomain::create(['store_id' => $store->id, 'host' => "{$slug}.sellchase.com", 'type' => 'subdomain', 'is_primary' => true]);

        return [$user, $store];
    }

    private function product(string $slug, string $price, ?int $category = null): Product
    {
        return Product::create(['store_id' => $this->store->id, 'user_id' => $this->store->owner_user_id, 'category_id' => $category, 'name' => ucfirst($slug), 'slug' => $slug, 'price' => $price, 'is_active' => true]);
    }

    private function discount(array $attrs): Coupon
    {
        return Coupon::create(array_merge(['store_id' => $this->store->id, 'method' => 'code', 'code' => 'SAVE', 'type' => 'fixed', 'value' => '10.00', 'is_active' => true], $attrs));
    }

    /** @param array<int, array{0: Product, 1: int}> $items */
    private function quote(array $items, ?string $code = null): TestResponse
    {
        return $this->postJson('http://alpha.sellchase.com/api/v1/storefront/checkout/quote', [
            'items' => array_map(fn (array $item) => ['product_id' => $item[0]->id, 'quantity' => $item[1]], $items),
            'coupon_code' => $code,
        ]);
    }

    private function admin(): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
    }

    public function test_automatic_percentage_discount_applies_without_a_code(): void
    {
        $this->discount(['method' => 'automatic', 'code' => null, 'title' => 'Autumn sale', 'type' => 'percentage', 'value' => '10.00']);

        $this->quote([[$this->tee, 1], [$this->cap, 1]])->assertOk()
            ->assertJsonPath('data.totals.discount_total', '12.00')
            ->assertJsonPath('data.discounts.0.title', 'Autumn sale')
            ->assertJsonPath('data.discounts.0.method', 'automatic')
            ->assertJsonPath('data.discounts.0.code', null);
    }

    public function test_the_best_automatic_discount_wins_and_an_entered_code_replaces_it(): void
    {
        $this->discount(['method' => 'automatic', 'code' => null, 'title' => 'Five off', 'type' => 'fixed', 'value' => '5.00']);
        $this->discount(['method' => 'automatic', 'code' => null, 'title' => 'Ten percent', 'type' => 'percentage', 'value' => '10.00']);
        $this->discount(['code' => 'TWO', 'type' => 'fixed', 'value' => '2.00']);

        $this->quote([[$this->tee, 1]])->assertOk()->assertJsonPath('data.totals.discount_total', '10.00')->assertJsonPath('data.discounts.0.title', 'Ten percent');
        $this->quote([[$this->tee, 1]], 'two')->assertOk()->assertJsonPath('data.totals.discount_total', '2.00')->assertJsonCount(1, 'data.discounts');
    }

    public function test_product_and_category_scopes_discount_only_matching_items(): void
    {
        $this->discount(['code' => 'CAPS', 'type' => 'percentage', 'value' => '50.00', 'applies_to' => 'products', 'target_ids' => [$this->cap->id]]);
        $this->discount(['code' => 'TOPS', 'type' => 'percentage', 'value' => '10.00', 'applies_to' => 'categories', 'target_ids' => [$this->tops->id]]);
        $this->discount(['code' => 'BIG', 'type' => 'fixed', 'value' => '500.00', 'applies_to' => 'products', 'target_ids' => [$this->cap->id]]);

        $this->quote([[$this->tee, 1], [$this->cap, 2]], 'caps')->assertOk()->assertJsonPath('data.totals.discount_total', '20.00');
        $this->quote([[$this->tee, 1], [$this->sock, 2], [$this->cap, 1]], 'tops')->assertOk()->assertJsonPath('data.totals.discount_total', '12.00');
        // A fixed amount never exceeds the eligible items' total.
        $this->quote([[$this->tee, 1], [$this->cap, 1]], 'big')->assertOk()->assertJsonPath('data.totals.discount_total', '20.00');
        // No eligible item: the code is refused with a clear message.
        $this->quote([[$this->tee, 1]], 'caps')->assertStatus(422)->assertJsonValidationErrors('coupon');
    }

    public function test_minimum_quantity_counts_only_eligible_items(): void
    {
        $this->discount(['code' => 'THREE', 'type' => 'percentage', 'value' => '10.00', 'applies_to' => 'categories', 'target_ids' => [$this->tops->id], 'minimum_quantity' => 3]);

        $this->quote([[$this->tee, 1], [$this->sock, 1], [$this->cap, 5]], 'three')->assertStatus(422)->assertJsonValidationErrors('coupon');
        $this->quote([[$this->tee, 1], [$this->sock, 2]], 'three')->assertOk()->assertJsonPath('data.totals.discount_total', '12.00');
    }

    public function test_free_shipping_combines_with_an_order_discount(): void
    {
        $this->discount(['method' => 'automatic', 'code' => null, 'title' => 'Free delivery', 'type' => 'free_shipping', 'value' => 0, 'minimum_order_amount' => '50.00']);
        $this->discount(['code' => 'TEN', 'type' => 'fixed', 'value' => '10.00']);

        $this->quote([[$this->cap, 1]])->assertOk()->assertJsonPath('data.totals.shipping_total', '30.00')->assertJsonCount(0, 'data.discounts');
        $this->quote([[$this->tee, 1]], 'ten')->assertOk()
            ->assertJsonPath('data.totals.shipping_total', '0.00')
            ->assertJsonPath('data.totals.discount_total', '10.00')
            ->assertJsonPath('data.totals.grand_total', '90.00')
            ->assertJsonCount(2, 'data.discounts');
    }

    public function test_buy_x_get_y_discounts_the_cheapest_eligible_items_once_per_set(): void
    {
        // Buy 2 tops, get the cheapest third top free; at most one set per order.
        $this->discount(['code' => 'B2G1', 'type' => 'buy_x_get_y', 'value' => 0, 'bxgy' => [
            'buy_quantity' => 2, 'buy_applies_to' => 'categories', 'buy_ids' => [$this->tops->id],
            'get_quantity' => 1, 'get_applies_to' => 'categories', 'get_ids' => [$this->tops->id], 'get_percent' => 100, 'max_uses_per_order' => 1,
        ]]);
        // Buy any tee, get a cap at half price, unlimited sets.
        $this->discount(['code' => 'CAPHALF', 'type' => 'buy_x_get_y', 'value' => 0, 'bxgy' => [
            'buy_quantity' => 1, 'buy_applies_to' => 'products', 'buy_ids' => [$this->tee->id],
            'get_quantity' => 1, 'get_applies_to' => 'products', 'get_ids' => [$this->cap->id], 'get_percent' => 50,
        ]]);

        $this->quote([[$this->tee, 2], [$this->sock, 2]], 'b2g1')->assertOk()->assertJsonPath('data.totals.discount_total', '10.00');
        $this->quote([[$this->tee, 2]], 'b2g1')->assertStatus(422)->assertJsonValidationErrors('coupon');
        $this->quote([[$this->tee, 2], [$this->cap, 3]], 'caphalf')->assertOk()->assertJsonPath('data.totals.discount_total', '20.00');
    }

    public function test_order_records_discount_details_and_usage_for_automatic_discounts(): void
    {
        $auto = $this->discount(['method' => 'automatic', 'code' => null, 'title' => 'Autumn sale', 'type' => 'percentage', 'value' => '10.00', 'max_uses' => 1]);
        $free = $this->discount(['method' => 'automatic', 'code' => null, 'title' => 'Free delivery', 'type' => 'free_shipping', 'value' => 0]);
        $host = 'http://alpha.sellchase.com/api/v1/storefront';
        $headers = ['X-Cart-Token' => $this->postJson("{$host}/cart/items", ['store_product_id' => $this->tee->id, 'quantity' => 1])->assertCreated()->json('data.token')];

        $order = $this->postJson("{$host}/checkout", ['customer_name' => 'C', 'customer_email' => 'c@example.com', 'customer_phone' => '+201000000001',
            'shipping_address' => ['line1' => '1 Street', 'city' => 'Cairo', 'country' => 'EG']], $headers);
        $order->assertCreated()->assertJsonPath('data.discount_total', '10.00')->assertJsonPath('data.shipping_total', '0.00');

        $stored = StoreOrder::withoutGlobalScopes()->findOrFail($order->json('data.id'));
        $this->assertSame(['Autumn sale', 'Free delivery'], array_column($stored->discount_details, 'title'));
        $this->assertDatabaseHas('coupon_usages', ['coupon_id' => $auto->id, 'store_order_id' => $stored->id, 'discount_amount' => '10.00']);
        $this->assertDatabaseHas('coupon_usages', ['coupon_id' => $free->id, 'store_order_id' => $stored->id]);

        // max_uses reached: the automatic discount stops applying quietly.
        $this->quote([[$this->tee, 1]])->assertOk()->assertJsonPath('data.totals.discount_total', '0.00');
    }

    public function test_cart_apply_and_remove_keep_automatic_discounts(): void
    {
        $this->discount(['method' => 'automatic', 'code' => null, 'title' => 'Five off', 'type' => 'fixed', 'value' => '5.00']);
        $this->discount(['code' => 'SHIP', 'type' => 'free_shipping', 'value' => 0]);
        $host = 'http://alpha.sellchase.com/api/v1/storefront';
        $token = $this->postJson("{$host}/auth/register", ['name' => 'C', 'email' => 'c@example.com', 'password' => 'password123'])->json('token');
        $auth = ['Authorization' => "Bearer {$token}"];
        $this->postJson("{$host}/cart/items", ['store_product_id' => $this->tee->id, 'quantity' => 1], $auth)->assertCreated();

        $this->postJson("{$host}/checkout/coupon/apply", ['code' => 'ship'], $auth)->assertOk()
            ->assertJsonPath('totals.discount_total', '5.00')
            ->assertJsonPath('totals.shipping_total', '0.00');
        $this->deleteJson("{$host}/checkout/coupon", [], $auth)->assertOk()
            ->assertJsonPath('totals.discount_total', '5.00')
            ->assertJsonPath('totals.shipping_total', '30.00');
    }

    public function test_automatic_discounts_are_not_redeemable_as_codes_or_listed_publicly(): void
    {
        $this->discount(['method' => 'automatic', 'code' => null, 'title' => 'Hidden', 'type' => 'fixed', 'value' => '5.00']);
        $this->discount(['code' => 'PUBLIC', 'type' => 'fixed', 'value' => '1.00']);

        $codes = collect($this->getJson('http://alpha.sellchase.com/api/v1/storefront/coupons')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame(['PUBLIC'], $codes);
    }

    public function test_merchant_creates_each_discount_kind_with_validation(): void
    {
        $base = "/api/v1/stores/{$this->store->id}/coupons";
        $this->admin()->postJson($base, ['method' => 'automatic', 'type' => 'percentage', 'value' => 10])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->admin()->postJson($base, ['method' => 'automatic', 'title' => 'Sale', 'type' => 'percentage', 'value' => 10, 'code' => 'IGNORED'])
            ->assertCreated()->assertJsonPath('data.method', 'automatic')->assertJsonPath('data.code', null);
        $this->admin()->postJson($base, ['code' => 'ship', 'type' => 'free_shipping'])->assertCreated()->assertJsonPath('data.value', '0.00');
        $this->admin()->postJson($base, ['code' => 'caps', 'type' => 'fixed', 'value' => 5, 'applies_to' => 'products'])->assertStatus(422)->assertJsonValidationErrors('target_ids');

        $foreign = Product::create(['store_id' => $this->other->id, 'user_id' => $this->other->owner_user_id, 'name' => 'Foreign', 'slug' => 'foreign', 'price' => '1.00', 'is_active' => true]);
        $this->admin()->postJson($base, ['code' => 'x1', 'type' => 'fixed', 'value' => 5, 'applies_to' => 'products', 'target_ids' => [$foreign->id]])
            ->assertStatus(422)->assertJsonValidationErrors('target_ids.0');

        $this->admin()->postJson($base, ['code' => 'bogo', 'type' => 'buy_x_get_y', 'bxgy' => ['buy_quantity' => 1, 'get_quantity' => 1, 'get_percent' => 100, 'get_applies_to' => 'products', 'get_ids' => [$this->cap->id]]])
            ->assertCreated()->assertJsonPath('data.bxgy.get_ids.0', $this->cap->id);
        $this->admin()->getJson("{$base}?method=automatic")->assertOk()->assertJsonPath('meta.total', 1);
        $this->admin()->getJson("{$base}?type=free_shipping")->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_buy_x_get_y_on_a_huge_cart_is_evaluated_quickly(): void
    {
        $this->discount(['method' => 'automatic', 'code' => null, 'title' => 'B2G1', 'type' => 'buy_x_get_y', 'value' => 0, 'bxgy' => [
            'buy_quantity' => 2, 'buy_applies_to' => 'all', 'get_quantity' => 1, 'get_applies_to' => 'all', 'get_percent' => 100,
        ]]);
        // 100 lines priced 1..100, 999 units each: 33,300 sets, the cheapest 33,300 units free.
        $lines = array_map(fn (int $i) => ['product_id' => 100000 + $i, 'quantity' => 999, 'unit_price' => "{$i}.00"], range(1, 100));

        app(CurrentStore::class)->set($this->store);
        $started = microtime(true);
        $result = app(DiscountEngine::class)->evaluate($this->store, $lines, null, null);
        $this->assertLessThan(1.0, microtime(true) - $started);
        app(CurrentStore::class)->forget();
        $this->assertSame(bcadd(bcmul('999', (string) array_sum(range(1, 33)), 2), bcmul('333', '34', 2), 2), $result['discount']);
    }

    public function test_buy_x_get_y_discounts_an_item_in_both_scopes_when_the_rest_can_pay(): void
    {
        // Buy 2 of anything, get an accessory at half price: the watch is the discounted item.
        $accessories = Category::create(['store_id' => $this->store->id, 'name' => 'Accessories', 'slug' => 'accessories']);
        $watch = $this->product('watch', '200.00', $accessories->id);
        $shirt = $this->product('shirt', '50.00');
        $this->discount(['code' => 'ACC', 'type' => 'buy_x_get_y', 'value' => 0, 'bxgy' => [
            'buy_quantity' => 2, 'buy_applies_to' => 'all', 'get_quantity' => 1, 'get_applies_to' => 'categories', 'get_ids' => [$accessories->id], 'get_percent' => 50,
        ]]);

        $this->quote([[$watch, 1], [$shirt, 2]], 'acc')->assertOk()->assertJsonPath('data.totals.discount_total', '100.00');
        // An item that only qualifies to pay is never given away.
        $this->discount(['code' => 'SHIRTS', 'type' => 'buy_x_get_y', 'value' => 0, 'bxgy' => [
            'buy_quantity' => 1, 'buy_applies_to' => 'products', 'buy_ids' => [$shirt->id], 'get_quantity' => 1, 'get_applies_to' => 'all', 'get_percent' => 100,
        ]]);
        $this->quote([[$shirt, 1], [$this->cap, 1]], 'shirts')->assertOk()->assertJsonPath('data.totals.discount_total', '20.00');
    }

    public function test_percentage_amounts_round_half_up_to_cents(): void
    {
        $odd = $this->product('odd', '33.33');
        $this->discount(['code' => 'FIFTEEN', 'type' => 'percentage', 'value' => '15.00']);
        $this->discount(['code' => 'HALF', 'type' => 'buy_x_get_y', 'value' => 0, 'bxgy' => [
            'buy_quantity' => 1, 'buy_applies_to' => 'all', 'get_quantity' => 1, 'get_applies_to' => 'all', 'get_percent' => 50,
        ]]);

        // 15% of 33.33 is 4.9995.
        $this->quote([[$odd, 1]], 'fifteen')->assertOk()->assertJsonPath('data.totals.discount_total', '5.00');
        // Half of three units is rounded on the line (49.995), not per unit (3 x 16.665).
        $this->quote([[$odd, 6]], 'half')->assertOk()->assertJsonPath('data.totals.discount_total', '50.00');
    }

    public function test_a_guest_who_used_up_an_automatic_discount_is_refused_instead_of_charged_more(): void
    {
        $this->discount(['method' => 'automatic', 'code' => null, 'title' => 'Once each', 'type' => 'percentage', 'value' => '10.00', 'max_uses_per_customer' => 1]);
        $host = 'http://alpha.sellchase.com/api/v1/storefront';
        $checkout = function () use ($host) {
            $headers = ['X-Cart-Token' => $this->postJson("{$host}/cart/items", ['store_product_id' => $this->tee->id, 'quantity' => 1])->assertCreated()->json('data.token')];

            return $this->postJson("{$host}/checkout", ['customer_name' => 'C', 'customer_email' => 'c@example.com', 'customer_phone' => '+201000000001',
                'shipping_address' => ['line1' => '1 Street', 'city' => 'Cairo', 'country' => 'EG']], $headers);
        };

        $checkout()->assertCreated()->assertJsonPath('data.discount_total', '10.00');
        // The quote cannot know the guest yet, so it still shows the discount ...
        $this->quote([[$this->tee, 1]])->assertOk()->assertJsonPath('data.totals.discount_total', '10.00');
        // ... and placing the order refuses rather than silently charging the full price.
        $checkout()->assertStatus(422)->assertJsonValidationErrors('coupon');
        $this->assertSame(1, StoreOrder::withoutGlobalScopes()->count());
    }

    public function test_free_shipping_is_not_used_up_when_delivery_is_already_free(): void
    {
        $this->store->update(['shipping_free_over' => '50.00']);
        $free = $this->discount(['method' => 'automatic', 'code' => null, 'title' => 'Free delivery', 'type' => 'free_shipping', 'value' => 0]);
        $host = 'http://alpha.sellchase.com/api/v1/storefront';

        $this->quote([[$this->tee, 1]])->assertOk()->assertJsonPath('data.totals.shipping_total', '0.00')->assertJsonCount(0, 'data.discounts');
        $headers = ['X-Cart-Token' => $this->postJson("{$host}/cart/items", ['store_product_id' => $this->tee->id, 'quantity' => 1])->assertCreated()->json('data.token')];
        $order = $this->postJson("{$host}/checkout", ['customer_name' => 'C', 'customer_email' => 'c@example.com', 'customer_phone' => '+201000000001',
            'shipping_address' => ['line1' => '1 Street', 'city' => 'Cairo', 'country' => 'EG']], $headers);
        $order->assertCreated()->assertJsonPath('data.shipping_total', '0.00');

        $this->assertNull(StoreOrder::withoutGlobalScopes()->findOrFail($order->json('data.id'))->discount_details);
        $this->assertDatabaseMissing('coupon_usages', ['coupon_id' => $free->id]);
        // Below the threshold the discount waives the delivery price and is used.
        $this->quote([[$this->cap, 1]])->assertOk()->assertJsonPath('data.totals.shipping_total', '0.00')->assertJsonPath('data.discounts.0.type', 'free_shipping');
    }

    public function test_updating_a_discount_without_a_scope_keeps_its_scope(): void
    {
        $base = "/api/v1/stores/{$this->store->id}/coupons";
        $id = $this->admin()->postJson($base, ['code' => 'caps', 'type' => 'fixed', 'value' => 5, 'applies_to' => 'products', 'target_ids' => [$this->cap->id]])
            ->assertCreated()->json('data.id');

        $this->admin()->putJson("{$base}/{$id}", ['code' => 'caps', 'type' => 'fixed', 'value' => 7])->assertOk();
        $coupon = Coupon::query()->withoutGlobalScopes()->findOrFail($id);
        $this->assertSame('products', $coupon->applies_to);
        $this->assertSame([$this->cap->id], array_map('intval', (array) $coupon->target_ids));
        $this->assertSame('7.00', (string) $coupon->value);

        // An explicitly empty scope still means the whole store.
        $this->admin()->putJson("{$base}/{$id}", ['code' => 'caps', 'type' => 'fixed', 'value' => 7, 'applies_to' => null])->assertOk();
        $this->assertSame('all', $coupon->fresh()->applies_to);
        $this->assertNull($coupon->fresh()->target_ids);
    }

    public function test_updating_an_automatic_discount_without_a_method_keeps_it_automatic(): void
    {
        $base = "/api/v1/stores/{$this->store->id}/coupons";
        $id = $this->admin()->postJson($base, ['method' => 'automatic', 'title' => 'Autumn', 'type' => 'percentage', 'value' => 10])
            ->assertCreated()->json('data.id');

        $this->admin()->putJson("{$base}/{$id}", ['title' => 'Autumn', 'type' => 'percentage', 'value' => 15])->assertOk();
        $coupon = Coupon::query()->withoutGlobalScopes()->findOrFail($id);
        $this->assertSame('automatic', $coupon->method);
        $this->assertNull($coupon->code);
        $this->assertSame('15.00', (string) $coupon->value);
    }
}
