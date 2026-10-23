<?php

namespace Tests\Feature;

use App\Models\OutboxMessage;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCarrierConnection;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use App\Models\User;
use App\Services\Commerce\DigitalProducts;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DigitalRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

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
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
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

    public function test_pure_digital_checkout_stays_in_store(): void
    {
        $data = $this->place();
        $this->getJson('/api/v1/my-store/orders/'.$data['data']['id'], $this->headers($this->owner))->assertOk()
            ->assertJsonPath('data.fulfillment.type', 'digital')->assertJsonPath('data.fulfillment.requires_shipping', false)
            ->assertJsonPath('data.fulfillment.digital_quantity', 2)->assertJsonPath('data.next_statuses', ['confirmed', 'cancelled']);
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
    }

    public function test_verified_provider_payment_settles_mixed_order_without_ambient_tenant(): void
    {
        $data = $this->place(true);
        $id = $data['data']['id'];
        StoreOrder::forStore($this->store->id)->whereKey($id)->update(['payment_method' => 'stripe']);
        $transaction = StorePaymentTransaction::where('store_order_id', $id)->firstOrFail();
        $transaction->update(['gateway' => 'stripe', 'provider_reference' => 'cs_routing']);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'stripe', 'enabled' => true, 'credentials' => ['webhook_secret' => 'whsec_routing']]);
        $json = json_encode(['id' => 'evt_routing', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_routing', 'payment_status' => 'paid', 'payment_intent' => 'pi_routing',
            'amount_total' => (int) bcmul((string) $transaction->amount, '100', 0), 'currency' => strtolower($transaction->currency),
            'metadata' => ['transaction_id' => $transaction->id, 'store_order_id' => $id]]]], JSON_THROW_ON_ERROR);
        $time = time();
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$json, 'whsec_routing')];
        app(CurrentStore::class)->forget();
        $url = '/api/v1/payment-webhooks/'.$this->store->id.'/stripe';
        $this->call('POST', $url, [], [], [], $headers, $json)->assertOk();
        $this->call('POST', $url, [], [], [], $headers, $json)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame('paid', StoreOrder::forStore($this->store->id)->findOrFail($id)->payment_status);
        $this->assertSame(1, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
    }
}
