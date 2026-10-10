<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCarrierConnection;
use App\Models\StoreOrder;
use App\Models\StoreShipment;
use App\Models\StoreShipmentEvent;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StoreCarrierTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private StoreOrder $order;

    private string $base = '/api/v1/my-store';

    private array $input = ['city_id' => 'cairo', 'district_id' => 'maadi', 'address_line' => '12 Local review street', 'package_size' => 'MEDIUM', 'description' => 'Two bags'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $user->id, 'owner_type' => 'merchant', 'name' => 'Carrier test', 'slug' => 'carrier', 'currency' => 'EGP', 'status' => 'active']);
        app(CurrentStore::class)->set($this->store);
        $product = Product::create(['store_id' => $this->store->id, 'user_id' => $user->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true]);
        $this->order = StoreOrder::create(['store_id' => $this->store->id, 'order_number' => 'LOCAL-1', 'status' => 'confirmed', 'currency' => 'EGP', 'customer_name' => 'Local Buyer', 'customer_phone' => '+20 100 000 0000', 'customer_email' => 'local@example.test', 'shipping_address' => ['country' => 'EG', 'line1' => 'Original address'], 'payment_method' => 'cod', 'payment_status' => 'pending', 'subtotal' => 200, 'discount_total' => 25, 'shipping_total' => 35, 'tax_total' => 0, 'grand_total' => 210]);
        $this->order->items()->create(['store_id' => $this->store->id, 'store_product_id' => $product->id, 'name' => 'Bag', 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200]);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    private function connection(): StoreCarrierConnection
    {
        return StoreCarrierConnection::create(['store_id' => $this->store->id, 'carrier' => 'bosta', 'api_key' => 'local-test-secret', 'enabled' => true, 'pickup_location_id' => 'pickup-1', 'pickup_locations' => [['id' => 'pickup-1', 'name' => 'Warehouse', 'is_default' => true]], 'verified_at' => now()]);
    }

    private function fake(mixed $delivery = null): void
    {
        Http::fake(function ($request) use ($delivery) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/v2/cities') {
                return Http::response(['success' => true, 'data' => ['list' => [['_id' => 'cairo', 'name' => 'Cairo', 'nameAr' => 'القاهرة', 'dropOffAvailability' => true]]]]);
            }
            if ($path === '/api/v2/cities/cairo/districts') {
                return Http::response(['success' => true, 'data' => [['districtId' => 'maadi', 'districtName' => 'Maadi', 'districtOtherName' => 'المعادي', 'dropOffAvailability' => true], ['districtId' => 'closed', 'districtName' => 'Closed', 'dropOffAvailability' => false]]]);
            }
            if ($path === '/api/v2/pickup-locations') {
                return Http::response(['success' => true, 'data' => ['list' => [['_id' => 'pickup-1', 'locationName' => 'Warehouse', 'isDefault' => true]]]]);
            }
            if (str_starts_with($path, '/api/v2/deliveries')) {
                if ($delivery) {
                    return $delivery($request);
                }

                return Http::response(['success' => true, 'data' => ['_id' => 'delivery-1', 'trackingNumber' => '123456', 'businessReference' => $request['businessReference'] ?? StoreShipment::firstOrFail()->business_reference, 'state' => ['code' => 10, 'value' => 'Pickup requested']]]);
            }
            throw new \RuntimeException('Unexpected carrier endpoint');
        });
    }

    private function endpoint(): string
    {
        return $this->base.'/orders/'.$this->order->id.'/shipment';
    }

    public function test_connection_key_is_encrypted_hidden_and_requires_verification_before_enabling(): void
    {
        $this->fake();
        $url = $this->base.'/carriers/bosta';
        $this->putJson($url, ['api_key' => 'local-test-secret', 'enabled' => true])->assertOk()->assertJsonPath('data.enabled', false)->assertJsonMissing(['api_key' => 'local-test-secret']);
        $this->assertNotSame('local-test-secret', DB::table('store_carrier_connections')->value('api_key'));
        $this->putJson($url, ['enabled' => true, 'pickup_location_id' => 'pickup-1'])->assertUnprocessable();
        $this->postJson($url.'/verify')->assertOk()->assertJsonPath('data.pickup_location_id', 'pickup-1');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/pickup-locations') && $r->hasHeader('Authorization', 'local-test-secret'));
        $this->putJson($url, ['enabled' => true, 'pickup_location_id' => 'foreign'])->assertUnprocessable();
        $this->putJson($url, ['enabled' => true, 'pickup_location_id' => 'pickup-1'])->assertOk()->assertJsonPath('data.enabled', true);
        $this->getJson($url)->assertOk()->assertJsonPath('data.has_api_key', true)->assertDontSee('local-test-secret');
        $this->putJson($url, ['api_key' => 'replacement-secret', 'enabled' => true, 'pickup_location_id' => 'pickup-1'])->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.verified_at', null);
    }

    public function test_dispatch_uses_order_totals_and_fixed_carrier_contract_and_blocks_duplicates(): void
    {
        $this->connection();
        $this->fake();
        $this->postJson($this->endpoint(), $this->input + ['cod' => 1, 'store_id' => 999])->assertCreated()->assertJsonPath('data.tracking_number', '123456')->assertJsonMissingPath('data.request_snapshot');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://app.bosta.co/api/v2/deliveries?apiVersion=1' && $r['cod'] === 210 && $r['specs']['packageDetails']['itemsCount'] === 2 && $r['receiver']['phone'] === '01000000000' && $r['dropOffAddress']['city'] === 'Cairo' && $r['businessLocationId'] === 'pickup-1' && $r['uniqueBusinessReference'] === $r['businessReference']);
        $this->postJson($this->endpoint(), $this->input)->assertConflict();
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
        $this->assertStringNotContainsString('Local Buyer', DB::table('store_shipments')->value('request_snapshot'));
        $this->assertSame('Original address', $this->order->fresh()->shipping_address['line1']);
        $this->assertSame('210.00', $this->order->fresh()->grand_total);
    }

    public function test_timeout_claim_is_durable_and_can_only_reconcile_with_its_own_reference(): void
    {
        $this->connection();
        $this->fake(fn () => throw new ConnectionException('upstream-secret-must-not-leak'));
        $this->postJson($this->endpoint(), $this->input)->assertStatus(502)->assertJsonPath('ambiguous', true)->assertDontSee('upstream-secret');
        $this->assertDatabaseHas('store_shipments', ['status' => 'unknown']);
        $this->postJson($this->endpoint(), $this->input)->assertConflict();
        // Rebind fake independently; Laravel fake callbacks append, so replace the factory.
        Http::swap(new Factory);
        $this->fake(fn () => Http::response(['success' => true, 'data' => ['_id' => 'delivery-1', 'trackingNumber' => '123456', 'businessReference' => 'another-order', 'state' => ['code' => 45, 'value' => 'Delivered']]]));
        $this->postJson($this->endpoint().'/sync', ['tracking_number' => '123456'])->assertStatus(502);
        $this->assertDatabaseHas('store_shipments', ['status' => 'unknown', 'tracking_number' => null]);
        Http::swap(new Factory);
        $this->fake();
        $this->postJson($this->endpoint().'/sync', ['tracking_number' => '123456'])->assertOk()->assertJsonPath('data.status', 'created');
        $this->postJson($this->endpoint(), $this->input)->assertConflict();
    }

    public function test_definite_rejection_can_be_corrected_and_retried_with_same_reference(): void
    {
        $this->connection();
        $this->fake(fn () => Http::response(['message' => 'private upstream details'], 422));
        $this->postJson($this->endpoint(), $this->input)->assertStatus(502)->assertJsonPath('ambiguous', false)->assertDontSee('private upstream');
        $reference = StoreShipment::firstOrFail()->business_reference;
        Http::swap(new Factory);
        $this->fake();
        $this->postJson($this->endpoint(), $this->input)->assertCreated()->assertJsonPath('data.business_reference', $reference);
        $this->assertDatabaseCount('store_shipments', 1);
    }

    public function test_invalid_orders_and_foreign_districts_never_create_a_shipment(): void
    {
        $this->connection();
        $this->fake();
        foreach ([['district_id' => 'foreign'], ['district_id' => 'closed'], ['address_line' => 'short']] as $invalid) {
            $this->postJson($this->endpoint(), array_replace($this->input, $invalid))->assertUnprocessable();
        }
        foreach ([['status' => 'cancelled'], ['status' => 'pending'], ['currency' => 'USD'], ['payment_method' => 'stripe'], ['payment_status' => 'refunded'], ['grand_total' => '30000.01'], ['customer_phone' => 'invalid']] as $invalid) {
            $original = $this->order->getAttributes();
            $this->order->update($invalid);
            $this->postJson($this->endpoint(), $this->input)->assertUnprocessable();
            $this->order->setRawAttributes($original)->save();
        }
        $this->assertDatabaseCount('store_shipments', 0);
        $this->assertCount(0, Http::recorded(fn ($r) => $r->method() === 'POST'));
    }

    public function test_paid_orders_do_not_collect_money_again_and_sync_does_not_reprice_or_mark_paid(): void
    {
        $this->connection();
        $this->fake();
        $this->order->update(['payment_status' => 'paid', 'payment_method' => 'stripe']);
        $this->postJson($this->endpoint(), $this->input)->assertCreated();
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['cod'] === 0);
        Http::swap(new Factory);
        $this->fake(fn () => Http::response(['success' => true, 'data' => ['_id' => 'delivery-1', 'trackingNumber' => '123456', 'businessReference' => StoreShipment::firstOrFail()->business_reference, 'state' => ['code' => 45, 'value' => 'Delivered'], 'cod' => 999, 'shipmentFees' => 500]]));
        $this->postJson($this->endpoint().'/sync')->assertOk()->assertJsonPath('data.carrier_state', 45);
        $this->assertSame('210.00', $this->order->fresh()->grand_total);
        $this->assertSame('delivered', $this->order->fresh()->status);
        $this->assertSame('paid', $this->order->fresh()->payment_status);
    }

    public function test_another_store_cannot_read_keys_dispatch_or_reconcile_orders(): void
    {
        $this->connection();
        $this->fake();
        $this->postJson($this->endpoint(), $this->input)->assertCreated();
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        Store::create(['owner_user_id' => $other->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other', 'currency' => 'EGP', 'status' => 'active']);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($other));
        $this->getJson($this->base.'/carriers/bosta')->assertOk()->assertJsonPath('data.has_api_key', false);
        $this->getJson($this->endpoint())->assertNotFound();
        $this->postJson($this->endpoint(), $this->input)->assertNotFound();
        $this->postJson($this->endpoint().'/sync', ['tracking_number' => '123456'])->assertNotFound();
        $this->postJson($this->endpoint().'/label', ['size' => 'A4', 'language' => 'ar'])->assertNotFound();
        $this->getJson('/api/v1/stores/'.$this->store->id.'/carriers/bosta')->assertForbidden();
    }

    public function test_employee_permissions_are_checked_for_settings_and_shipments_independently(): void
    {
        $this->connection();
        $this->fake();
        $this->getJson('/api/v1/stores/'.$this->store->id.'/carriers/bosta')->assertOk();
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($employee));
        $this->getJson($this->base.'/carriers/bosta')->assertForbidden();
        $this->putJson($this->base.'/carriers/bosta', ['enabled' => false])->assertForbidden();
        $this->postJson($this->base.'/carriers/bosta/verify')->assertForbidden();
        $this->getJson($this->endpoint())->assertForbidden();
        $this->postJson($this->endpoint(), $this->input)->assertForbidden();
        $employee->givePermissionTo('store.orders.manage');
        $this->travel(31)->seconds();
        $this->getJson($this->endpoint())->assertOk();
        $this->postJson($this->endpoint(), $this->input)->assertCreated();
        $this->getJson($this->base.'/carriers/bosta')->assertForbidden();
        $employee->givePermissionTo('store.settings.manage');
        $this->travel(31)->seconds();
        $this->getJson($this->base.'/carriers/bosta')->assertOk()->assertDontSee('local-test-secret');
    }

    public function test_claim_is_committed_before_network_call_and_bad_success_is_not_retryable(): void
    {
        $this->connection();
        $this->fake(function () {
            $this->assertSame(1, DB::transactionLevel());
            $this->assertDatabaseHas('store_shipments', ['store_order_id' => $this->order->id, 'status' => 'submitting']);
            $this->postJson($this->endpoint(), $this->input)->assertConflict();

            return Http::response(['success' => true, 'data' => ['_id' => 'bad-response']]);
        });
        // RefreshDatabase holds the whole test inside one transaction; the service must close its own nested transaction.
        $level = DB::transactionLevel();
        $this->assertSame(1, $level);
        $this->postJson($this->endpoint(), $this->input)->assertStatus(502)->assertJsonPath('ambiguous', true);
        $this->assertDatabaseHas('store_shipments', ['status' => 'unknown']);
        $this->postJson($this->endpoint(), $this->input)->assertConflict();
    }

    private function webhook(StoreShipment $shipment, array $extra = [], ?string $secret = null): TestResponse
    {
        return $this->postJson('/api/v1/carriers/bosta/webhook/'.$shipment->business_reference, array_replace([
            '_id' => 'delivery-1', 'trackingNumber' => 123456, 'state' => 45, 'type' => 'SEND',
            'timeStamp' => now()->getTimestampMs(), 'businessReference' => $shipment->business_reference,
        ], $extra), ['Authorization' => 'Bearer '.($secret ?? $shipment->webhook_secret)]);
    }

    private function dispatchWithWebhook(): StoreShipment
    {
        config(['services.bosta.webhook_base_url' => 'https://carrier-receiver.example.test']);
        $this->connection();
        $this->fake();
        $this->postJson($this->endpoint(), $this->input)->assertCreated()->assertJsonPath('data.webhook_configured', true)->assertJsonMissingPath('data.webhook_secret');

        return StoreShipment::firstOrFail();
    }

    public function test_authenticated_webhooks_advance_fulfillment_without_marking_cod_paid(): void
    {
        $shipment = $this->dispatchWithWebhook();
        $secret = $shipment->webhook_secret;
        $this->assertNotSame($secret, DB::table('store_shipments')->value('webhook_secret'));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['webhookUrl'] === 'https://carrier-receiver.example.test/api/v1/carriers/bosta/webhook/'.$shipment->business_reference
            && $request['webhookCustomHeaders']['Authorization'] === 'Bearer '.$secret);
        $this->webhook($shipment, ['state' => 21, 'timeStamp' => now()->getTimestampMs() - 2000])->assertOk();
        $this->assertSame('shipped', $this->order->fresh()->status);
        $this->webhook($shipment, ['cod' => 999])->assertOk();
        $this->assertSame('delivered', $this->order->fresh()->status);
        $this->assertSame('pending', $this->order->fresh()->payment_status);
        $this->assertSame('210.00', $this->order->fresh()->grand_total);
        $this->assertDatabaseCount('store_order_status_changes', 3);
        $this->assertDatabaseHas('store_order_status_changes', ['store_id' => $this->store->id, 'to_status' => 'delivered', 'source' => 'carrier', 'actor_id' => null]);
        $this->getJson($this->endpoint())->assertOk()->assertJsonPath('data.carrier_state', 45)->assertJsonCount(3, 'data.events')->assertDontSee($secret);
    }

    public function test_duplicate_old_and_same_time_conflicting_events_cannot_rewind_the_order(): void
    {
        $shipment = $this->dispatchWithWebhook();
        $time = now()->getTimestampMs() - 1000;
        $this->webhook($shipment, ['timeStamp' => $time])->assertOk();
        $this->webhook($shipment, ['timeStamp' => $time])->assertOk();
        $this->assertDatabaseCount('store_shipment_events', 2);
        $this->webhook($shipment, ['state' => 41, 'timeStamp' => $time - 1000])->assertOk();
        $this->webhook($shipment, ['state' => 10, 'timeStamp' => $time])->assertOk();
        $this->assertSame(45, $shipment->fresh()->carrier_state);
        $this->assertSame('delivered', $this->order->fresh()->status);
        $this->assertDatabaseCount('store_order_status_changes', 3);
        $this->assertSame(2, StoreShipmentEvent::where('applied', false)->where('ignored_reason', 'terminal_state')->count());
    }

    public function test_invalid_auth_identity_type_and_future_events_do_not_mutate_shipments(): void
    {
        $shipment = $this->dispatchWithWebhook();
        $this->webhook($shipment, [], 'wrong-secret')->assertUnauthorized();
        $this->webhook($shipment, ['_id' => 'foreign-delivery'])->assertConflict();
        $this->webhook($shipment, ['businessReference' => 'another-order'])->assertConflict();
        $this->webhook($shipment, ['trackingNumber' => '987654'])->assertConflict();
        $this->webhook($shipment, ['type' => 'CUSTOMER_RETURN_PICKUP'])->assertUnprocessable();
        $this->webhook($shipment, ['timeStamp' => now()->getTimestampMs() + 600000])->assertUnprocessable();
        $this->assertSame(10, $shipment->fresh()->carrier_state);
        $this->assertDatabaseCount('store_shipment_events', 1);
        $this->assertDatabaseCount('store_order_status_changes', 0);
    }

    public function test_webhook_can_confirm_an_unknown_creation_and_wins_over_a_late_timeout(): void
    {
        config(['services.bosta.webhook_base_url' => 'https://carrier-receiver.example.test']);
        $this->connection();
        $attempts = 0;
        $this->fake(function () use (&$attempts) {
            $attempts++;
            $this->webhook(StoreShipment::firstOrFail(), ['businessReference' => null, 'state' => 21])->assertOk();
            throw new ConnectionException('The create response was lost');
        });
        $this->postJson($this->endpoint(), $this->input)->assertCreated()->assertJsonPath('data.status', 'created')->assertJsonPath('data.carrier_state', 21);
        $this->assertSame('shipped', $this->order->fresh()->status);
        $this->postJson($this->endpoint(), $this->input)->assertConflict();
        $this->assertSame(1, $attempts);
    }

    public function test_untimed_or_stale_reads_cannot_replace_a_newer_webhook_state(): void
    {
        $shipment = $this->dispatchWithWebhook();
        $this->webhook($shipment)->assertOk();
        $this->postJson($this->endpoint().'/sync')->assertOk()->assertJsonPath('data.carrier_state', 45);
        Http::swap(new Factory);
        $this->fake(fn () => Http::response(['success' => true, 'data' => ['_id' => 'delivery-1', 'trackingNumber' => '123456', 'businessReference' => $shipment->business_reference, 'state' => ['code' => 41], 'updatedAt' => now()->subMinute()->toIso8601String()]]));
        $this->postJson($this->endpoint().'/sync')->assertOk()->assertJsonPath('data.carrier_state', 45);
        $this->assertSame('delivered', $this->order->fresh()->status);
    }

    public function test_webhooks_preserve_cancelled_orders_and_restore_the_previous_tenant(): void
    {
        $shipment = $this->dispatchWithWebhook();
        $this->order->update(['status' => 'cancelled']);
        app(CurrentStore::class)->forget();
        $this->webhook($shipment)->assertOk();
        $this->assertNull(app(CurrentStore::class)->id());
        $this->assertSame('cancelled', StoreOrder::withoutGlobalScopes()->findOrFail($this->order->id)->status);
        $this->assertDatabaseCount('store_order_status_changes', 0);
    }

    public function test_awb_is_tenant_scoped_validated_pdf_and_never_an_email_or_external_url(): void
    {
        $this->dispatchWithWebhook();
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";
        Http::swap(new Factory);
        $this->fake(fn ($request) => Http::response(['success' => true, 'data' => base64_encode($pdf)]));
        $this->postJson($this->endpoint().'/label', ['size' => 'A6', 'language' => 'ar'])->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Disposition', 'attachment; filename="bosta-123456.pdf"')->assertContent($pdf);
        Http::assertSent(fn ($request) => $request->url() === 'https://app.bosta.co/api/v2/deliveries/mass-awb' && $request['trackingNumbers'] === '123456' && $request['requestedAwbType'] === 'A6' && $request['lang'] === 'ar');
        $this->postJson($this->endpoint().'/label', ['size' => 'A0', 'language' => 'ar'])->assertUnprocessable();
        Http::swap(new Factory);
        $this->fake(fn () => Http::response(['success' => true, 'message' => 'exported to email']));
        $this->postJson($this->endpoint().'/label', ['size' => 'A4', 'language' => 'en'])->assertStatus(502);
        $this->webhook(StoreShipment::firstOrFail())->assertOk();
        $this->postJson($this->endpoint().'/label', ['size' => 'A4', 'language' => 'en'])->assertUnprocessable();
    }

    public function test_out_of_order_in_progress_events_are_recorded_without_regression_and_tenant_is_restored(): void
    {
        $shipment = $this->dispatchWithWebhook();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other tenant', 'slug' => 'other-tenant', 'currency' => 'EGP', 'status' => 'active']);
        app(CurrentStore::class)->set($other);
        $time = now()->getTimestampMs() - 1000;
        $this->webhook($shipment, ['state' => 41, 'timeStamp' => $time])->assertOk();
        $this->assertSame($other->id, app(CurrentStore::class)->id());
        $this->webhook($shipment, ['state' => 21, 'timeStamp' => $time - 1000])->assertOk();
        $this->assertSame(41, $shipment->fresh()->carrier_state);
        $this->assertDatabaseHas('store_shipment_events', ['store_id' => $this->store->id, 'carrier_state' => 21, 'applied' => false, 'ignored_reason' => 'older_event']);
        $this->assertDatabaseHas('store_order_status_changes', ['store_id' => $this->store->id, 'to_status' => 'shipped', 'source' => 'carrier']);
        $this->assertDatabaseMissing('store_order_status_changes', ['store_id' => $other->id]);
    }
}
