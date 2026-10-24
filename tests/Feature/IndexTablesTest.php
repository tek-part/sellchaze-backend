<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\TableView;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IndexTablesTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($this->owner)];
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Index', 'slug' => 'index-tables', 'currency' => 'EGP', 'default_locale' => 'en', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'index-tables.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
    }

    private function product(string $name, array $attributes = []): Product
    {
        return Product::withoutGlobalScopes()->create(array_merge(['store_id' => $this->store->id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::random(5),
            'price' => 100, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 20, 'reserved_quantity' => 0], $attributes));
    }

    private function names(array $params): array
    {
        $response = $this->getJson('/api/v1/my-store/catalog/products?'.http_build_query($params + ['per_page' => 100]), $this->auth)->assertOk();

        return collect($response->json('data'))->pluck('name')->sort()->values()->all();
    }

    public function test_product_index_filters_by_stock_type_price_and_sorts_by_stock(): void
    {
        $this->product('Plenty', ['price' => 300, 'stock_quantity' => 50]);
        $this->product('Low', ['price' => 80, 'stock_quantity' => 4, 'reserved_quantity' => 1, 'reorder_level' => 0]);
        $this->product('Empty', ['stock_quantity' => 2, 'reserved_quantity' => 2]);
        $this->product('Untracked', ['track_inventory' => false, 'stock_quantity' => 0]);
        $this->product('Ebook', ['digital_type' => 'link', 'digital_url' => 'https://example.test/e', 'track_inventory' => false]);
        $variants = $this->product('Shirt', ['track_inventory' => false, 'stock_quantity' => 0]);
        ProductVariant::withoutGlobalScopes()->create(['store_product_id' => $variants->id, 'store_id' => $this->store->id, 'name' => 'S', 'sku' => 'SH-S-'.Str::random(4), 'options' => ['size' => 'S'], 'stock_quantity' => 0, 'reserved_quantity' => 0, 'track_inventory' => true, 'is_active' => true]);

        $this->assertSame(['Ebook', 'Low', 'Plenty', 'Untracked'], $this->names(['stock' => 'in_stock']));
        $this->assertSame(['Empty', 'Shirt'], $this->names(['stock' => 'out_of_stock']));
        $this->assertSame(['Low'], $this->names(['stock' => 'low']));
        $this->assertSame(['Ebook', 'Untracked'], $this->names(['stock' => 'untracked']));
        $this->assertSame(['Ebook'], $this->names(['type' => 'digital']));
        $this->assertNotContains('Ebook', $this->names(['type' => 'physical']));
        $this->assertSame(['Low', 'Plenty'], $this->names(['price_min' => 50, 'price_max' => 300, 'stock' => 'in_stock', 'type' => 'physical', 'search' => 'l']));

        $sorted = $this->getJson('/api/v1/my-store/catalog/products?sort_by=stock_quantity&sort_direction=desc&per_page=2', $this->auth)->assertOk();
        $this->assertSame('Plenty', $sorted->json('data.0.name'));
        $this->getJson('/api/v1/my-store/catalog/products?stock=everything', $this->auth)->assertUnprocessable();
    }

    public function test_saved_views_are_private_named_and_bounded(): void
    {
        $state = ['search' => 'shirt', 'sort' => ['by' => 'price', 'direction' => 'asc'], 'filters' => ['status' => 'active', 'price' => ['min' => '10', 'max' => null], 'tags' => ['a', 'b']], 'columns' => ['name', 'price']];
        $created = $this->postJson('/api/v1/table-views', ['scope' => 'store:1:products', 'name' => 'Cheap shirts', 'state' => $state], $this->auth)
            ->assertCreated()->assertJsonPath('data.position', 0)->json('data');
        $this->postJson('/api/v1/table-views', ['scope' => 'store:1:products', 'name' => 'cheap SHIRTS', 'state' => $state], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/table-views', ['scope' => 'store:1:products', 'name' => 'Deep', 'state' => ['filters' => ['x' => ['nested' => ['no']]]]], $this->auth)->assertUnprocessable();
        $this->postJson('/api/v1/table-views', ['scope' => 'Bad Scope!', 'name' => 'X', 'state' => ['filters' => []]], $this->auth)->assertUnprocessable();

        $this->getJson('/api/v1/table-views?scope=store:1:products', $this->auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.state.filters.price.min', '10');
        $this->patchJson('/api/v1/table-views/'.$created['id'], ['name' => 'Budget'], $this->auth)->assertOk()->assertJsonPath('data.name', 'Budget')->assertJsonPath('data.state.search', 'shirt');
        $this->patchJson('/api/v1/table-views/'.$created['id'], ['state' => ['filters' => ['status' => 'draft']]], $this->auth)->assertOk()->assertJsonPath('data.state.filters.status', 'draft');

        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        $otherAuth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($other)];
        $this->getJson('/api/v1/table-views?scope=store:1:products', $otherAuth)->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/table-views/'.$created['id'], ['name' => 'Mine'], $otherAuth)->assertNotFound();
        $this->deleteJson('/api/v1/table-views/'.$created['id'], [], $otherAuth)->assertNotFound();

        for ($i = 1; $i < 20; $i++) {
            TableView::query()->create(['user_id' => $this->owner->id, 'scope' => 'store:1:products', 'name' => 'V'.$i, 'state' => ['filters' => []], 'position' => $i]);
        }
        $this->postJson('/api/v1/table-views', ['scope' => 'store:1:products', 'name' => 'One too many', 'state' => ['filters' => []]], $this->auth)->assertUnprocessable();
        $this->deleteJson('/api/v1/table-views/'.$created['id'], [], $this->auth)->assertNoContent();
    }

    private function order(array $attributes): StoreOrder
    {
        return StoreOrder::withoutGlobalScopes()->create(array_merge(['store_id' => $this->store->id, 'order_number' => 'IX-'.Str::random(8), 'status' => 'delivered',
            'payment_status' => 'paid', 'payment_method' => 'cod', 'currency' => 'EGP', 'subtotal' => 100, 'grand_total' => 100], $attributes));
    }

    public function test_customer_directory_groups_guest_orders_by_phone_then_email(): void
    {
        $this->order(['customer_name' => 'Mona', 'customer_phone' => '+20 100 111 2222', 'customer_phone_normalized' => '201001112222', 'grand_total' => 300, 'placed_at' => now()->subDays(40), 'shipping_address' => ['city' => 'Giza']]);
        $this->order(['customer_name' => 'Mona A.', 'customer_phone' => '01001112222', 'customer_phone_normalized' => '201001112222', 'grand_total' => 200, 'placed_at' => now()->subDays(2), 'shipping_address' => ['city' => 'Cairo']]);
        $this->order(['customer_name' => 'Mona', 'customer_phone_normalized' => '201001112222', 'status' => 'cancelled', 'grand_total' => 999]);
        $this->order(['customer_name' => 'Omar', 'customer_email' => 'Omar@Example.test', 'grand_total' => 50, 'placed_at' => now()->subDays(100)]);
        $this->order(['customer_name' => 'Omar', 'customer_email' => 'omar@example.test', 'grand_total' => 70, 'placed_at' => now()->subDays(95)]);
        $this->order(['customer_name' => 'Nobody', 'grand_total' => 10, 'placed_at' => now()->subDay()]);

        $all = $this->getJson('/api/v1/my-store/customers/directory', $this->auth)->assertOk();
        $this->assertSame(3, $all->json('meta.total'));
        $mona = collect($all->json('data'))->firstWhere('key', '201001112222');
        $this->assertSame(2, $mona['orders_count']);
        $this->assertEquals(500, $mona['total_spent']);
        $this->assertSame('Cairo', $mona['city']);
        $this->assertSame('Nobody', $all->json('data.0.name'));

        $repeat = $this->getJson('/api/v1/my-store/customers/directory?orders_min=2&sort_by=total_spent&sort_direction=desc', $this->auth)->assertOk();
        $this->assertSame(['201001112222', 'omar@example.test'], array_column($repeat->json('data'), 'key'));
        $this->assertSame(1, $repeat->json('meta.last_page'));
        $inactive = $this->getJson('/api/v1/my-store/customers/directory?last_order_to='.now()->subDays(90)->toDateString(), $this->auth)->assertOk();
        $this->assertSame(['omar@example.test'], array_column($inactive->json('data'), 'key'));
        $this->assertSame(['201001112222'], array_column($this->getJson('/api/v1/my-store/customers/directory?search=mona&spent_min=400', $this->auth)->json('data'), 'key'));
        $this->getJson('/api/v1/my-store/customers/directory?sort_by=password', $this->auth)->assertUnprocessable();
    }

    public function test_coupon_status_type_and_sort_filters(): void
    {
        $make = fn (string $code, array $attributes = []) => Coupon::withoutGlobalScopes()->create(array_merge(['store_id' => $this->store->id, 'code' => $code, 'type' => 'fixed', 'value' => 10, 'is_active' => true], $attributes));
        $make('LIVE');
        $make('LATER', ['starts_at' => now()->addDays(3), 'type' => 'percentage']);
        $make('OLD', ['expires_at' => now()->subDay()]);
        $make('OFF', ['is_active' => false]);
        $codes = fn (string $query) => array_column($this->getJson('/api/v1/my-store/coupons?'.$query, $this->auth)->assertOk()->json('data'), 'code');

        $this->assertSame(['LIVE'], $codes('status=active'));
        $this->assertSame(['LATER'], $codes('status=scheduled'));
        $this->assertSame(['OLD'], $codes('status=expired'));
        $this->assertSame(['OFF'], $codes('status=disabled'));
        $this->assertSame(['LATER'], $codes('type=percentage'));
        $this->assertSame(['LATER', 'LIVE', 'OFF', 'OLD'], $codes('sort_by=code&sort_direction=asc'));
        $this->getJson('/api/v1/my-store/coupons?status=forever', $this->auth)->assertUnprocessable();
    }

    public function test_review_search_rating_filters_and_bulk_moderation_stay_in_the_store(): void
    {
        $product = $this->product('Silk scarf');
        $review = fn (int $rating, string $title, array $attributes = []) => ProductReview::withoutGlobalScopes()->create(array_merge(['store_id' => $this->store->id, 'store_product_id' => $product->id,
            'rating' => $rating, 'title' => $title, 'body' => 'Body', 'status' => 'pending', 'author_name' => 'Guest'], $attributes));
        $great = $review(5, 'Lovely fabric');
        $bad = $review(1, 'Torn on arrival');
        $review(3, 'Okay');
        $otherStore = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-index', 'currency' => 'EGP', 'status' => 'active']);
        $foreign = ProductReview::withoutGlobalScopes()->create(['store_id' => $otherStore->id, 'store_product_id' => $product->id, 'rating' => 5, 'title' => 'Foreign', 'body' => 'x', 'status' => 'pending']);

        $titles = fn (string $query) => array_column($this->getJson('/api/v1/my-store/reviews?'.$query, $this->auth)->assertOk()->json('data'), 'title');
        $this->assertSame(['Lovely fabric', 'Torn on arrival'], $titles('rating=1,5&sort_by=rating&sort_direction=desc'));
        $this->assertSame(['Torn on arrival'], $titles('search=torn'));
        $this->assertSame(['Lovely fabric', 'Okay', 'Torn on arrival'], $titles('search=silk&sort_by=rating&sort_direction=desc'));
        $this->getJson('/api/v1/my-store/reviews?rating=9', $this->auth)->assertUnprocessable();

        $this->postJson('/api/v1/my-store/reviews/bulk-status', ['ids' => [$great->id, $bad->id, $foreign->id], 'status' => 'approved'], $this->auth)
            ->assertOk()->assertJsonPath('meta.updated', 2);
        $this->assertSame('approved', $great->fresh()->status);
        $this->assertSame('pending', $foreign->fresh()->status);
    }
}
