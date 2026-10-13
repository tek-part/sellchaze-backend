<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Commerce\DigitalProducts;
use App\Services\Commerce\ManualBankPayment;
use App\Services\JwtTokenService;
use App\Services\PlatformWallet;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualStoreOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Store $store;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        $this->actor = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->actor->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $this->actor->id, 'owner_type' => 'merchant', 'name' => 'Manual review', 'slug' => 'manual-review', 'status' => 'active', 'currency' => 'USD']);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->actor->id, 'name' => 'Review shirt', 'slug' => 'review-shirt', 'sku' => 'SHIRT-REVIEW', 'price' => '25.00', 'is_active' => true, 'digital_type' => 'physical', 'track_inventory' => true, 'stock_quantity' => 10, 'reserved_quantity' => 0]);
    }

    private function endpoint(string $path = ''): string
    {
        return '/api/v1/stores/'.$this->store->id.'/orders/manual'.$path;
    }

    private function auth(?User $user = null): array
    {
        return ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($user ?? $this->actor)];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'customer_name' => 'Local review customer', 'customer_phone' => '+201000000001', 'customer_email' => 'manual@example.test', 'address' => 'Local review address', 'shipping_total' => '5.00', 'notes' => 'Local order note', 'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => '20.00']]], $overrides);
    }

    public function test_manual_override_preserves_catalog_and_reserves_inventory_with_single_replay_effects(): void
    {
        config(['platform_wallet.fees_enabled' => true]);
        app(PlatformWallet::class)->activateOrderFees();
        $payload = $this->payload(['grand_total' => '42.00']);
        $response = $this->postJson($this->endpoint(), $payload, $this->auth())->assertCreated()->assertJsonPath('data.grand_total', '42.00')->assertJsonPath('data.manual_adjustment_total', '-3.00')->assertJsonPath('data.entry_source', 'manual');
        $id = $response->json('data.id');
        $this->getJson($this->endpoint('/request/'.$payload['request_key']), $this->auth())->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($this->endpoint(), $payload, $this->auth())->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson($this->endpoint(), array_replace($payload, ['request_key' => strtoupper($payload['request_key'])]), $this->auth())->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertSame('25.00', $this->product->fresh()->price);
        $this->assertSame(2, $this->product->fresh()->reserved_quantity);
        $this->assertDatabaseCount('store_order_items', 1);
        $this->assertDatabaseCount('store_inventory_movements', 1);
        $this->assertDatabaseCount('store_order_status_changes', 1);
        $this->assertDatabaseHas('store_orders', ['id' => $id, 'notes' => null]);
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $id, 'notes' => 'Local order note', 'source' => 'merchant_manual']);
        $this->assertDatabaseCount('store_payment_transactions', 1);
        $this->assertSame(1, DB::table('platform_wallet_entries')->where('type', 'order_fee')->count());
        $this->assertSame(1, $this->actor->notifications()->where('type', 'App\\Notifications\\StoreOrderActivity')->count());
        $this->assertNull($response->json('data.manual_request_hash'));
        $this->postJson($this->endpoint(), array_replace($payload, ['customer_name' => 'Changed']), $this->auth())->assertStatus(409);
        $order = StoreOrder::findOrFail($id);
        $order->delete();
        $this->postJson($this->endpoint(), $payload, $this->auth())->assertStatus(409);
        $this->assertSame(1, StoreOrder::withTrashed()->count());
        Http::assertNothingSent();
    }

    public function test_unavailable_foreign_and_insufficient_batch_items_roll_back_every_side_effect(): void
    {
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-manual', 'status' => 'active']);
        $foreign = Product::create(['store_id' => $other->id, 'name' => 'Foreign item', 'slug' => 'foreign-item', 'price' => 5, 'is_active' => true]);
        $payload = $this->payload();
        $payload['items'][] = ['product_id' => $foreign->id, 'quantity' => 1];
        $this->postJson($this->endpoint(), $payload, $this->auth())->assertUnprocessable();
        $this->postJson($this->endpoint(), $this->payload(['items' => [['product_id' => $this->product->id, 'quantity' => 11]]]), $this->auth())->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('store_inventory_movements', 0);
        $this->assertDatabaseCount('store_payment_transactions', 0);
        $this->assertSame(0, $this->product->fresh()->reserved_quantity);
    }

    public function test_variants_are_required_and_stock_and_price_overrides_are_snapshotted(): void
    {
        $variant = ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $this->product->id, 'name' => 'Large', 'options' => ['Size' => 'L'], 'sku' => 'L-REVIEW', 'price_override' => 30, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 4, 'reserved_quantity' => 0]);
        $this->postJson($this->endpoint(), $this->payload(), $this->auth())->assertUnprocessable();
        $payload = $this->payload(['items' => [['product_id' => $this->product->id, 'variant_id' => $variant->id, 'quantity' => 3, 'unit_price' => '27.50']]]);
        $response = $this->postJson($this->endpoint(), $payload, $this->auth())->assertCreated()->assertJsonPath('data.subtotal', '82.50');
        $this->assertSame('30.00', $variant->fresh()->price_override);
        $this->assertSame(3, $variant->fresh()->reserved_quantity);
        $this->assertSame(0, $this->product->fresh()->reserved_quantity);
        $this->assertDatabaseHas('store_order_items', ['store_order_id' => $response->json('data.id'), 'variant_id' => $variant->id, 'variant_name' => 'Large', 'unit_price' => '27.50']);
        $this->getJson($this->endpoint('/products/'.$this->product->id.'/variants'), $this->auth())->assertOk()->assertJsonPath('data.0.id', $variant->id)->assertJsonPath('data.0.price', '30.00');
    }

    public function test_product_picker_is_paginated_scoped_and_available_to_authorized_order_staff(): void
    {
        $staff = User::factory()->create(['parent_user_id' => $this->actor->id, 'is_active' => true, 'pending_approval' => false]);
        $staff->assignRole('Employee');
        $staff->givePermissionTo('store.orders.manage');
        $this->getJson($this->endpoint('/products?sku=SHIRT-REVIEW'), $this->auth($staff))->assertOk()->assertJsonPath('data.0.id', $this->product->id)->assertJsonPath('meta.per_page', 25);
        $this->postJson($this->endpoint(), $this->payload(), $this->auth($staff))->assertCreated()->assertJsonPath('data.manual_actor_id', $staff->id);
        $this->getJson($this->endpoint('/products?sku=0'), $this->auth())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->endpoint('/products'), $this->auth(User::factory()->create(['is_active' => true, 'pending_approval' => false])))->assertForbidden();
        $ownerRequest = $this->payload();
        $this->postJson($this->endpoint(), $ownerRequest, $this->auth())->assertCreated();
        $this->getJson($this->endpoint('/request/'.$ownerRequest['request_key']), $this->auth($staff))->assertNotFound();
        $staff->revokePermissionTo('store.orders.manage');
        $this->postJson($this->endpoint(), $this->payload(), $this->auth($staff))->assertForbidden();
    }

    public function test_digital_codes_require_email_and_zero_shipping_and_keep_delivery_pending_until_paid(): void
    {
        $this->product->update(['digital_type' => 'codes']);
        app(DigitalProducts::class)->appendCodes($this->product, ['MANUAL-REVIEW-1', 'MANUAL-REVIEW-2']);
        $this->postJson($this->endpoint(), $this->payload(['shipping_total' => 0, 'customer_email' => null]), $this->auth())->assertUnprocessable();
        $this->postJson($this->endpoint(), $this->payload(), $this->auth())->assertUnprocessable();
        $payload = $this->payload(['shipping_total' => 0]);
        $response = $this->postJson($this->endpoint(), $payload, $this->auth())->assertCreated()->assertJsonPath('data.payment_method', 'bank_transfer')->assertJsonPath('data.fulfillment.type', 'digital');
        $order = StoreOrder::findOrFail($response->json('data.id'));
        $this->assertDatabaseCount('store_payment_transactions', 1);
        $this->assertSame(0, app(DigitalProducts::class)->available($this->product));
        $this->assertStringNotContainsString('MANUAL-REVIEW-1', $response->getContent());
        app(ManualBankPayment::class)->confirm($order, 'LOCAL-BANK-REVIEW', $this->actor->id);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('40.00', $order->fresh()->grand_total);
        Http::assertNothingSent();
    }

    public function test_required_personalization_money_limits_and_tax_remain_authoritative(): void
    {
        $this->product->update(['personalization_fields' => [['key' => 'text', 'type' => 'text', 'label' => 'Engraving', 'required' => true, 'max_length' => 20]]]);
        $this->postJson($this->endpoint(), $this->payload(), $this->auth())->assertUnprocessable();
        $payload = $this->payload();
        $payload['items'][0]['personalization'] = ['text' => 'Local engraving'];
        $this->store->update(['tax_enabled' => true, 'tax_rate' => '10.000', 'tax_prices_include' => false]);
        $this->postJson($this->endpoint(), $payload, $this->auth())->assertCreated()->assertJsonPath('data.tax_total', '4.00')->assertJsonPath('data.grand_total', '49.00');
        $tooLarge = $this->payload(['items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => '9999999999.99', 'personalization' => ['text' => 'Review']]]]);
        $this->postJson($this->endpoint(), $tooLarge, $this->auth())->assertUnprocessable();
        $this->postJson($this->endpoint(), $this->payload(['payment_method' => 'stripe']), $this->auth())->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 1);
    }

    public function test_default_price_changes_and_store_transfer_cannot_replay_into_new_context(): void
    {
        $payload = $this->payload(['items' => [['product_id' => $this->product->id, 'quantity' => 1, 'expected_price' => '25.00']]]);
        $this->product->update(['price' => '26.00']);
        $this->postJson($this->endpoint(), $payload, $this->auth())->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
        $payload['items'][0]['expected_price'] = '26.00';
        $response = $this->postJson($this->endpoint(), $payload, $this->auth())->assertCreated()->assertJsonPath('data.subtotal', '26.00');
        $newOwner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $newOwner->assignRole('Merchant');
        $this->store->update(['owner_user_id' => $newOwner->id]);
        $this->postJson($this->endpoint(), $payload, $this->auth())->assertForbidden();
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertSame($this->actor->id, StoreOrder::withoutGlobalScopes()->findOrFail($response->json('data.id'))->manual_owner_id);
    }

    public function test_private_personalization_upload_is_scoped_and_claimed_by_the_owned_order(): void
    {
        $this->product->update(['personalization_fields' => [['key' => 'image', 'type' => 'image', 'label' => 'Review image', 'required' => true, 'max_length' => 1000]]]);
        $file = UploadedFile::fake()->image('owned-review.png', 50, 50);
        $result = $this->post($this->endpoint('/products/'.$this->product->id.'/personalization-image'), ['field_key' => 'image', 'file' => $file], $this->auth())->assertCreated();
        $payload = $this->payload();
        $payload['items'][0]['personalization'] = ['image' => $result->json('data.token')];
        $response = $this->postJson($this->endpoint(), $payload, $this->auth())->assertCreated();
        $this->assertSame(1, DB::table('product_personalization_uploads')->whereNotNull('claimed_at')->count());
        $this->assertDatabaseHas('store_order_items', ['store_order_id' => $response->json('data.id')]);
        $this->post($this->endpoint('/products/'.$this->product->id.'/personalization-image'), ['field_key' => 'unknown', 'file' => $file], $this->auth())->assertUnprocessable();
        $this->assertDatabaseCount('product_personalization_uploads', 1);
    }

    public function test_fee_debt_hides_new_manual_order_data_and_replay_does_not_charge_again(): void
    {
        config(['platform_wallet.fees_enabled' => true]);
        app(PlatformWallet::class)->activateOrderFees();
        DB::table('platform_wallet_accounts')->insert(['user_id' => $this->actor->id, 'balance_milli' => -160, 'created_at' => now(), 'updated_at' => now()]);
        $payload = $this->payload();
        $first = $this->postJson($this->endpoint(), $payload, $this->auth())->assertCreated()->assertJsonPath('orders_locked', true);
        $this->assertArrayNotHasKey('customer', $first->json('data'));
        $this->postJson($this->endpoint(), $payload, $this->auth())->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(-200, DB::table('platform_wallet_accounts')->where('user_id', $this->actor->id)->value('balance_milli'));
        $this->assertDatabaseCount('store_orders', 1);
    }
}
