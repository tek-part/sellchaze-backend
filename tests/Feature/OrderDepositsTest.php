<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use App\Models\User;
use App\Services\Commerce\GiftCards;
use App\Services\Commerce\PaymentSettlement;
use App\Services\Commerce\StoreOrderService;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** G1 (TAR-036 #1): deposits paid online, the remainder collected on delivery. */
class OrderDepositsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://deposit.sellchase.com/api/v1/storefront';

    private Store $store;

    private User $owner;

    private Product $bag;

    private Product $custom;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Deposit store', 'slug' => 'deposit', 'currency' => 'EGP', 'status' => 'active',
            'shipping_enabled' => true, 'shipping_flat_rate' => '20.00']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'deposit.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true, 'sort_order' => 0]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'stripe', 'enabled' => true, 'sort_order' => 1,
            'credentials' => ['secret_key' => 'sk_test_synthetic', 'webhook_secret' => 'whsec_synthetic']]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true, 'sort_order' => 2]);
        $this->bag = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 10]);
        $this->custom = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Custom ring', 'slug' => 'ring', 'price' => 380, 'is_active' => true,
            'product_preferences' => ['requires_deposit' => true]]);
    }

    private function headers(?User $user = null): array
    {
        return ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($user ?? $this->owner)];
    }

    private function enable(array $config = []): void
    {
        $this->store->update(['deposit_configuration' => array_replace(['enabled' => true, 'percentage' => '20.00', 'minimum' => '0.00', 'scope' => 'all', 'confirm_after_payment' => false, 'version' => 1], $config)]);
    }

    private function body(string $method, array $products = [], array $extra = []): array
    {
        return array_replace(['cart_mode' => 'direct', 'items' => array_map(fn (Product $product) => ['product_id' => $product->id, 'quantity' => 1], $products ?: [$this->bag]),
            'customer_name' => 'Deposit buyer', 'customer_phone' => '01000000000', 'customer_email' => 'buyer@example.test',
            'shipping_address' => ['line1' => 'Street 1', 'city' => 'Cairo', 'country' => 'EG'], 'payment_method' => $method], $extra);
    }

    private function place(string $method, array $products = [], array $extra = []): TestResponse
    {
        return $this->postJson(self::HOST.'/checkout', $this->body($method, $products, $extra), ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function quote(string $method, array $products = [], array $extra = []): TestResponse
    {
        return $this->postJson(self::HOST.'/checkout/quote', $this->body($method, $products, $extra));
    }

    private function fakeStripe(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_deposit', 'url' => 'https://checkout.stripe.com/c/pay/cs_deposit'])]);
    }

    private function settle(int $orderId, string $reference = 'pi_deposit'): void
    {
        app(PaymentSettlement::class)->transition(StorePaymentTransaction::where('store_order_id', $orderId)->latest('id')->firstOrFail(), 'paid', $reference);
    }

    public function test_deposits_are_off_by_default_and_existing_totals_do_not_change(): void
    {
        $this->quote('cod')->assertOk()->assertJsonPath('data.deposit', null)->assertJsonPath('data.amount_due', '120.00')->assertJsonPath('data.totals.grand_total', '120.00');
        $methods = $this->getJson(self::HOST.'/payment-methods?product_ids[]='.$this->bag->id)->assertOk()->assertJsonPath('deposit', null);
        $this->assertContains('cod', array_column($methods->json('data'), 'slug'));
        $order = $this->place('cod')->assertCreated()->assertJsonPath('data.deposit', null)->assertJsonPath('data.amount_due', '120.00');
        $this->assertNull(StoreOrder::findOrFail($order->json('data.id'))->deposit_total);
        $this->getJson('/api/v1/my-store/payments/deposit', $this->headers())->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.version', 1);
    }

    public function test_an_online_deposit_is_charged_first_and_the_remainder_is_recorded_on_delivery(): void
    {
        $this->enable();
        $quote = $this->quote('stripe')->assertOk()->assertJsonPath('data.totals.grand_total', '120.00')->assertJsonPath('data.amount_due', '24.00')
            ->assertJsonPath('data.deposit.amount', '24.00')->assertJsonPath('data.deposit.remaining', '96.00')->assertJsonPath('data.deposit.remainder_method', 'cod');
        $methods = $this->getJson(self::HOST.'/payment-methods?product_ids[]='.$this->bag->id)->assertOk()->assertJsonPath('deposit.percentage', '20.00');
        $this->assertNotContains('cod', array_column($methods->json('data'), 'slug'));
        $this->place('cod', [], ['expected_total' => '120.00'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertDatabaseCount('store_orders', 0);

        $this->fakeStripe();
        $response = $this->place('stripe', [], ['expected_total' => $quote->json('data.totals.grand_total'), 'expected_deposit' => '24.00'])->assertCreated()
            ->assertJsonPath('data.amount_due', '24.00')->assertJsonPath('data.deposit.paid', false);
        Http::assertSent(fn ($request) => $request['line_items'][0]['price_data']['unit_amount'] === 2400);
        $order = StoreOrder::findOrFail($response->json('data.id'));
        $this->assertSame('24.00', $order->deposit_total);
        $this->assertSame('24.00', StorePaymentTransaction::where('store_order_id', $order->id)->firstOrFail()->amount);
        $this->assertSame('120.00', $order->collectOnDelivery());

        $this->settle($order->id);
        $order->refresh();
        $this->assertSame('deposit_paid', $order->payment_status);
        $this->assertSame('96.00', $order->amountDue());
        $this->assertSame('96.00', $order->collectOnDelivery());
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $order->id, 'source' => 'deposit_payment']);
        $this->getJson('/api/v1/my-store/orders/'.$order->id, $this->headers())->assertOk()->assertJsonPath('data.deposit.paid', true)
            ->assertJsonPath('data.deposit.remaining', '96.00')->assertJsonPath('data.amount_due', '96.00')->assertJsonPath('data.payment_status', 'deposit_paid');
        // A repeated provider confirmation changes nothing.
        $this->settle($order->id);
        $this->assertSame(1, $order->statusChanges()->where('source', 'deposit_payment')->count());

        $url = '/api/v1/my-store/orders/'.$order->id.'/payment/collect-remainder';
        $this->postJson($url, ['reference' => 'CASH-1', 'amount' => '120.00', 'currency' => 'EGP', 'collected' => true], $this->headers())->assertUnprocessable();
        $this->postJson($url, ['reference' => 'CASH-1', 'amount' => '96.00', 'currency' => 'EGP', 'collected' => true], $this->headers())->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.deposit.remainder_collected', true);
        $this->postJson($url, ['reference' => 'CASH-1', 'amount' => '96.00', 'currency' => 'EGP', 'collected' => true], $this->headers())->assertOk();
        $this->postJson($url, ['reference' => 'CASH-2', 'amount' => '96.00', 'currency' => 'EGP', 'collected' => true], $this->headers())->assertUnprocessable();
        $this->assertSame('0.00', $order->fresh()->collectOnDelivery());
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $order->id, 'source' => 'deposit_remainder', 'actor_id' => $this->owner->id]);
    }

    public function test_flagged_products_force_a_deposit_with_a_minimum_never_above_the_total(): void
    {
        $this->enable(['scope' => 'flagged', 'percentage' => '10.00', 'minimum' => '50.00']);
        $this->quote('stripe')->assertOk()->assertJsonPath('data.deposit', null);
        $this->quote('stripe', [$this->bag, $this->custom])->assertOk()->assertJsonPath('data.totals.grand_total', '500.00')
            ->assertJsonPath('data.deposit.amount', '50.00')->assertJsonPath('data.deposit.remaining', '450.00');
        $this->assertNotContains('cod', array_column($this->getJson(self::HOST.'/payment-methods?product_ids[]='.$this->custom->id)->json('data'), 'slug'));
        $this->assertContains('cod', array_column($this->getJson(self::HOST.'/payment-methods?product_ids[]='.$this->bag->id)->json('data'), 'slug'));
        // The minimum is capped at what the customer pays.
        $this->enable(['scope' => 'all', 'percentage' => '0.00', 'minimum' => '500.00']);
        $this->quote('stripe')->assertOk()->assertJsonPath('data.deposit.amount', '120.00')->assertJsonPath('data.deposit.remaining', '0.00');
    }

    public function test_a_changed_deposit_returns_a_fresh_quote_instead_of_an_order(): void
    {
        $this->enable();
        $this->place('stripe', [], ['expected_total' => '120.00', 'expected_deposit' => '0.00'])->assertStatus(409)->assertJsonPath('code', 'total_changed')
            ->assertJsonPath('quote.deposit.amount', '24.00');
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_confirmation_waits_for_the_deposit_when_the_merchant_requires_it(): void
    {
        $this->enable(['confirm_after_payment' => true]);
        $id = $this->place('bank_transfer')->assertCreated()->assertJsonPath('data.deposit.confirm_after_payment', true)->json('data.id');
        $order = StoreOrder::findOrFail($id);
        $this->assertNotContains('confirmed', app(StoreOrderService::class)->nextStatuses($order));
        $this->patchJson('/api/v1/my-store/orders/'.$id.'/status', ['status' => 'confirmed'], $this->headers())->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'This order is confirmed once its deposit is paid.');
        $this->postJson('/api/v1/my-store/orders/bulk-status', ['status' => 'confirmed', 'eligible_only' => true, 'orders' => [['id' => $id, 'status' => 'pending']]], $this->headers())
            ->assertOk()->assertJsonPath('skipped', 1);
        try {
            app(StoreOrderService::class)->transition($order, 'confirmed');
            $this->fail('A deposit order was confirmed before its deposit.');
        } catch (ValidationException $exception) {
            $this->assertSame('This order is confirmed once its deposit is paid.', $exception->errors()['status'][0]);
        }
        // The merchant confirms the transfer of the deposit: the order can be confirmed.
        $this->postJson('/api/v1/my-store/orders/'.$id.'/payment/confirm-bank', ['reference' => 'TRF-1'], $this->headers())->assertOk()
            ->assertJsonPath('data.payment_status', 'deposit_paid')->assertJsonPath('data.amount_due', '96.00');
        $this->postJson('/api/v1/my-store/orders/'.$id.'/payment/confirm-bank', ['reference' => 'TRF-1'], $this->headers())->assertOk();
        $this->patchJson('/api/v1/my-store/orders/'.$id.'/status', ['status' => 'confirmed'], $this->headers())->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    public function test_gift_cards_pay_first_and_the_deposit_is_a_share_of_what_is_left(): void
    {
        $this->enable(['percentage' => '50.00']);
        $code = app(GiftCards::class)->issue($this->store, ['initial_value' => '20.00'], $this->owner->id)['code'];
        $this->quote('stripe', [], ['gift_cards' => [$code]])->assertOk()->assertJsonPath('data.totals.grand_total', '120.00')
            ->assertJsonPath('data.deposit.amount', '50.00')->assertJsonPath('data.deposit.remaining', '50.00')->assertJsonPath('data.amount_due', '50.00');
        $id = $this->place('bank_transfer', [], ['gift_cards' => [$code]])->assertCreated()->assertJsonPath('data.gift_card_total', '20.00')->json('data.id');
        $order = StoreOrder::findOrFail($id);
        $this->assertSame('50.00', $order->amountDue());
        $this->assertSame('50.00', $order->remainderDue());
        $this->assertSame('50.00', StorePaymentTransaction::where('store_order_id', $id)->firstOrFail()->amount);
        // Cards that pay everything leave nothing for a deposit.
        $full = app(GiftCards::class)->issue($this->store, ['initial_value' => '200.00'], $this->owner->id)['code'];
        $this->quote('stripe', [], ['gift_cards' => [$full]])->assertOk()->assertJsonPath('data.deposit', null)->assertJsonPath('data.amount_due', '0.00');
    }

    public function test_refunds_of_a_cancelled_deposit_order_are_capped_at_the_deposit(): void
    {
        $this->enable(['percentage' => '25.00']);
        $id = $this->place('bank_transfer')->assertCreated()->json('data.id');
        $this->postJson('/api/v1/my-store/orders/'.$id.'/payment/confirm-bank', ['reference' => 'TRF-2'], $this->headers())->assertOk();
        $this->patchJson('/api/v1/my-store/orders/'.$id.'/status', ['status' => 'cancelled'], $this->headers())->assertOk();
        $summary = $this->getJson('/api/v1/my-store/orders/'.$id.'/refunds', $this->headers())->assertOk();
        $summary->assertJsonPath('data.money_collected', '30.00')->assertJsonPath('data.refundable_money', '30.00')->assertJsonPath('data.refundable', true);
        $item = $summary->json('data.items.0.store_order_item_id');
        $this->postJson('/api/v1/my-store/orders/'.$id.'/refunds', ['items' => [['store_order_item_id' => $item, 'quantity' => 1]], 'amount' => '31.00', 'reason' => 'other', 'method' => 'bank_transfer'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson('/api/v1/my-store/orders/'.$id.'/refunds', ['items' => [['store_order_item_id' => $item, 'quantity' => 1]], 'reason' => 'other', 'method' => 'bank_transfer'], $this->headers())
            ->assertCreated()->assertJsonPath('data.refunded_money', '30.00')->assertJsonPath('data.refundable_money', '0.00');
        $this->assertSame('refunded', StoreOrder::findOrFail($id)->payment_status);
    }

    public function test_settings_are_validated_versioned_and_need_settings_permission(): void
    {
        $url = '/api/v1/my-store/payments/deposit';
        $valid = ['enabled' => true, 'percentage' => '30', 'minimum' => '0', 'scope' => 'flagged', 'confirm_after_payment' => true, 'version' => 1];
        $this->putJson($url, ['percentage' => '0', 'minimum' => '0'] + $valid, $this->headers())->assertUnprocessable()->assertJsonValidationErrors('percentage');
        $this->putJson($url, ['percentage' => '120'] + $valid, $this->headers())->assertUnprocessable();
        $this->putJson($url, ['scope' => 'some'] + $valid, $this->headers())->assertUnprocessable();
        $this->putJson($url, $valid, $this->headers())->assertOk()->assertJsonPath('data.percentage', '30.00')->assertJsonPath('data.scope', 'flagged')->assertJsonPath('data.version', 2);
        $this->putJson($url, $valid, $this->headers())->assertStatus(409);

        $employee = User::factory()->create(['is_active' => true, 'pending_approval' => false, 'parent_user_id' => $this->owner->id]);
        $employee->assignRole('Employee');
        $employee->givePermissionTo('store.orders.manage');
        $this->putJson($url, ['version' => 2] + $valid, $this->headers($employee))->assertForbidden();
        $outsider = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $outsider->assignRole('Merchant');
        $this->getJson('/api/v1/stores/'.$this->store->id.'/payments/deposit', $this->headers($outsider))->assertNotFound();
        $this->assertSame(2, $this->store->fresh()->deposit_configuration['version']);
    }

    public function test_remainder_collection_is_store_scoped_and_needs_order_permission(): void
    {
        $this->enable();
        $id = $this->place('bank_transfer')->assertCreated()->json('data.id');
        $payload = ['reference' => 'CASH', 'amount' => '96.00', 'currency' => 'EGP', 'collected' => true];
        // Nothing to collect before the deposit is paid.
        $this->postJson('/api/v1/my-store/orders/'.$id.'/payment/collect-remainder', $payload, $this->headers())->assertUnprocessable();
        $this->postJson('/api/v1/my-store/orders/'.$id.'/payment/confirm-bank', ['reference' => 'TRF-3'], $this->headers())->assertOk();
        $employee = User::factory()->create(['is_active' => true, 'pending_approval' => false, 'parent_user_id' => $this->owner->id]);
        $employee->assignRole('Employee');
        $this->postJson('/api/v1/my-store/orders/'.$id.'/payment/collect-remainder', $payload, $this->headers($employee))->assertForbidden();
        $intruder = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $intruder->assignRole('Merchant');
        Store::create(['owner_user_id' => $intruder->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-deposit', 'status' => 'active', 'currency' => 'EGP']);
        app(CurrentStore::class)->forget();
        $this->postJson('/api/v1/my-store/orders/'.$id.'/payment/collect-remainder', $payload, $this->headers($intruder))->assertNotFound();
        $this->postJson('/api/v1/stores/'.$this->store->id.'/orders/'.$id.'/payment/collect-remainder', $payload, $this->headers($intruder))->assertForbidden();
        $this->assertSame('deposit_paid', StoreOrder::forStore($this->store)->findOrFail($id)->payment_status);
    }

    public function test_a_store_without_a_method_that_can_collect_a_deposit_never_dead_ends_checkout(): void
    {
        StorePaymentGateway::where('store_id', $this->store->id)->where('gateway', '!=', 'cod')->update(['enabled' => false]);
        $valid = ['enabled' => true, 'percentage' => '20', 'minimum' => '0', 'scope' => 'all', 'confirm_after_payment' => false, 'version' => 1];
        $this->putJson('/api/v1/my-store/payments/deposit', $valid, $this->headers())->assertUnprocessable()->assertJsonValidationErrors('enabled');
        $this->putJson('/api/v1/my-store/payments/deposit', ['enabled' => false] + $valid, $this->headers())->assertOk()->assertJsonPath('data.payable', false);
        // Deposits left on while the last online method was disabled: checkout falls back to cash on delivery.
        $this->enable();
        $this->getJson('/api/v1/my-store/payments/deposit', $this->headers())->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.payable', false);
        $this->getJson(self::HOST.'/payment-methods?product_ids[]='.$this->bag->id)->assertOk()->assertJsonPath('deposit', null)->assertJsonPath('data.0.slug', 'cod');
        $this->quote('cod')->assertOk()->assertJsonPath('data.deposit', null)->assertJsonPath('data.amount_due', '120.00');
        $this->place('cod')->assertCreated()->assertJsonPath('data.deposit', null);
    }

    public function test_the_remainder_stays_collectible_after_the_deposit_was_refunded_and_nothing_is_due_after(): void
    {
        $this->enable();
        // Two bags: 200 + 20 delivery; deposit 44, remainder 176.
        $id = $this->place('bank_transfer', [], ['items' => [['product_id' => $this->bag->id, 'quantity' => 2]]])->assertCreated()->assertJsonPath('data.deposit.amount', '44.00')->json('data.id');
        $this->postJson('/api/v1/my-store/orders/'.$id.'/payment/confirm-bank', ['reference' => 'TRF-9'], $this->headers())->assertOk();
        $order = StoreOrder::findOrFail($id);
        foreach (['confirmed', 'processing', 'shipped'] as $status) {
            app(StoreOrderService::class)->transition($order, $status);
        }
        $item = $order->items()->firstOrFail();
        // Part of the deposit back, then all of it: the remainder is still collected on delivery.
        $this->postJson('/api/v1/my-store/orders/'.$id.'/refunds', ['shipping_amount' => '10.00', 'reason' => 'other', 'method' => 'bank_transfer'], $this->headers())->assertCreated();
        $this->assertSame(['partially_refunded', '176.00', '176.00'], [$order->fresh()->payment_status, $order->fresh()->collectOnDelivery(), $order->fresh()->amountDue()]);
        $this->postJson('/api/v1/my-store/orders/'.$id.'/refunds', ['items' => [['store_order_item_id' => $item->id, 'quantity' => 1]], 'amount' => '34.00', 'reason' => 'other', 'method' => 'bank_transfer'], $this->headers())->assertCreated();
        $this->assertSame(['refunded', 'shipped', '176.00'], [$order->fresh()->payment_status, $order->fresh()->status, $order->fresh()->collectOnDelivery()]);
        $this->postJson('/api/v1/my-store/orders/'.$id.'/payment/collect-remainder', ['reference' => 'CASH-9', 'amount' => '176.00', 'currency' => 'EGP', 'collected' => true], $this->headers())->assertOk()
            ->assertJsonPath('data.payment_status', 'partially_refunded')->assertJsonPath('data.amount_due', '0.00')->assertJsonPath('data.deposit.remainder_collected', true);
        $this->assertSame('0.00', $order->fresh()->collectOnDelivery());
    }
}
