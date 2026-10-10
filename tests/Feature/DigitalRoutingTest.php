<?php

namespace Tests\Feature;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OutboxMessage;
use App\Models\Product;
use App\Models\ShippingCompany;
use App\Models\Store;
use App\Models\StoreCarrierConnection;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use App\Models\User;
use App\Notifications\OrderAssignedToSupplierNotification;
use App\Services\Commerce\DigitalProducts;
use App\Services\Commerce\StorefrontOrderBridge;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DigitalRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private User $supplier;

    private Product $digital;

    private Product $physical;

    private string $base = 'http://routing.sellchase.com/api/v1/storefront';

    private array $carrierInput = ['city_id' => 'cairo', 'district_id' => 'maadi', 'address_line' => 'Synthetic review street', 'package_size' => 'MEDIUM', 'description' => 'Physical bags'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        config()->set('services.sellchase_fallback_supplier_user_id', null);
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->supplier = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->supplier->assignRole('Supplier');
        $this->owner->suppliersAsMerchant()->attach($this->supplier->id, ['status' => 'accepted']);
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Routing store', 'slug' => 'routing', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'routing.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true, 'test_mode' => true]);
        $this->digital = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Private digital license', 'slug' => 'license', 'price' => 100, 'is_active' => true, 'digital_type' => 'codes', 'track_inventory' => true, 'stock_quantity' => 5]);
        app(DigitalProducts::class)->appendCodes($this->digital, ['PRIVATE-1', 'PRIVATE-2', 'PRIVATE-3', 'PRIVATE-4']);
        $this->physical = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Physical bag', 'slug' => 'bag', 'price' => 50, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 6]);
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($user)];
    }

    private function place(bool $mixed = false): array
    {
        $body = ['items' => [['product_id' => $this->digital->id, 'quantity' => 2]], 'customer_name' => 'Review buyer', 'customer_phone' => '01000000000', 'customer_email' => 'routing@example.test', 'payment_method' => 'bank_transfer'];
        if ($mixed) {
            $body['items'][] = ['product_id' => $this->physical->id, 'quantity' => 3];
            $body['shipping_address'] = ['line1' => 'Synthetic review street', 'city' => 'Cairo', 'country' => 'EG'];
        }

        return $this->postJson($this->base.'/checkout', $body)->assertCreated()->json();
    }

    private function confirm(int $id): TestResponse
    {
        return $this->postJson('/api/v1/my-store/orders/'.$id.'/payment/confirm-bank', ['reference' => 'LOCAL-ROUTING-'.$id], $this->headers($this->owner));
    }

    private function transition(int $id, string $status): TestResponse
    {
        return $this->patchJson('/api/v1/my-store/orders/'.$id.'/status', ['status' => $status], $this->headers($this->owner));
    }

    public function test_pure_digital_checkout_stays_in_store_and_never_fans_out_to_suppliers(): void
    {
        $data = $this->place();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_suppliers', 0);
        $this->assertSame(0, OutboxMessage::where('event_type', 'StorefrontOrderBridged')->count());
        Notification::assertNotSentTo($this->supplier, OrderAssignedToSupplierNotification::class);
        BridgeStorefrontOrderJob::dispatchSync($data['data']['id'], $this->store->id);
        $this->assertDatabaseCount('orders', 0);
        $this->getJson('/api/v1/my-store/orders/'.$data['data']['id'], $this->headers($this->owner))->assertOk()
            ->assertJsonPath('data.fulfillment.type', 'digital')->assertJsonPath('data.fulfillment.requires_shipping', false)
            ->assertJsonPath('data.fulfillment.digital_quantity', 2)->assertJsonPath('data.b2b_order', null)->assertJsonPath('data.next_statuses', ['confirmed', 'cancelled']);
    }

    public function test_mixed_checkout_bridges_only_physical_snapshot_quantity_and_syncs_full_customer_payment(): void
    {
        $data = $this->place(true);
        $order = Order::where('store_order_id', $data['data']['id'])->firstOrFail();
        $this->assertSame($this->physical->id, (int) $order->product_id);
        $this->assertSame(3, (int) $order->quantity);
        $this->assertCount(1, $order->storefront_items);
        $this->assertSame('Physical bag', $order->storefront_items[0]['name']);
        $this->assertSame('physical', $order->storefront_items[0]['fulfillment_type']);
        $this->assertSame([], $order->storefront_items[0]['personalization']);
        $this->assertStringNotContainsString('PRIVATE-', $order->getRawOriginal('storefront_items'));
        $this->assertStringNotContainsString('Private digital license', $order->getRawOriginal('storefront_items'));
        Notification::assertSentTo($this->supplier, OrderAssignedToSupplierNotification::class, 1);
        $this->getJson('/api/v1/orders/'.$order->code, $this->headers($this->supplier))->assertOk()
            ->assertDontSee('PRIVATE-')->assertDontSee('Private digital license')
            ->assertJsonPath('data.quantity', 3)->assertJsonCount(1, 'data.storefront_items')
            ->assertJsonPath('data.storefront_items.0.personalization', [])
            ->assertJsonPath('data.storefront_fulfillment.type', 'mixed')->assertJsonPath('data.storefront_fulfillment.physical_quantity', 3);
        $legacyItems = $order->storefront_items;
        $legacyItems[0]['personalization'] = null;
        $order->update(['storefront_items' => $legacyItems]);
        $this->getJson('/api/v1/orders/'.$order->code, $this->headers($this->supplier))->assertOk()
            ->assertJsonPath('data.storefront_items.0.personalization', []);
        $this->confirm($data['data']['id'])->assertOk();
        $this->assertSame($data['data']['grand_total'], $order->fresh()->paid_amount);
        $this->assertSame('LOCAL-ROUTING-'.$data['data']['id'], $order->fresh()->payment_transaction_id);
        BridgeStorefrontOrderJob::dispatchSync($data['data']['id'], $this->store->id);
        $this->assertDatabaseCount('orders', 1);
        Notification::assertSentTo($this->supplier, OrderAssignedToSupplierNotification::class, 1);
    }

    public function test_pure_digital_shipment_is_rejected_before_any_carrier_network_call_even_with_legacy_address(): void
    {
        $id = $this->place()['data']['id'];
        StoreOrder::forStore($this->store->id)->whereKey($id)->update(['status' => 'confirmed', 'payment_status' => 'paid', 'shipping_address' => ['country' => 'EG']]);
        $this->postJson('/api/v1/my-store/orders/'.$id.'/shipment', $this->carrierInput, $this->headers($this->owner))->assertUnprocessable()->assertJsonValidationErrors('order');
        Http::assertNothingSent();
        $this->assertDatabaseCount('store_shipments', 0);
    }

    public function test_mixed_carrier_payload_counts_only_physical_units_and_never_contains_codes(): void
    {
        $id = $this->place(true)['data']['id'];
        $this->confirm($id)->assertOk();
        $this->transition($id, 'delivered')->assertUnprocessable();
        $this->transition($id, 'confirmed')->assertOk();
        StoreCarrierConnection::create(['store_id' => $this->store->id, 'carrier' => 'bosta', 'api_key' => 'synthetic-secret', 'enabled' => true, 'pickup_location_id' => 'pickup-1', 'verified_at' => now()]);
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/v2/cities') {
                return Http::response(['success' => true, 'data' => ['list' => [['_id' => 'cairo', 'name' => 'Cairo', 'dropOffAvailability' => true]]]]);
            }
            if ($path === '/api/v2/cities/cairo/districts') {
                return Http::response(['success' => true, 'data' => [['districtId' => 'maadi', 'districtName' => 'Maadi', 'dropOffAvailability' => true]]]);
            }

            return Http::response(['success' => true, 'data' => ['_id' => 'delivery-1', 'trackingNumber' => '123456', 'businessReference' => $request['businessReference'], 'state' => ['code' => 10]]]);
        });
        $this->postJson('/api/v1/my-store/orders/'.$id.'/shipment', $this->carrierInput + ['items_count' => 999], $this->headers($this->owner))->assertCreated();
        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST') {
                return false;
            }
            $this->assertSame(3, $request['specs']['packageDetails']['itemsCount']);
            $this->assertSame(0, $request['cod']);
            $this->assertStringNotContainsString('PRIVATE-', $request->body());

            return true;
        });
    }

    public function test_paid_pure_digital_can_complete_without_shipping_and_commits_tracked_units_once(): void
    {
        $id = $this->place()['data']['id'];
        $this->transition($id, 'delivered')->assertUnprocessable();
        $this->transition($id, 'confirmed')->assertOk();
        $this->transition($id, 'processing')->assertUnprocessable();
        $this->transition($id, 'shipped')->assertUnprocessable();
        $this->confirm($id)->assertOk()->assertJsonPath('data.next_statuses', ['delivered', 'cancelled']);
        $this->transition($id, 'delivered')->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.next_statuses', []);
        $this->transition($id, 'delivered')->assertUnprocessable();
        $this->assertSame(3, $this->digital->fresh()->stock_quantity);
        $this->assertSame(0, $this->digital->fresh()->reserved_quantity);
        $this->assertDatabaseHas('store_inventory_movements', ['product_id' => $this->digital->id, 'reason' => 'digital_committed', 'stock_delta' => -2]);
        $this->assertSame(0, OutboxMessage::where('event_type', 'StorefrontOrderBridged')->count());
        $this->assertSame(1, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
    }

    public function test_deleted_catalog_and_other_tenant_context_do_not_change_snapshot_shipping_classification(): void
    {
        $data = $this->place();
        app(CurrentStore::class)->set($this->store);
        $this->digital->delete();
        app(CurrentStore::class)->forget();
        $order = StoreOrder::forStore($this->store->id)->findOrFail($data['data']['id']);
        $this->assertFalse($order->requiresShipping());
        $this->postJson($this->base.'/checkout/receipt', ['token' => $data['receipt']['token']])->assertOk()->assertJsonPath('data.fulfillment.requires_shipping', false);
        $foreign = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Foreign', 'slug' => 'foreign-routing', 'status' => 'active']);
        $this->expectException(ValidationException::class);
        app(StorefrontOrderBridge::class)->bridge($order, $foreign);
    }

    public function test_legacy_pure_digital_b2b_row_is_retained_but_cannot_create_a_delivery(): void
    {
        $id = $this->place()['data']['id'];
        $legacy = Order::create(['code' => 'LEGACY-DIGITAL', 'quantity' => 2, 'product_id' => $this->digital->id, 'user_id' => $this->owner->id,
            'attributes' => 'a:0:{}', 'notes' => '', 'status' => 'pending', 'source' => Order::SOURCE_STOREFRONT, 'store_id' => $this->store->id, 'store_order_id' => $id, 'storefront_items' => []]);
        $this->getJson('/api/v1/orders/'.$legacy->code, $this->headers($this->owner))->assertOk()->assertJsonPath('data.storefront_fulfillment.requires_shipping', false);
        $company = ShippingCompany::create(['name' => 'Review carrier', 'code' => 'manual', 'is_active' => true]);
        $this->owner->givePermissionTo('deliveries-update');
        $this->postJson('/api/v1/deliveries', ['order_code' => $legacy->code, 'shipping_company_id' => $company->id], $this->headers($this->owner))
            ->assertUnprocessable()->assertJsonValidationErrors('order');
        try {
            OrderDelivery::create(['order_id' => $legacy->id, 'delivery_company' => 'manual', 'status' => 'pending']);
            $this->fail('Legacy digital order accepted a delivery.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }
        $this->assertDatabaseHas('orders', ['id' => $legacy->id]);
        $this->assertDatabaseCount('order_deliveries', 0);
    }

    public function test_supplier_owned_pure_digital_order_does_not_self_route_to_procurement(): void
    {
        $this->owner->syncRoles(['Supplier']);
        $data = $this->place();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_suppliers', 0);
        $this->confirm($data['data']['id'])->assertOk();
        $this->transition($data['data']['id'], 'delivered')->assertOk();
    }

    public function test_verified_provider_payment_updates_mixed_b2b_metadata_without_ambient_tenant(): void
    {
        $data = $this->place(true);
        $id = $data['data']['id'];
        StoreOrder::forStore($this->store->id)->whereKey($id)->update(['payment_method' => 'stripe']);
        $transaction = StorePaymentTransaction::where('store_order_id', $id)->firstOrFail();
        $transaction->update(['gateway' => 'stripe', 'provider_reference' => 'cs_routing']);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'stripe', 'enabled' => true, 'credentials' => ['webhook_secret' => 'whsec_routing']]);
        $json = json_encode(['id' => 'evt_routing', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_routing', 'payment_status' => 'paid', 'payment_intent' => 'pi_routing',
            'metadata' => ['transaction_id' => $transaction->id, 'store_order_id' => $id]]]], JSON_THROW_ON_ERROR);
        $time = time();
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$json, 'whsec_routing')];
        app(CurrentStore::class)->forget();
        $url = '/api/v1/payment-webhooks/'.$this->store->id.'/stripe';
        $this->call('POST', $url, [], [], [], $headers, $json)->assertOk();
        $this->call('POST', $url, [], [], [], $headers, $json)->assertOk()->assertJsonPath('duplicate', true);
        $bridge = Order::where('store_id', $this->store->id)->where('store_order_id', $id)->firstOrFail();
        $this->assertSame($data['data']['grand_total'], $bridge->paid_amount);
        $this->assertSame('pi_routing', $bridge->payment_transaction_id);
        $this->assertSame(3, $bridge->storefrontFulfillment()['physical_quantity']);
        $this->assertSame(1, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
    }
}
