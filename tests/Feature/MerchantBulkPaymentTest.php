<?php

namespace Tests\Feature;

use App\Models\OutboxMessage;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StorePaymentTransaction;
use App\Models\User;
use App\Services\Commerce\PaymentSettlement;
use App\Services\Commerce\StorefrontOrderBridge;
use App\Services\JwtTokenService;
use App\Services\PlatformWallet;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantBulkPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private Product $product;

    private array $auth;

    private string $base = '/api/v1/my-store/orders';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->auth = $this->headers($this->owner);
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Bulk review', 'slug' => 'bulk-payment-review', 'currency' => 'EGP', 'status' => 'active']);
        app(CurrentStore::class)->set($this->store);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Synthetic product', 'slug' => 'bulk-product', 'price' => 100, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 10, 'reserved_quantity' => 3]);
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($user)];
    }

    private function order(string $method = 'cod', array $overrides = []): StoreOrder
    {
        $row = StoreOrder::create(array_replace(['store_id' => $this->store->id, 'order_number' => 'SYNTHETIC-'.Str::uuid(), 'customer_name' => 'Synthetic collection',
            'customer_email' => 'review@example.test', 'status' => 'processing', 'currency' => 'EGP', 'subtotal' => 100, 'grand_total' => 100,
            'payment_method' => $method, 'payment_status' => 'pending', 'placed_at' => now()], $overrides));
        $row->items()->create(['store_id' => $row->store_id, 'store_product_id' => $this->product->id, 'name' => 'Synthetic product', 'unit_price' => 100, 'quantity' => 1, 'line_total' => 100]);
        StorePaymentTransaction::create(['store_id' => $row->store_id, 'store_order_id' => $row->id, 'gateway' => $method, 'idempotency_key' => Str::uuid(),
            'status' => $row->payment_status, 'amount' => 100, 'currency' => 'EGP', 'metadata' => ['private_provider_secret' => 'DO-NOT-EXPOSE', 'bank_transfer' => ['fields' => ['iban' => 'SYNTHETIC-ONLY']]]]);

        return $row;
    }

    private function transaction(StoreOrder $order): StorePaymentTransaction
    {
        return StorePaymentTransaction::where('store_order_id', $order->id)->latest('id')->firstOrFail();
    }

    private function proof(StoreOrder $order, string $id = ''): string
    {
        $id = $id ?: (string) Str::uuid();
        DB::table('store_payment_proofs')->insert(['id' => $id, 'store_id' => $order->store_id, 'store_order_id' => $order->id, 'path' => 'payment-proofs/synthetic-only.png',
            'mime' => 'image/png', 'size_bytes' => 10, 'reference' => 'SYNTHETIC-RECEIPT', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function payload(array $orders, string $target = 'paid'): array
    {
        $preview = $this->postJson($this->base.'/bulk-payment-review', ['target' => $target, 'ids' => array_map(fn ($row) => $row->id, $orders)], $this->auth)
            ->assertOk();
        $this->assertStringContainsString('private', $preview->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $preview->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('DO-NOT-EXPOSE', $preview->getContent());
        $this->assertStringNotContainsString('private_provider_secret', $preview->getContent());

        return ['target' => $target, 'confirmed' => true, 'request_id' => (string) Str::uuid(), 'orders' => array_map(fn ($row) => ['id' => $row['id'], 'version' => $row['version'],
            'reference' => 'SYNTHETIC-COLLECT-'.$row['id'], 'amount' => $row['amount'], 'currency' => $row['currency'], 'acknowledged' => true, 'note' => 'Synthetic review reason'], $preview->json('data'))];
    }

    public function test_paid_batch_reuses_audited_cash_bank_receipt_confirmation_and_preserves_fulfillment_stock_and_private_metadata(): void
    {
        $cash = $this->order();
        $bank = $this->order('bank_transfer');
        $transfer = $this->order('transfer_receipt');
        $receipt = $this->proof($transfer);
        $online = $this->order('stripe');
        $closed = $this->order('cod', ['status' => 'refunded']);
        $already = $this->order('bank_transfer', ['payment_status' => 'paid']);
        $bridge = app(StorefrontOrderBridge::class)->bridge($cash, $this->store);
        $this->assertNotNull($bridge);
        $cash->items()->create(['store_id' => $this->store->id, 'name' => 'Synthetic digital item', 'unit_price' => 0, 'quantity' => 1, 'line_total' => 0, 'digital_delivery' => ['type' => 'codes']]);
        $payload = $this->payload([$cash, $bank, $transfer, $online, $closed, $already]);
        $this->postJson($this->base.'/bulk-payment-status', $payload, $this->auth)->assertOk()->assertJsonPath('updated', 3)->assertJsonPath('skipped', 2)->assertJsonPath('unchanged', 1);
        foreach ([$cash, $bank, $transfer] as $row) {
            $this->assertSame('paid', $row->fresh()->payment_status);
            $this->assertSame('processing', $row->fresh()->status);
            $tx = $this->transaction($row);
            $this->assertSame('paid', $tx->status);
            $this->assertNotNull($tx->paid_at);
            $this->assertSame($this->owner->id, $tx->metadata['merchant_payment_review']['actor_id']);
            $this->assertSame('DO-NOT-EXPOSE', $tx->metadata['private_provider_secret']);
            $this->assertSame(1, $row->statusChanges()->count());
        }
        $this->assertSame('100.00', $bridge->fresh()->paid_amount);
        $this->assertSame('SYNTHETIC-COLLECT-'.$cash->id, $bridge->fresh()->payment_transaction_id);
        $this->assertSame('accepted', DB::table('store_payment_proofs')->where('id', $receipt)->value('status'));
        $this->assertSame(10, $this->product->fresh()->stock_quantity);
        $this->assertSame(3, $this->product->fresh()->reserved_quantity);
        $this->assertSame(1, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
        $this->postJson($this->base.'/bulk-payment-status', $payload, $this->auth)->assertOk()->assertJsonPath('updated', 0)->assertJsonPath('unchanged', 4)->assertJsonPath('skipped', 2);
        $this->assertSame(1, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
        app(PaymentSettlement::class)->transition($this->transaction($cash), 'failed');
        $this->assertSame('paid', $cash->fresh()->payment_status);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_failed_and_pending_are_audited_offline_states_and_failed_collection_can_later_be_confirmed(): void
    {
        $row = $this->order();
        $payload = $this->payload([$row], 'failed');
        $this->postJson($this->base.'/bulk-payment-status', $payload, $this->auth)->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame('failed', $row->fresh()->payment_status);
        $this->assertNotNull($this->transaction($row)->failed_at);
        $this->postJson($this->base.'/bulk-payment-status', $payload, $this->auth)->assertOk()->assertJsonPath('unchanged', 1);
        $this->postJson($this->base.'/bulk-payment-status', $this->payload([$row], 'pending'), $this->auth)->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame('pending', $row->fresh()->payment_status);
        $this->assertNull($this->transaction($row)->failed_at);
        $this->postJson($this->base.'/bulk-payment-status', $this->payload([$row], 'failed'), $this->auth)->assertOk();
        $this->postJson($this->base.'/bulk-payment-status', $this->payload([$row]), $this->auth)->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame('paid', $row->fresh()->payment_status);
        $this->assertSame(['merchant_payment_failed', 'merchant_payment_pending', 'merchant_payment_failed', 'cash_payment'], $row->statusChanges()->pluck('source')->all());
        $this->assertSame('processing', $row->fresh()->status);
    }

    public function test_stale_amount_or_new_receipt_rejects_the_entire_batch_before_confirmation(): void
    {
        $one = $this->order();
        $two = $this->order('transfer_receipt');
        $this->proof($two);
        $payload = $this->payload([$one, $two]);
        $two->update(['grand_total' => 101]);
        $this->postJson($this->base.'/bulk-payment-status', $payload, $this->auth)->assertStatus(409);
        $this->assertSame('pending', $one->fresh()->payment_status);
        $this->assertDatabaseCount('store_order_status_changes', 0);
        $two->update(['grand_total' => 100]);
        $payload = $this->payload([$one, $two]);
        $this->proof($two);
        $this->postJson($this->base.'/bulk-payment-status', $payload, $this->auth)->assertStatus(409);
        $this->assertSame('pending', $one->fresh()->payment_status);
        $this->assertDatabaseCount('store_order_status_changes', 0);
    }

    public function test_amount_currency_acknowledgement_reference_and_reason_are_required_before_any_write(): void
    {
        $one = $this->order();
        $two = $this->order();
        $payload = $this->payload([$one, $two]);
        foreach ([['amount' => '99.00'], ['currency' => 'USD'], ['acknowledged' => false], ['reference' => '   ']] as $override) {
            $invalid = $payload;
            $invalid['orders'][1] = array_replace($invalid['orders'][1], $override);
            $this->postJson($this->base.'/bulk-payment-status', $invalid, $this->auth)->assertUnprocessable();
            $this->assertSame('pending', $one->fresh()->payment_status);
        }
        $payload = $this->payload([$one], 'failed');
        $payload['orders'][0]['note'] = '';
        $this->postJson($this->base.'/bulk-payment-status', $payload, $this->auth)->assertUnprocessable();
        $this->assertDatabaseCount('store_order_status_changes', 0);
    }

    public function test_request_replay_cannot_change_reference_actor_or_selection(): void
    {
        $row = $this->order();
        $extra = $this->order();
        $payload = $this->payload([$row]);
        $this->postJson($this->base.'/bulk-payment-status', $payload, $this->auth)->assertOk();
        $changed = $payload;
        $changed['orders'][0]['reference'] = 'DIFFERENT';
        $this->postJson($this->base.'/bulk-payment-status', $changed, $this->auth)->assertStatus(409);
        $changed = $payload;
        $changed['orders'][] = $this->payload([$extra])['orders'][0];
        $this->postJson($this->base.'/bulk-payment-status', $changed, $this->auth)->assertStatus(409);
        $employee = User::factory()->create(['parent_user_id' => $this->owner->id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $employee->givePermissionTo('store.orders.manage');
        $this->postJson($this->base.'/bulk-payment-status', $payload, $this->headers($employee))->assertStatus(409);
        $this->assertSame('pending', $extra->fresh()->payment_status);
        $this->assertSame(1, $row->statusChanges()->count());
    }

    public function test_foreign_archived_or_missing_selection_and_missing_permission_cannot_mutate_a_neighbor(): void
    {
        $one = $this->order();
        $two = $this->order();
        $payload = $this->payload([$one, $two]);
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Foreign', 'slug' => 'foreign-bulk-payment', 'status' => 'active']);
        $two->update(['store_id' => $other->id]);
        $this->postJson($this->base.'/bulk-payment-status', $payload, $this->auth)->assertNotFound();
        $this->postJson($this->base.'/bulk-payment-review', ['target' => 'paid', 'ids' => [$one->id, $two->id]], $this->auth)->assertNotFound();
        $employee = User::factory()->create(['parent_user_id' => $this->owner->id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $this->postJson($this->base.'/bulk-payment-review', ['target' => 'paid', 'ids' => [$one->id]], $this->headers($employee))->assertForbidden();
        $this->postJson($this->base.'/bulk-payment-status', $this->payload([$one]), $this->headers($employee))->assertForbidden();
        $this->assertSame('pending', $one->fresh()->payment_status);
        $this->assertDatabaseCount('store_order_status_changes', 0);
        $payload = $this->payload([$one]);
        $one->delete();
        $this->postJson($this->base.'/bulk-payment-status', $payload, $this->auth)->assertNotFound();
    }

    public function test_provider_payments_other_settled_attempts_closed_orders_and_missing_proofs_are_reported_without_updates(): void
    {
        $online = $this->order('fawaterak');
        $settled = $this->order();
        $receipt = $this->order('transfer_receipt');
        $closed = $this->order('cod', ['status' => 'cancelled']);
        StorePaymentTransaction::create(['store_id' => $this->store->id, 'store_order_id' => $settled->id, 'gateway' => 'bank_transfer', 'idempotency_key' => Str::uuid(), 'status' => 'paid', 'paid_at' => now(), 'amount' => 100, 'currency' => 'EGP']);
        $preview = $this->postJson($this->base.'/bulk-payment-review', ['target' => 'paid', 'ids' => [$online->id, $settled->id, $receipt->id, $closed->id]], $this->auth)->assertOk();
        $this->assertSame(['provider_controls', 'settled_payment', 'receipt_required', 'order_closed'], array_column($preview->json('data'), 'reason'));
        $this->postJson($this->base.'/bulk-payment-status', $this->payload([$online, $settled, $receipt, $closed]), $this->auth)->assertOk()->assertJsonPath('updated', 0)->assertJsonPath('skipped', 4);
        $this->assertDatabaseCount('store_order_status_changes', 0);
        Http::assertNothingSent();
    }

    public function test_review_accepts_only_the_specific_displayed_receipt_even_with_matching_upload_timestamps(): void
    {
        $row = $this->order('transfer_receipt');
        $first = $this->proof($row, '00000000-0000-4000-8000-000000000001');
        $second = $this->proof($row, 'ffffffff-ffff-4fff-8fff-ffffffffffff');
        $this->postJson($this->base.'/bulk-payment-review', ['target' => 'paid', 'ids' => [$row->id]], $this->auth)->assertOk()->assertJsonPath('data.0.receipt.id', $second);
        $this->postJson($this->base.'/bulk-payment-status', $this->payload([$row]), $this->auth)->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame('pending', DB::table('store_payment_proofs')->where('id', $first)->value('status'));
        $this->assertSame('accepted', DB::table('store_payment_proofs')->where('id', $second)->value('status'));
    }

    public function test_settled_store_or_bridge_payments_cannot_be_downgraded_and_mismatched_or_processing_transactions_are_not_confirmed(): void
    {
        $paid = $this->order('cod', ['payment_status' => 'paid']);
        foreach (['pending', 'failed'] as $target) {
            $this->postJson($this->base.'/bulk-payment-status', $this->payload([$paid], $target), $this->auth)->assertOk()->assertJsonPath('skipped', 1)->assertJsonPath('updated', 0);
        }
        $bridgeOrder = $this->order();
        $bridge = app(StorefrontOrderBridge::class)->bridge($bridgeOrder, $this->store);
        $bridge->update(['paid_amount' => 25]);
        $mismatch = $this->order();
        $this->transaction($mismatch)->update(['amount' => 99]);
        $processing = $this->order();
        $this->transaction($processing)->update(['status' => 'processing']);
        $missing = $this->order();
        $this->transaction($missing)->delete();
        $response = $this->postJson($this->base.'/bulk-payment-review', ['target' => 'paid', 'ids' => [$bridgeOrder->id, $mismatch->id, $processing->id, $missing->id]], $this->auth)->assertOk();
        $this->assertSame(['settled_payment', 'amount_changed', 'processing_payment', 'missing_transaction'], array_column($response->json('data'), 'reason'));
        $this->assertSame('paid', $paid->fresh()->payment_status);
        $this->assertSame('25.00', $bridge->fresh()->paid_amount);
        $this->assertDatabaseCount('store_order_status_changes', 0);
    }

    public function test_wallet_lock_after_review_blocks_the_entire_batch_without_recording_collection(): void
    {
        config(['platform_wallet.fees_enabled' => true, 'platform_wallet.fee_milli' => 40, 'platform_wallet.debt_order_limit' => 5]);
        $wallet = app(PlatformWallet::class);
        $wallet->activateOrderFees();
        $one = $this->order();
        $two = $this->order();
        $payload = $this->payload([$one, $two]);
        foreach ([$one, $two, $this->order(), $this->order(), $this->order()] as $row) {
            $wallet->orderFee($row);
        }
        $this->assertTrue($wallet->ordersLocked($this->owner));
        $this->postJson($this->base.'/bulk-payment-status', $payload, $this->auth)->assertStatus(402);
        $this->postJson($this->base.'/bulk-payment-review', ['target' => 'paid', 'ids' => [$one->id]], $this->auth)->assertStatus(402);
        $this->assertSame('pending', $one->fresh()->payment_status);
        $this->assertSame('pending', $two->fresh()->payment_status);
        $this->assertDatabaseCount('store_order_status_changes', 0);
        $this->assertSame(0, StorePaymentTransaction::where('status', 'paid')->count());
    }

    public function test_empty_oversized_duplicate_and_invalid_target_requests_are_rejected_without_changes(): void
    {
        $row = $this->order();
        foreach ([[], [$row->id, $row->id], range(1, 101)] as $ids) {
            $this->postJson($this->base.'/bulk-payment-review', ['target' => 'paid', 'ids' => $ids], $this->auth)->assertUnprocessable();
        }
        $this->postJson($this->base.'/bulk-payment-review', ['target' => 'paid_failed', 'ids' => [$row->id]], $this->auth)->assertUnprocessable();
        $payload = $this->payload([$row]);
        $payload['confirmed'] = false;
        $this->postJson($this->base.'/bulk-payment-status', $payload, $this->auth)->assertUnprocessable();
        $this->assertSame('pending', $row->fresh()->payment_status);
        $this->assertDatabaseCount('store_order_status_changes', 0);
    }
}
