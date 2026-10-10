<?php

namespace Tests\Feature;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Mail\DigitalOrderPaidMail;
use App\Mail\DigitalOrderReceiptMail;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCarrierConnection;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use App\Models\StoreShipment;
use App\Models\User;
use App\Services\Commerce\DigitalProducts;
use App\Services\Commerce\ManualCashPayment;
use App\Services\Commerce\StorefrontOrderBridge;
use App\Services\Commerce\StoreOrderService;
use App\Services\JwtTokenService;
use App\Services\Outbox\OutboxPublisher;
use App\Services\Shipping\StoreShipmentEvents;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class CashCollectionTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private Product $digital;

    private Product $physical;

    private string $base = 'http://cash.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake([BridgeStorefrontOrderJob::class]);
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $supplier = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $supplier->assignRole('Supplier');
        $this->owner->suppliersAsMerchant()->attach($supplier->id, ['status' => 'accepted']);
        config()->set('services.sellchase_fallback_supplier_user_id', null);
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Cash review', 'slug' => 'cash', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'cash.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true, 'test_mode' => true]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true, 'test_mode' => true]);
        $this->digital = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Cash digital license', 'slug' => 'license', 'price' => 100, 'is_active' => true, 'digital_type' => 'codes']);
        app(DigitalProducts::class)->appendCodes($this->digital, ['PRIVATE-CASH-1', 'PRIVATE-CASH-2', 'PRIVATE-CASH-3']);
        $this->physical = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Physical bag', 'slug' => 'bag', 'price' => 50, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 10]);
    }

    private function headers(?User $user = null): array
    {
        return ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($user ?? $this->owner)];
    }

    private function place(bool $mixed = true, string $method = 'cod'): array
    {
        $items = [['product_id' => $this->physical->id, 'quantity' => 1]];
        if ($mixed) {
            array_unshift($items, ['product_id' => $this->digital->id, 'quantity' => 1]);
        }
        $data = $this->postJson($this->base.'/checkout', ['items' => $items, 'customer_name' => 'Cash review buyer', 'customer_phone' => '01000000000',
            'customer_email' => 'cash@example.test', 'shipping_address' => ['line1' => 'Review address', 'city' => 'Cairo', 'country' => 'EG'], 'payment_method' => $method])->assertCreated()->json();
        app(StorefrontOrderBridge::class)->bridge(StoreOrder::forStore($this->store->id)->findOrFail($data['data']['id']), $this->store);

        return $data;
    }

    private function confirm(array $data, array $override = [], ?User $user = null): TestResponse
    {
        return $this->postJson('/api/v1/my-store/orders/'.$data['data']['id'].'/payment/confirm-cash', array_replace([
            'reference' => 'CASH-REVIEW', 'amount' => $data['data']['grand_total'], 'currency' => 'EGP', 'collected' => true, 'note' => 'Synthetic receipt checked',
        ], $override), $this->headers($user));
    }

    public function test_carrier_delivery_does_not_release_codes_but_audited_full_collection_does_once(): void
    {
        $data = $this->place();
        $id = $data['data']['id'];
        $order = StoreOrder::forStore($this->store->id)->findOrFail($id);
        app(StoreOrderService::class)->transition($order, 'confirmed', $this->owner->id);
        $connection = StoreCarrierConnection::create(['store_id' => $this->store->id, 'carrier' => 'bosta', 'enabled' => true, 'environment' => 'sandbox']);
        $shipment = StoreShipment::create(['store_id' => $this->store->id, 'store_order_id' => $id, 'store_carrier_connection_id' => $connection->id, 'carrier' => 'bosta', 'business_reference' => 'cash-review', 'status' => 'created', 'request_snapshot' => ['cod' => 150]]);
        app(CurrentStore::class)->forget();
        app(StoreShipmentEvents::class)->apply($shipment, 'delivery-review', 'tracking-review', 45, 100000, 'webhook');
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(0, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
        $this->postJson($this->base.'/checkout/receipt', ['token' => $data['receipt']['token']])->assertOk()->assertJsonPath('data.items.0.digital_delivery.values', []);
        $this->confirm($data)->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.payment_reference', 'CASH-REVIEW')
            ->assertJsonPath('data.items.0.digital_delivery.values', ['PRIVATE-CASH-1']);
        $this->confirm($data)->assertOk();
        $transaction = StorePaymentTransaction::where('store_order_id', $id)->firstOrFail();
        $this->assertSame('paid', $transaction->status);
        $this->assertSame('150.00', $transaction->metadata['cash_collection']['amount']);
        $this->assertSame($this->owner->id, $transaction->metadata['cash_collection']['actor_id']);
        $this->assertSame('Synthetic receipt checked', $transaction->metadata['cash_collection']['note']);
        $this->assertNotNull($transaction->paid_at);
        $this->assertSame(1, $order->statusChanges()->where('source', 'cash_payment')->count());
        $this->assertSame(1, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
        $bridge = Order::where('store_order_id', $id)->firstOrFail();
        $this->assertSame('150.00', $bridge->paid_amount);
        $this->assertSame('CASH-REVIEW', $bridge->payment_transaction_id);
        $this->assertSame(1, (int) $bridge->quantity);
        $this->assertStringNotContainsString('PRIVATE-CASH', $bridge->getRawOriginal('storefront_items'));
        $this->postJson($this->base.'/checkout/receipt', ['token' => $data['receipt']['token']])->assertOk()->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.items.0.digital_delivery.values', ['PRIVATE-CASH-1'])
            ->assertDontSee('Synthetic receipt checked');
        app(CurrentStore::class)->forget();
        app(OutboxPublisher::class)->publishPending();
        app(OutboxPublisher::class)->publishPending();
        Mail::assertSent(DigitalOrderPaidMail::class, 1);
        Http::assertNothingSent();
    }

    public function test_physical_cash_order_can_be_paid_without_advancing_fulfillment_or_sending_digital_mail(): void
    {
        $data = $this->place(false);
        $this->confirm($data)->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.payment_status', 'paid');
        $this->assertSame(10, (int) $this->physical->fresh()->stock_quantity);
        $this->assertSame(1, (int) $this->physical->fresh()->reserved_quantity);
        $this->assertSame(0, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
    }

    public function test_pending_cash_receipt_explains_collection_and_withholds_all_private_codes(): void
    {
        $this->store->update(['default_locale' => 'en']);
        $this->place();
        app(CurrentStore::class)->forget();
        app(OutboxPublisher::class)->publishPending();
        Mail::assertSent(DigitalOrderReceiptMail::class, function ($mail) {
            $html = $mail->render();
            $this->assertStringContainsString('Cash on delivery:', $html);
            $this->assertStringContainsString('Parcel delivery alone does not confirm payment.', $html);
            $this->assertStringContainsString('#receipt=', $html);
            $this->assertStringNotContainsString('PRIVATE-CASH-', $html);

            return $mail->hasTo('cash@example.test');
        });
        Mail::assertNotSent(DigitalOrderPaidMail::class);
    }

    public function test_partial_wrong_currency_missing_acknowledgement_and_invalid_decimals_are_rejected(): void
    {
        $data = $this->place();
        foreach ([['amount' => '149.99'], ['amount' => '150.01'], ['currency' => 'USD'], ['collected' => false], ['reference' => '   '], ['amount' => '150.001'], ['amount' => '1.5e2']] as $override) {
            $this->confirm($data, $override)->assertUnprocessable();
        }
        $this->assertSame('pending', StoreOrder::forStore($this->store->id)->findOrFail($data['data']['id'])->payment_status);
        $this->assertSame(0, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
    }

    public function test_transaction_snapshot_mismatch_failed_missing_and_non_cash_payments_fail_closed(): void
    {
        $data = $this->place();
        $transaction = StorePaymentTransaction::where('store_order_id', $data['data']['id'])->firstOrFail();
        foreach ([['amount' => 1], ['currency' => 'USD'], ['status' => 'failed']] as $override) {
            $transaction->update(array_replace(['amount' => 150, 'currency' => 'EGP', 'status' => 'pending'], $override));
            $this->confirm($data)->assertUnprocessable();
        }
        $transaction->delete();
        $this->confirm($data)->assertUnprocessable();
        $bank = $this->place(true, 'bank_transfer');
        $this->confirm($bank)->assertUnprocessable();
        $this->assertSame(0, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
    }

    public function test_cancelled_and_inconsistent_paid_orders_cannot_collect_or_overwrite_reference(): void
    {
        $data = $this->place();
        $this->confirm($data)->assertOk();
        $this->confirm($data, ['reference' => 'OTHER'])->assertUnprocessable();
        $id = $data['data']['id'];
        StorePaymentTransaction::where('store_order_id', $id)->update(['status' => 'pending']);
        $this->confirm($data)->assertUnprocessable();
        $cancelled = $this->place();
        app(CurrentStore::class)->set($this->store);
        app(StoreOrderService::class)->transition(StoreOrder::forStore($this->store->id)->findOrFail($cancelled['data']['id']), 'cancelled', $this->owner->id);
        $this->confirm($cancelled)->assertUnprocessable();
        $this->assertSame(1, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
    }

    public function test_only_permitted_store_staff_can_confirm_and_foreign_order_ids_remain_hidden(): void
    {
        $data = $this->place();
        $employee = User::factory()->create(['is_active' => true, 'pending_approval' => false, 'parent_user_id' => $this->owner->id]);
        $employee->assignRole('Employee');
        $this->confirm($data, [], $employee)->assertForbidden();
        $outsider = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $outsider->assignRole('Merchant');
        $this->postJson('/api/v1/stores/'.$this->store->id.'/orders/'.$data['data']['id'].'/payment/confirm-cash', [], $this->headers($outsider))->assertForbidden();
        $this->postJson('/api/v1/my-store/orders/999999/payment/confirm-cash', ['reference' => 'CASH', 'amount' => 150, 'currency' => 'EGP', 'collected' => true], $this->headers())->assertNotFound();
        $this->assertSame(0, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
    }

    public function test_permitted_employee_is_recorded_and_malformed_legacy_metadata_is_not_a_replay(): void
    {
        $data = $this->place();
        $employee = User::factory()->create(['is_active' => true, 'pending_approval' => false, 'parent_user_id' => $this->owner->id]);
        $employee->assignRole('Employee');
        $employee->givePermissionTo('store.orders.manage');
        $this->confirm($data, ['reference' => 'EMPLOYEE-CASH'], $employee)->assertOk();
        $transaction = StorePaymentTransaction::where('store_order_id', $data['data']['id'])->firstOrFail();
        $this->assertSame($employee->id, $transaction->metadata['cash_collection']['actor_id']);
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $data['data']['id'], 'actor_id' => $employee->id, 'source' => 'cash_payment']);
        $transaction->update(['metadata' => ['cash_collection' => 'EMPLOYEE-CASH']]);
        $this->confirm($data, ['reference' => 'EMPLOYEE-CASH'], $employee)->assertUnprocessable();
        $this->assertSame(1, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
    }

    public function test_failure_during_bridge_sync_rolls_back_payment_audit_and_delivery_event(): void
    {
        $data = $this->place();
        $order = StoreOrder::forStore($this->store->id)->findOrFail($data['data']['id']);
        $this->mock(StorefrontOrderBridge::class)->shouldReceive('syncPayment')->once()->andThrow(new RuntimeException('Review rollback'));
        try {
            app(ManualCashPayment::class)->confirm($order, 'ROLLBACK', '150.00', 'EGP', $this->owner->id);
            $this->fail('The simulated failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Review rollback', $exception->getMessage());
        }
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->payment_reference);
        $transaction = StorePaymentTransaction::where('store_order_id', $order->id)->firstOrFail();
        $this->assertSame('pending', $transaction->status);
        $this->assertNull($transaction->paid_at);
        $this->assertArrayNotHasKey('cash_collection', $transaction->metadata);
        $this->assertSame(0, $order->statusChanges()->where('source', 'cash_payment')->count());
        $this->assertSame(0, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
    }
}
