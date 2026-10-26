<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StoreOrderWebhookDelivery;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use App\Models\User;
use App\Services\Commerce\OrderReceipt;
use App\Services\Commerce\PaymentSettlement;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/** G1 (TAR-036 #11, #13, #2): change an unpaid order's payment method, transfer targets, and printable invoice data. */
class OrderPaymentMethodChangeTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://switch.sellchase.com/api/v1/storefront';

    private Store $store;

    private User $owner;

    private Product $bag;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Switch store', 'slug' => 'switch', 'currency' => 'EGP', 'status' => 'active',
            'shipping_enabled' => true, 'shipping_flat_rate' => '20.00', 'phone' => '+201000000009']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'switch.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
        $this->bag = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 10]);
    }

    private function headers(?User $user = null): array
    {
        return ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($user ?? $this->owner)];
    }

    private function url(int $id, string $path): string
    {
        return '/api/v1/my-store/orders/'.$id.'/payment/'.$path;
    }

    /** A storefront order whose online payment failed: 120.00 plus a 10% method fee. */
    private function failedOnlineOrder(): StoreOrder
    {
        $order = StoreOrder::create(['order_number' => 'ORD-'.Str::upper(Str::random(8)), 'customer_name' => 'Buyer', 'customer_phone' => '01000000000', 'status' => 'pending',
            'payment_method' => 'stripe', 'payment_status' => 'failed', 'currency' => 'EGP', 'subtotal' => '100.00', 'shipping_total' => '20.00', 'tax_total' => '0.00',
            'discount_total' => '0.00', 'grand_total' => '132.00', 'shipping_address' => ['line1' => 'Street', 'city' => 'Cairo', 'country' => 'EG'],
            'payment_adjustment' => ['version' => 1, 'gateway' => 'stripe', 'currency' => 'EGP', 'rule' => ['enabled' => true, 'type' => 'percentage', 'direction' => 'add', 'value' => '10.00'],
                'base_total' => '120.00', 'amount' => '12.00']]);
        $order->items()->create(['store_id' => $this->store->id, 'store_product_id' => $this->bag->id, 'name' => 'Bag', 'quantity' => 1, 'unit_price' => '100.00', 'line_total' => '100.00']);
        StorePaymentTransaction::create(['store_id' => $this->store->id, 'store_order_id' => $order->id, 'gateway' => 'stripe', 'idempotency_key' => (string) Str::uuid(),
            'status' => 'failed', 'amount' => '132.00', 'currency' => 'EGP', 'failed_at' => now()]);

        return $order;
    }

    public function test_a_failed_online_payment_moves_to_cash_on_delivery_with_its_own_fee(): void
    {
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true,
            'presentation' => ['price_adjustment' => ['enabled' => true, 'type' => 'fixed', 'direction' => 'add', 'value' => '5.00']]]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true]);
        Http::fake(['https://hooks.example.test/*' => Http::response([], 204)]);
        $this->postJson('/api/v1/my-store/webhooks', ['url' => 'https://hooks.example.test/orders', 'description' => 'Local fixture', 'type' => 'orders', 'enabled' => true,
            'request_key' => (string) Str::uuid()], $this->headers())->assertCreated();
        $order = $this->failedOnlineOrder();

        $options = $this->getJson($this->url($order->id, 'methods'), $this->headers())->assertOk()->assertJsonPath('data.blocker', null)->json('data.methods');
        $cod = collect($options)->firstWhere('slug', 'cod');
        $this->assertSame('125.00', $cod['grand_total']);
        $this->assertSame('5.00', $cod['payment_adjustment_total']);
        $this->assertSame('120.00', collect($options)->firstWhere('slug', 'bank_transfer')['grand_total']);

        $this->postJson($this->url($order->id, 'method'), ['payment_method' => 'cod', 'expected_grand_total' => '126.00'], $this->headers())->assertStatus(409);
        $this->postJson($this->url($order->id, 'method'), ['payment_method' => 'stripe'], $this->headers())->assertUnprocessable();
        $this->postJson($this->url($order->id, 'method'), ['payment_method' => 'cod', 'expected_grand_total' => '125.00', 'note' => 'Customer asked by phone'], $this->headers())->assertOk()
            ->assertJsonPath('data.payment_method', 'cod')->assertJsonPath('data.payment_status', 'pending')->assertJsonPath('data.grand_total', '125.00')
            ->assertJsonPath('data.payment_adjustment_total', '5.00')->assertJsonPath('data.amount_due', '125.00');

        $old = StorePaymentTransaction::where('store_order_id', $order->id)->where('gateway', 'stripe')->firstOrFail();
        $this->assertSame('payment_method_change', $old->metadata['superseded']['by']);
        $this->assertSame('125.00', StorePaymentTransaction::where('store_order_id', $order->id)->where('gateway', 'cod')->firstOrFail()->amount);
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $order->id, 'source' => 'payment_method_change', 'actor_id' => $this->owner->id]);
        $this->assertSame(1, StoreOrderWebhookDelivery::where('store_order_id', $order->id)->where('event_key', 'like', 'payment-method:%')->count());
        // The new method settles as usual: the full cash amount is confirmed.
        $this->postJson('/api/v1/my-store/orders/'.$order->id.'/payment/confirm-cash', ['reference' => 'CASH-9', 'amount' => '125.00', 'currency' => 'EGP', 'collected' => true], $this->headers())
            ->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->getJson($this->url($order->id, 'methods'), $this->headers())->assertOk()->assertJsonPath('data.blocker', 'payment_settled')->assertJsonPath('data.methods', []);
        $this->postJson($this->url($order->id, 'method'), ['payment_method' => 'bank_transfer'], $this->headers())->assertUnprocessable();
    }

    public function test_the_zone_cash_on_delivery_fee_follows_the_method(): void
    {
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true]);
        $this->postJson('/api/v1/my-store/shipping/zones', ['name' => ['ar' => 'مصر', 'en' => 'Egypt'], 'countries' => ['EG'], 'regions' => [], 'enabled' => true,
            'rates' => [['name' => ['ar' => 'عادي', 'en' => 'Standard'], 'type' => 'flat', 'price' => '30.00', 'free_over' => null, 'delivery_time' => ['ar' => '', 'en' => ''], 'cod_fee' => '15.00', 'enabled' => true]]], $this->headers())->assertCreated();
        $id = $this->postJson(self::HOST.'/checkout', ['cart_mode' => 'direct', 'items' => [['product_id' => $this->bag->id, 'quantity' => 1]], 'customer_name' => 'Buyer', 'customer_phone' => '01000000000', 'customer_email' => 'buyer@example.test',
            'shipping_country' => 'EG', 'shipping_address' => ['line1' => 'Street', 'city' => 'Cairo', 'country' => 'EG'], 'payment_method' => 'cod'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.shipping_total', '45.00')->assertJsonPath('data.grand_total', '145.00')->json('data.id');

        $this->postJson($this->url($id, 'method'), ['payment_method' => 'bank_transfer', 'expected_grand_total' => '130.00'], $this->headers())->assertOk()
            ->assertJsonPath('data.shipping_total', '30.00')->assertJsonPath('data.grand_total', '130.00')->assertJsonPath('data.shipping_address.shipping_details.cod_fee', '0.00');
        $this->postJson($this->url($id, 'method'), ['payment_method' => 'cod'], $this->headers())->assertOk()
            ->assertJsonPath('data.shipping_total', '45.00')->assertJsonPath('data.grand_total', '145.00');
        $this->assertSame(2, StoreOrder::findOrFail($id)->statusChanges()->where('source', 'payment_method_change')->count());
    }

    public function test_moving_a_deposit_order_to_cash_on_delivery_waives_the_deposit(): void
    {
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true]);
        $this->store->update(['deposit_configuration' => ['enabled' => true, 'percentage' => '50.00', 'minimum' => '0.00', 'scope' => 'all', 'confirm_after_payment' => true, 'version' => 1]]);
        $id = $this->postJson(self::HOST.'/checkout', ['cart_mode' => 'direct', 'items' => [['product_id' => $this->bag->id, 'quantity' => 1]], 'customer_name' => 'Buyer', 'customer_phone' => '01000000000', 'customer_email' => 'buyer@example.test',
            'shipping_address' => ['line1' => 'Street', 'city' => 'Cairo', 'country' => 'EG'], 'payment_method' => 'bank_transfer'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.deposit.amount', '60.00')->json('data.id');
        $this->postJson($this->url($id, 'method'), ['payment_method' => 'cod'], $this->headers())->assertOk()
            ->assertJsonPath('data.deposit', null)->assertJsonPath('data.amount_due', '120.00');
        // Without the deposit the merchant may confirm the order.
        $this->patchJson('/api/v1/my-store/orders/'.$id.'/status', ['status' => 'confirmed'], $this->headers())->assertOk();
    }

    public function test_changes_are_store_scoped_and_need_order_permission(): void
    {
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true]);
        $order = $this->failedOnlineOrder();
        $employee = User::factory()->create(['is_active' => true, 'pending_approval' => false, 'parent_user_id' => $this->owner->id]);
        $employee->assignRole('Employee');
        $this->getJson($this->url($order->id, 'methods'), $this->headers($employee))->assertForbidden();
        $this->postJson($this->url($order->id, 'method'), ['payment_method' => 'cod'], $this->headers($employee))->assertForbidden();
        $intruder = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $intruder->assignRole('Merchant');
        Store::create(['owner_user_id' => $intruder->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-switch', 'status' => 'active', 'currency' => 'EGP']);
        app(CurrentStore::class)->forget();
        $this->postJson($this->url($order->id, 'method'), ['payment_method' => 'cod'], $this->headers($intruder))->assertNotFound();
        $this->postJson('/api/v1/stores/'.$this->store->id.'/orders/'.$order->id.'/payment/method', ['payment_method' => 'cod'], $this->headers($intruder))->assertForbidden();
        $this->assertSame('stripe', StoreOrder::forStore($this->store)->findOrFail($order->id)->payment_method);
        $permitted = User::factory()->create(['is_active' => true, 'pending_approval' => false, 'parent_user_id' => $this->owner->id]);
        $permitted->assignRole('Employee');
        $permitted->givePermissionTo('store.orders.manage');
        $this->postJson($this->url($order->id, 'method'), ['payment_method' => 'cod'], $this->headers($permitted))->assertOk()->assertJsonPath('data.payment_method', 'cod');
    }

    public function test_transfer_targets_are_validated_and_shown_with_safe_links(): void
    {
        $url = '/api/v1/my-store/payments';
        $gateway = fn (array $targets, array $credentials = []) => ['gateways' => [['slug' => 'transfer_receipt', 'enabled' => true, 'credentials' => $credentials,
            'presentation' => ['transfer_targets' => $targets]]]];
        $instapay = ['label' => 'InstaPay', 'number' => 'shop@instapay', 'link' => 'https://ipn.eg/S/shop/instapay/abc'];
        $this->putJson($url, $gateway([['label' => 'InstaPay', 'number' => '010', 'link' => 'javascript:alert(1)']]), $this->headers())->assertUnprocessable();
        $this->putJson($url, $gateway([$instapay, $instapay, $instapay]), $this->headers())->assertUnprocessable();
        $this->putJson($url, $gateway([['label' => '', 'number' => '010']]), $this->headers())->assertUnprocessable();
        $this->putJson($url, ['gateways' => [['slug' => 'cod', 'enabled' => true, 'presentation' => ['transfer_targets' => [$instapay]]]]], $this->headers())->assertUnprocessable();
        // Without a bank account or targets, transfer with receipt cannot be enabled.
        $this->putJson($url, $gateway([]), $this->headers())->assertUnprocessable();
        // A wallet number alone is enough.
        $rows = $this->putJson($url, $gateway([$instapay, ['label' => 'Vodafone Cash', 'number' => '01000000001']]), $this->headers())->assertOk()->json('data');
        $row = collect($rows)->firstWhere('slug', 'transfer_receipt');
        $this->assertTrue($row['configured']);
        $this->assertSame([$instapay, ['label' => 'Vodafone Cash', 'number' => '01000000001', 'link' => null]], $row['transfer_targets']);

        $methods = $this->getJson(self::HOST.'/payment-methods?product_ids[]='.$this->bag->id)->assertOk()->json('data');
        $this->assertSame('https://ipn.eg/S/shop/instapay/abc', collect($methods)->firstWhere('slug', 'transfer_receipt')['transfer_targets'][0]['link']);
        $id = $this->postJson(self::HOST.'/checkout', ['cart_mode' => 'direct', 'items' => [['product_id' => $this->bag->id, 'quantity' => 1]], 'customer_name' => 'Buyer', 'customer_phone' => '01000000000', 'customer_email' => 'buyer@example.test',
            'shipping_address' => ['line1' => 'Street', 'city' => 'Cairo', 'country' => 'EG'], 'payment_method' => 'transfer_receipt'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $token = app(OrderReceipt::class)->make(StoreOrder::findOrFail($id))['token'];
        $this->postJson(self::HOST.'/checkout/receipt', ['token' => $token])->assertOk()
            ->assertJsonPath('bank_transfer.targets.0.number', 'shop@instapay')->assertJsonPath('bank_transfer.targets.1.link', null);
        // A stored unsafe link (e.g. from an older save) is never served.
        StorePaymentGateway::where('store_id', $this->store->id)->where('gateway', 'transfer_receipt')->update(['presentation' => json_encode(['transfer_targets' => [['label' => 'X', 'number' => '1', 'link' => 'javascript:alert(1)']]])]);
        $this->assertNull(collect($this->getJson(self::HOST.'/payment-methods')->json('data'))->firstWhere('slug', 'transfer_receipt')['transfer_targets'][0]['link']);
    }

    public function test_print_data_carries_the_store_contact_and_the_deposit(): void
    {
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true]);
        $this->store->update(['deposit_configuration' => ['enabled' => true, 'percentage' => '25.00', 'minimum' => '0.00', 'scope' => 'all', 'confirm_after_payment' => false, 'version' => 1]]);
        $id = $this->postJson(self::HOST.'/checkout', ['cart_mode' => 'direct', 'items' => [['product_id' => $this->bag->id, 'quantity' => 1]], 'customer_name' => 'Buyer', 'customer_phone' => '01000000000', 'customer_email' => 'buyer@example.test',
            'shipping_address' => ['line1' => 'Street', 'city' => 'Cairo', 'country' => 'EG', 'phone_alt' => '01200000000'], 'payment_method' => 'bank_transfer'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/my-store/orders/print-data?ids[]='.$id, $this->headers())->assertOk()
            ->assertJsonPath('store.phone', '+201000000009')->assertJsonPath('store.website', 'https://switch.sellchase.com/')
            ->assertJsonPath('data.0.deposit.amount', '30.00')->assertJsonPath('data.0.deposit.remaining', '90.00');
    }

    public function test_a_switch_waits_for_an_open_provider_payment_or_a_receipt_under_review(): void
    {
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true]);
        $order = $this->failedOnlineOrder();
        $hosted = StorePaymentTransaction::create(['store_id' => $this->store->id, 'store_order_id' => $order->id, 'gateway' => 'stripe', 'idempotency_key' => (string) Str::uuid(),
            'status' => 'redirect_pending', 'amount' => '132.00', 'currency' => 'EGP']);
        $this->getJson($this->url($order->id, 'methods'), $this->headers())->assertOk()->assertJsonPath('data.blocker', 'payment_pending')->assertJsonPath('data.methods', []);
        $this->postJson($this->url($order->id, 'method'), ['payment_method' => 'cod'], $this->headers())->assertUnprocessable()->assertJsonPath('errors.reason.0', 'payment_pending');
        // Past the abandoned-payment window the customer is no longer paying.
        StorePaymentTransaction::whereKey($hosted->id)->update(['updated_at' => now()->subMinutes(121)]);
        $this->getJson($this->url($order->id, 'methods'), $this->headers())->assertOk()->assertJsonPath('data.blocker', null);
        DB::table('store_payment_proofs')->insert(['id' => (string) Str::uuid(), 'store_id' => $this->store->id, 'store_order_id' => $order->id, 'path' => 'proofs/x.png', 'mime' => 'image/png',
            'size_bytes' => 10, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson($this->url($order->id, 'method'), ['payment_method' => 'cod'], $this->headers())->assertUnprocessable()->assertJsonPath('errors.reason.0', 'receipt_pending');
        $this->assertSame('stripe', StoreOrder::findOrFail($order->id)->payment_method);
    }

    public function test_a_late_provider_payment_after_a_switch_is_recorded_as_needing_a_refund(): void
    {
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true]);
        $order = $this->failedOnlineOrder();
        $this->postJson($this->url($order->id, 'method'), ['payment_method' => 'cod'], $this->headers())->assertOk();
        $old = StorePaymentTransaction::where('store_order_id', $order->id)->where('gateway', 'stripe')->firstOrFail();
        app(PaymentSettlement::class)->transition($old, 'paid', 'pi_late');
        $old->refresh();
        $this->assertSame('paid', $old->status);
        $this->assertTrue($old->metadata['unapplied']['needs_refund']);
        $order->refresh();
        $this->assertSame(['cod', 'pending'], [$order->payment_method, $order->payment_status]);
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $order->id, 'source' => 'unapplied_payment']);
        $this->getJson('/api/v1/my-store/orders/'.$order->id, $this->headers())->assertOk()
            ->assertJsonPath('data.unapplied_payments.0.amount', '132.00')->assertJsonPath('data.unapplied_payments.0.reference', 'pi_late');
        // The recorded capture blocks further switches; a repeated confirmation changes nothing.
        app(PaymentSettlement::class)->transition($old->fresh(), 'paid', 'pi_late');
        $this->assertSame(1, $order->statusChanges()->where('source', 'unapplied_payment')->count());
        $this->getJson($this->url($order->id, 'methods'), $this->headers())->assertOk()->assertJsonPath('data.blocker', 'payment_in_progress');
    }

    public function test_the_cod_fee_tax_uses_the_order_frozen_tax_basis(): void
    {
        $this->store->update(['tax_enabled' => true, 'tax_rate' => '10', 'tax_prices_include' => false, 'tax_shipping' => true]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true]);
        $this->postJson('/api/v1/my-store/shipping/zones', ['name' => ['ar' => 'مصر', 'en' => 'Egypt'], 'countries' => ['EG'], 'regions' => [], 'enabled' => true,
            'rates' => [['name' => ['ar' => 'عادي', 'en' => 'Standard'], 'type' => 'flat', 'price' => '30.00', 'free_over' => null, 'delivery_time' => ['ar' => '', 'en' => ''], 'cod_fee' => '15.00', 'enabled' => true]]], $this->headers())->assertCreated();
        $id = $this->postJson(self::HOST.'/checkout', ['cart_mode' => 'direct', 'items' => [['product_id' => $this->bag->id, 'quantity' => 1]], 'customer_name' => 'Buyer', 'customer_phone' => '01000000000', 'customer_email' => 'buyer@example.test',
            'shipping_country' => 'EG', 'shipping_address' => ['line1' => 'Street', 'city' => 'Cairo', 'country' => 'EG'], 'payment_method' => 'cod'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.tax_total', '14.50')->assertJsonPath('data.grand_total', '159.50')->json('data.id');
        $this->assertSame('10.000', StoreOrder::findOrFail($id)->shipping_address['shipping_details']['tax']['rate']);
        // Today's rate changed; the order keeps its own.
        $this->store->update(['tax_rate' => '20']);
        $this->postJson($this->url($id, 'method'), ['payment_method' => 'bank_transfer', 'expected_grand_total' => '143.00'], $this->headers())->assertOk()
            ->assertJsonPath('data.tax_total', '13.00')->assertJsonPath('data.shipping_total', '30.00')->assertJsonPath('data.grand_total', '143.00');
    }
}
