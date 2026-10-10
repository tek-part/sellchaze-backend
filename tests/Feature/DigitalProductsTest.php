<?php

namespace Tests\Feature;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Models\Product;
use App\Models\ProductDigitalCode;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\StoreOrderService;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DigitalProductsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private array $auth;

    private string $base = 'http://digital.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Queue::fake([BridgeStorefrontOrderJob::class]);
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Digital', 'slug' => 'digital', 'currency' => 'EGP', 'default_locale' => 'en', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'digital.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true, 'credentials' => []]);
    }

    private function product(string $type = 'codes', array $codes = ['FIRST-CODE', 'SECOND-CODE']): int
    {
        $input = ['name' => 'Digital guide', 'price' => 100, 'is_active' => true, 'digital_type' => $type];
        if ($type === 'codes') {
            $input['digital_codes'] = $codes;
        } elseif ($type === 'link') {
            $input['digital_url'] = 'https://example.test/private-guide';
        }

        return $this->postJson('/api/v1/my-store/catalog/products', $input, $this->auth)->assertCreated()->json('data.id');
    }

    private function order(int $product, int $quantity = 1): array
    {
        return ['customer_name' => 'Local buyer', 'customer_email' => 'buyer@example.test', 'items' => [['product_id' => $product, 'quantity' => $quantity]]];
    }

    public function test_catalog_keeps_digital_values_private_encrypted_and_type_immutable(): void
    {
        $id = $this->product('link');
        $product = Product::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame('https://example.test/private-guide', $product->digital_url);
        $this->assertStringNotContainsString('private-guide', DB::table('products')->where('id', $id)->value('digital_url'));
        $this->assertArrayNotHasKey('digital_url', $product->toArray());
        $this->getJson($this->base.'/products/'.$product->slug)->assertOk()->assertJsonPath('data.digital_type', 'link')->assertJsonMissing(['digital_url' => 'https://example.test/private-guide']);
        $this->putJson('/api/v1/my-store/catalog/products/'.$id, ['digital_type' => 'codes'], $this->auth)->assertUnprocessable();
        $this->putJson('/api/v1/my-store/catalog/products/'.$id, ['price' => 125], $this->auth)->assertOk()->assertJsonPath('data.digital_type', 'link')->assertJsonPath('data.digital_url', 'https://example.test/private-guide');
    }

    public function test_invalid_types_links_codes_and_duplicates_fail_atomically(): void
    {
        foreach ([['digital_type' => 'file'], ['digital_type' => 'link'], ['digital_type' => 'link', 'digital_url' => 'javascript:alert(1)'], ['digital_type' => 'physical', 'digital_codes' => ['CODE']], ['digital_type' => 'codes', 'digital_codes' => ['CODE', ' CODE ']], ['digital_type' => 'codes', 'digital_codes' => ["a\nb"]]] as $data) {
            $this->postJson('/api/v1/my-store/catalog/products', $data + ['name' => 'Guide', 'price' => 100], $this->auth)->assertUnprocessable();
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_digital_codes', 0);
        $id = $this->product();
        $this->putJson('/api/v1/my-store/catalog/products/'.$id, ['digital_codes' => ['THIRD', 'FIRST-CODE']], $this->auth)->assertUnprocessable();
        $this->assertDatabaseCount('product_digital_codes', 2);
        $this->putJson('/api/v1/my-store/catalog/products/'.$id, ['digital_codes' => ['THIRD']], $this->auth)->assertOk()->assertJsonPath('data.digital_codes_available', 3);
        $this->assertStringNotContainsString('FIRST-CODE', DB::table('product_digital_codes')->first()->value);
        $this->assertArrayNotHasKey('value', ProductDigitalCode::firstOrFail()->toArray());
    }

    public function test_quote_does_not_allocate_or_expose_codes_and_aggregate_variants_share_the_pool(): void
    {
        $id = $this->product();
        $this->postJson($this->base.'/checkout/quote', ['items' => [['product_id' => $id, 'quantity' => 2]]])->assertOk()->assertJsonMissing(['FIRST-CODE']);
        $this->assertSame(2, ProductDigitalCode::whereNull('store_order_item_id')->count());
        $variant = ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $id, 'name' => 'Edition', 'is_active' => true]);
        $other = ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $id, 'name' => 'Other edition', 'is_active' => true]);
        $slug = Product::withoutGlobalScopes()->findOrFail($id)->slug;
        $this->getJson($this->base.'/products/'.$slug)->assertOk()->assertJsonPath('data.stock', 2)->assertJsonPath('data.variants.0.stock', 2);
        $lines = [['product_id' => $id, 'variant_id' => $other->id, 'quantity' => 2], ['product_id' => $id, 'variant_id' => $variant->id, 'quantity' => 1]];
        $this->postJson($this->base.'/checkout/quote', ['items' => $lines])->assertUnprocessable();
        $this->postJson($this->base.'/checkout', ['items' => $lines] + $this->order($id))->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_codes_are_distinct_per_order_hidden_until_paid_and_retained_after_catalog_deletion(): void
    {
        $id = $this->product();
        $first = $this->postJson($this->base.'/checkout', $this->order($id))->assertCreated()->assertJsonPath('data.items.0.digital_delivery.status', 'awaiting_payment')->assertJsonPath('data.items.0.digital_delivery.values', []);
        $second = $this->postJson($this->base.'/checkout', $this->order($id))->assertCreated();
        $this->postJson($this->base.'/checkout', $this->order($id))->assertUnprocessable();
        $orders = StoreOrder::withoutGlobalScopes()->whereIn('id', [$first->json('data.id'), $second->json('data.id')])->get();
        foreach ($orders as $order) {
            $order->update(['payment_status' => 'paid']);
        }
        $this->deleteJson('/api/v1/my-store/catalog/products/'.$id, [], $this->auth)->assertOk();
        $one = $this->getJson('/api/v1/my-store/orders/'.$first->json('data.id'), $this->auth)->assertOk()->assertJsonPath('data.items.0.digital_delivery.status', 'ready')->json('data.items.0.digital_delivery.values');
        $two = $this->getJson('/api/v1/my-store/orders/'.$second->json('data.id'), $this->auth)->assertOk()->json('data.items.0.digital_delivery.values');
        $this->assertSame(['FIRST-CODE'], $one);
        $this->assertSame(['SECOND-CODE'], $two);
        $this->assertStringNotContainsString('FIRST-CODE', DB::table('store_order_items')->where('store_order_id', $first->json('data.id'))->value('digital_delivery'));
    }

    public function test_unpaid_cancellation_releases_codes_but_paid_cancellation_does_not(): void
    {
        $id = $this->product();
        $first = $this->postJson($this->base.'/checkout', $this->order($id, 2))->assertCreated();
        app(CurrentStore::class)->set($this->store);
        $order = StoreOrder::findOrFail($first->json('data.id'));
        app(StoreOrderService::class)->transition($order, 'cancelled');
        $this->assertSame(2, ProductDigitalCode::whereNull('store_order_item_id')->count());
        $second = $this->postJson($this->base.'/checkout', $this->order($id, 2))->assertCreated();
        app(CurrentStore::class)->set($this->store);
        $order = StoreOrder::findOrFail($second->json('data.id'));
        $order->update(['payment_status' => 'paid']);
        app(StoreOrderService::class)->transition($order, 'cancelled');
        $this->assertSame(0, ProductDigitalCode::whereNull('store_order_item_id')->count());
        $this->getJson('/api/v1/my-store/orders/'.$second->json('data.id'), $this->auth)->assertOk()->assertJsonPath('data.items.0.digital_delivery.status', 'cancelled')->assertJsonPath('data.items.0.digital_delivery.values', []);
    }

    public function test_link_delivery_snapshots_survive_link_edits_and_duplicate_reads(): void
    {
        $id = $this->product('link');
        $response = $this->postJson($this->base.'/checkout', $this->order($id, 2))->assertCreated();
        StoreOrder::withoutGlobalScopes()->findOrFail($response->json('data.id'))->update(['payment_status' => 'paid']);
        $this->putJson('/api/v1/my-store/catalog/products/'.$id, ['digital_url' => 'https://example.test/replacement'], $this->auth)->assertOk()->assertJsonPath('data.digital_sales', 2);
        foreach ([1, 2] as $read) {
            $this->getJson('/api/v1/my-store/orders/'.$response->json('data.id'), $this->auth)->assertOk()->assertJsonPath('data.items.0.digital_delivery.values', ['https://example.test/private-guide']);
        }
        $this->assertDatabaseCount('product_digital_codes', 0);
    }

    public function test_cached_checkout_replay_refreshes_delivery_after_payment_without_allocating_again(): void
    {
        $id = $this->product();
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $input = $this->order($id);
        $first = $this->postJson($this->base.'/checkout', $input, $headers)->assertCreated()->assertJsonPath('data.items.0.digital_delivery.values', []);
        StoreOrder::withoutGlobalScopes()->findOrFail($first->json('data.id'))->update(['payment_status' => 'paid']);
        $this->postJson($this->base.'/checkout', $input, $headers)->assertCreated()->assertJsonPath('data.items.0.digital_delivery.status', 'ready')->assertJsonPath('data.items.0.digital_delivery.values', ['FIRST-CODE']);
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertSame(1, ProductDigitalCode::whereNull('store_order_item_id')->count());
    }

    public function test_later_code_allocation_failure_rolls_back_previous_assignments_and_order(): void
    {
        $id = $this->product();
        app(CurrentStore::class)->set($this->store);
        $cart = app(CartService::class)->create($this->store, null);
        foreach ([1, 2] as $quantity) {
            $variant = ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $id, 'name' => 'Edition '.$quantity, 'is_active' => true]);
            $cart->items()->create(['store_product_id' => $id, 'variant_id' => $variant->id, 'name' => 'Guide', 'unit_price' => 100, 'quantity' => $quantity]);
        }
        $this->postJson($this->base.'/checkout', ['customer_name' => 'Buyer', 'customer_email' => 'buyer@example.test'], ['X-Cart-Token' => $cart->token])->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('store_order_items', 0);
        $this->assertSame(2, ProductDigitalCode::whereNull('store_order_item_id')->count());
        $this->assertDatabaseHas('carts', ['id' => $cart->id, 'status' => 'active']);
    }
}
