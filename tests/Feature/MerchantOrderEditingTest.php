<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderEdit;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use App\Models\User;
use App\Services\Commerce\DigitalProducts;
use App\Services\Commerce\ManualBankPayment;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantOrderEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_recalculates_the_original_payment_rule_and_never_uses_changed_gateway_pricing(): void
    {
        $rule = ['enabled' => true, 'type' => 'percentage', 'direction' => 'add', 'value' => '10.00'];
        $this->order->update(['payment_adjustment' => ['version' => 1, 'gateway' => 'cod', 'currency' => 'USD', 'rule' => $rule, 'base_total' => '47.00', 'amount' => '4.70'], 'grand_total' => '51.70']);
        StorePaymentTransaction::where('store_order_id', $this->order->id)->update(['amount' => '51.70']);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true, 'presentation' => ['price_adjustment' => array_replace($rule, ['value' => '50.00'])]]);
        $this->postJson($this->endpoint(), $this->body(['shipping_total' => '10.00']))->assertOk()->assertJsonPath('data.grand_total', '57.20')->assertJsonPath('data.payment_adjustment_total', '5.20');
        $this->assertSame('10.00', $this->order->fresh()->payment_adjustment['rule']['value']);
        $this->assertSame('57.20', StorePaymentTransaction::where('store_order_id', $this->order->id)->firstOrFail()->amount);
    }

    private User $owner;

    private Store $store;

    private Product $product;

    private StoreOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Notification::fake();
        Queue::fake();
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Edit fixture', 'slug' => 'edit-fixture', 'status' => 'active', 'currency' => 'USD', 'tax_enabled' => true, 'tax_rate' => '5.000', 'tax_prices_include' => false]);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Edit fixture product', 'slug' => 'edit-product', 'price' => '25.00', 'cost' => '9.00', 'is_active' => true, 'digital_type' => 'physical', 'track_inventory' => true, 'stock_quantity' => 10, 'reserved_quantity' => 0]);
        $id = $this->postJson('/api/v1/stores/'.$this->store->id.'/orders/manual', ['request_key' => Str::uuid(), 'customer_name' => 'Original fixture customer', 'customer_phone' => '+201000000009', 'customer_email' => 'original@example.test', 'address' => 'Original fixture address', 'shipping_total' => '5.00', 'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => '20.00']]])->assertCreated()->json('data.id');
        $this->order = StoreOrder::findOrFail($id);
    }

    private function endpoint(string $suffix = ''): string
    {
        return '/api/v1/stores/'.$this->store->id.'/orders/'.$this->order->id.'/edit'.$suffix;
    }

    private function body(array $changes = []): array
    {
        $state = $this->getJson($this->endpoint())->assertOk()->json();

        return array_replace(['request_key' => (string) Str::uuid(), 'expected_token' => $state['edit']['token'], 'customer_name' => $state['data']['customer']['name'],
            'customer_phone' => $state['data']['customer']['phone'], 'customer_email' => $state['data']['customer']['email'],
            'shipping_address' => ['line1' => $state['data']['shipping_address']['line1'] ?? null], 'shipping_total' => $state['data']['shipping_total'],
            'items' => array_map(fn ($item) => ['item_id' => $item['id'], 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price']], $state['data']['items'])], $changes);
    }

    public function test_edit_updates_reservation_and_payment_atomically_and_keeps_encrypted_history_and_replay(): void
    {
        $body = $this->body(['customer_name' => 'Edited fixture customer', 'shipping_total' => '10.00', 'note' => 'Private edit reason']);
        $body['items'][0]['quantity'] = 3;
        $body['items'][0]['unit_price'] = '19.00';
        $response = $this->postJson($this->endpoint(), $body)->assertOk()->assertJsonPath('data.subtotal', '57.00')->assertJsonPath('data.tax_total', '2.85')->assertJsonPath('data.grand_total', '69.85')->assertJsonPath('data.shipping_address.shipping_details.base_rate', '10.00');
        $this->assertSame(3, $this->product->fresh()->reserved_quantity);
        $this->assertSame('25.00', $this->product->fresh()->price);
        $this->assertSame('69.85', StorePaymentTransaction::firstOrFail()->amount);
        $edit = StoreOrderEdit::firstOrFail();
        $this->assertSame('Original fixture customer', $edit->before_snapshot['order']['customer_name']);
        $this->assertSame('Edited fixture customer', $edit->after_snapshot['order']['customer_name']);
        $this->assertStringNotContainsString('Original fixture customer', DB::table('store_order_edits')->value('before_snapshot'));
        $this->assertSame(1, $edit->after_snapshot['revision']);
        $this->postJson($this->endpoint(), array_replace($body, ['request_key' => strtoupper($body['request_key'])]))->assertOk()->assertJsonPath('data.grand_total', '69.85');
        $this->assertDatabaseCount('store_order_edits', 1);
        $this->assertDatabaseCount('store_payment_transactions', 1);
        $this->assertDatabaseCount('platform_wallet_entries', 0);
        $this->assertNull($response->json('data.before_snapshot'));
        $this->getJson('/api/v1/stores/'.$this->store->id.'/orders/'.$this->order->id.'/edits/request/'.$body['request_key'])->assertOk()->assertJsonPath('data.customer.name', 'Edited fixture customer');
        $this->postJson($this->endpoint(), array_replace($body, ['customer_name' => 'Changed retry']))->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_removal_keeps_old_item_ledger_and_excludes_it_from_current_cart_and_filters(): void
    {
        $oldItem = $this->order->items()->firstOrFail();
        $new = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Replacement fixture', 'slug' => 'replacement-fixture', 'price' => '15.00', 'is_active' => true, 'digital_type' => 'physical', 'track_inventory' => true, 'stock_quantity' => 4, 'reserved_quantity' => 0]);
        $body = $this->body(['items' => [['product_id' => $new->id, 'quantity' => 1, 'unit_price' => '15.00']]]);
        $this->postJson($this->endpoint(), $body)->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.name', 'Replacement fixture')->assertJsonPath('data.grand_total', '20.75');
        $this->assertDatabaseCount('store_order_items', 2);
        $this->assertNotNull(DB::table('store_order_items')->where('id', $oldItem->id)->value('superseded_at'));
        $this->assertSame(0, $this->product->fresh()->reserved_quantity);
        $this->assertSame(1, $new->fresh()->reserved_quantity);
        $this->getJson('/api/v1/stores/'.$this->store->id.'/orders?product_id='.$this->product->id)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/stores/'.$this->store->id.'/orders/'.$this->order->id)->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_stock_failure_foreign_line_and_duplicate_line_leave_all_prior_data_untouched(): void
    {
        $before = $this->order->fresh()->getAttributes();
        $body = $this->body();
        $body['items'][0]['quantity'] = 11;
        $this->postJson($this->endpoint(), $body)->assertUnprocessable();
        $body = $this->body();
        $body['items'][0]['item_id'] = 999999;
        $this->postJson($this->endpoint(), $body)->assertUnprocessable();
        $body = $this->body();
        $body['items'][] = $body['items'][0];
        $this->postJson($this->endpoint(), $body)->assertUnprocessable();
        $this->assertSame($before, $this->order->fresh()->getAttributes());
        $this->assertSame(2, $this->product->fresh()->reserved_quantity);
        $this->assertDatabaseCount('store_order_edits', 0);
        $this->assertDatabaseCount('store_inventory_movements', 1);
    }

    public function test_stale_order_or_store_pricing_is_rejected_and_a_fresh_second_edit_succeeds(): void
    {
        $body = $this->body();
        $this->order->update(['customer_name' => 'Changed separately']);
        $this->postJson($this->endpoint(), $body)->assertStatus(409);
        $body = $this->body(['customer_name' => 'First accepted correction']);
        $this->postJson($this->endpoint(), $body)->assertOk();
        $body = $this->body(['customer_name' => 'Second accepted correction']);
        $this->postJson($this->endpoint(), $body)->assertOk();
        $this->assertDatabaseCount('store_order_edits', 2);
        $body = $this->body();
        $this->store->update(['tax_rate' => '7.000', 'updated_at' => now()->addSecond()]);
        $this->postJson($this->endpoint(), $body)->assertStatus(409);
    }

    public function test_paid_contact_correction_does_not_reprice_and_paid_cart_changes_are_rejected(): void
    {
        $this->order->update(['payment_status' => 'paid']);
        StorePaymentTransaction::query()->update(['status' => 'paid']);
        $body = $this->body(['customer_name' => 'Paid contact correction']);
        $this->postJson($this->endpoint(), $body)->assertOk()->assertJsonPath('data.customer.name', 'Paid contact correction')->assertJsonPath('data.grand_total', '47.00');
        $body = $this->body();
        $body['items'][0]['unit_price'] = '21.00';
        $this->postJson($this->endpoint(), $body)->assertUnprocessable();
        $this->assertDatabaseCount('store_order_edits', 1);
        $this->assertSame('47.00', StorePaymentTransaction::firstOrFail()->amount);
    }

    public function test_digital_quantity_reallocation_is_atomic_and_removed_codes_never_reach_the_paid_receipt(): void
    {
        $digital = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Digital edit fixture', 'slug' => 'digital-edit', 'price' => '10.00', 'is_active' => true, 'digital_type' => 'codes']);
        app(DigitalProducts::class)->appendCodes($digital, ['EDIT-TEST-A', 'EDIT-TEST-B', 'EDIT-TEST-C', 'EDIT-TEST-D']);
        $id = $this->postJson('/api/v1/stores/'.$this->store->id.'/orders/manual', ['request_key' => Str::uuid(), 'customer_name' => 'Digital fixture', 'customer_phone' => '+201000000009', 'customer_email' => 'digital@example.test', 'shipping_total' => '0.00', 'items' => [['product_id' => $digital->id, 'quantity' => 2]]])->assertCreated()->json('data.id');
        $this->order = StoreOrder::findOrFail($id);
        $body = $this->body();
        $body['items'][0]['quantity'] = 5;
        $this->postJson($this->endpoint(), $body)->assertUnprocessable();
        $this->assertSame(2, DB::table('product_digital_codes')->where('product_id', $digital->id)->whereNotNull('store_order_item_id')->count());
        $body = $this->body();
        $body['items'][0]['quantity'] = 3;
        $this->postJson($this->endpoint(), $body)->assertOk()->assertJsonCount(0, 'data.items.0.digital_delivery.values');
        $this->assertSame(3, DB::table('product_digital_codes')->where('product_id', $digital->id)->whereNotNull('store_order_item_id')->count());
        $body = $this->body(['items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '25.00']], 'shipping_address' => ['line1' => 'Digital replacement address'], 'shipping_total' => '5.00']);
        $this->postJson($this->endpoint(), $body)->assertOk()->assertJsonPath('data.fulfillment.type', 'physical');
        $this->assertSame(0, DB::table('product_digital_codes')->where('product_id', $digital->id)->whereNotNull('store_order_item_id')->count());
        app(ManualBankPayment::class)->confirm($this->order->fresh(), 'EDIT-PAID-FIXTURE', $this->owner->id);
        $this->getJson($this->endpoint())->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.digital_delivery', null);
        $this->assertSame(0, app(DigitalProducts::class)->sales($digital));
        $this->assertDatabaseCount('store_order_edits', 2);
    }

    public function test_foreign_store_archived_order_and_inactive_actor_cannot_use_the_editor(): void
    {
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Foreign edit fixture', 'slug' => 'edit-foreign', 'status' => 'active']);
        $body = $this->body();
        $this->order->update(['store_id' => $other->id]);
        $this->getJson($this->endpoint())->assertNotFound();
        $this->postJson($this->endpoint(), $body)->assertNotFound();
        $this->order->update(['store_id' => $this->store->id]);
        $this->order->delete();
        $this->getJson($this->endpoint())->assertNotFound();
        $this->postJson($this->endpoint(), $body)->assertNotFound();
        $this->order->restore();
        $this->owner->update(['is_active' => false]);
        $this->getJson($this->endpoint())->assertForbidden();
        $this->postJson($this->endpoint(), $body)->assertForbidden();
        $this->assertDatabaseCount('store_order_edits', 0);
    }

    public function test_a_populated_edit_migration_refuses_to_discard_its_history(): void
    {
        $this->postJson($this->endpoint(), $this->body(['customer_name' => 'History fixture']))->assertOk();
        $migration = require database_path('migrations/2026_10_13_120000_add_merchant_order_edit_history.php');
        try {
            $migration->down();
            $this->fail('Populated edit history must be preserved.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Preserve', $error->getMessage());
        }
        $this->assertDatabaseCount('store_order_edits', 1);
        $this->assertDatabaseCount('store_order_items', 1);
    }

    public function test_a_failed_online_invoice_keeps_cart_amounts_locked_but_allows_contact_correction(): void
    {
        StorePaymentTransaction::create(['store_id' => $this->store->id, 'store_order_id' => $this->order->id, 'gateway' => 'paymob', 'status' => 'failed', 'amount' => '47.00', 'currency' => 'USD', 'idempotency_key' => Str::uuid(), 'provider_reference' => 'FIXTURE-LATE-INVOICE']);
        $this->getJson($this->endpoint())->assertOk()->assertJsonPath('edit.financial', false);
        $body = $this->body();
        $body['items'][0]['unit_price'] = '21.00';
        $this->postJson($this->endpoint(), $body)->assertUnprocessable();
        $this->postJson($this->endpoint(), $this->body(['customer_name' => 'Invoice contact fixture']))->assertOk()->assertJsonPath('data.grand_total', '47.00');
        $this->assertSame(2, StorePaymentTransaction::where('amount', '47.00')->count());
    }

    public function test_a_physical_order_can_switch_to_a_digital_replacement_and_back(): void
    {
        $this->order->update(['payment_method' => 'bank_transfer']);
        StorePaymentTransaction::query()->update(['gateway' => 'bank_transfer']);
        $digital = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Link cycle fixture', 'slug' => 'link-cycle', 'price' => '10.00', 'is_active' => true, 'digital_type' => 'link', 'digital_url' => 'https://files.example.test/fixture.pdf']);
        $body = $this->body(['items' => [['product_id' => $digital->id, 'quantity' => 1, 'unit_price' => '10.00']], 'shipping_total' => '0.00']);
        $this->postJson($this->endpoint(), $body)->assertOk()->assertJsonPath('data.fulfillment.type', 'digital');
        $body = $this->body(['items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '20.00']], 'shipping_total' => '5.00']);
        $this->postJson($this->endpoint(), $body)->assertOk()->assertJsonPath('data.fulfillment.type', 'physical');
        $this->assertSame(1, $this->product->fresh()->reserved_quantity);
    }
}
