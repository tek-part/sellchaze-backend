<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StoreOrderWebhook;
use App\Models\StoreOrderWebhookDelivery;
use App\Models\StorePaymentGateway;
use App\Models\User;
use App\Services\Commerce\StoreOrderService;
use App\Services\Commerce\StoreOrderWebhooks;
use App\Services\Commerce\WebhookDestination;
use App\Services\JwtTokenService;
use App\Services\PlatformWallet;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\StorePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreOrderWebhooksTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private Product $product;

    private WebhookDestination $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class, StorePermissionsSeeder::class]);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Webhook fixture', 'slug' => 'webhook-fixture', 'status' => 'active', 'currency' => 'EGP']);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Webhook fixture product', 'slug' => 'webhook-product', 'price' => 250, 'is_active' => true]);
        $this->destination = new class extends WebhookDestination
        {
            public array $ips = ['8.8.8.8'];

            public function addresses(string $host): array
            {
                return $this->ips;
            }
        };
        $this->app->instance(WebhookDestination::class, $this->destination);
    }

    private function body(array $changes = []): array
    {
        return $changes + ['url' => 'https://hooks.example.test/orders', 'description' => 'Local fixture', 'type' => 'orders', 'enabled' => true, 'request_key' => (string) Str::uuid()];
    }

    private function hook(array $changes = []): StoreOrderWebhook
    {
        $result = $this->postJson('/api/v1/my-store/webhooks', $this->body($changes))->assertCreated();

        return StoreOrderWebhook::findOrFail($result->json('data.id'));
    }

    private function order(): StoreOrder
    {
        app(CurrentStore::class)->set($this->store);
        $order = StoreOrder::create(['store_id' => $this->store->id, 'order_number' => 'LOCAL-'.Str::uuid(), 'status' => 'pending', 'currency' => 'EGP', 'customer_name' => 'Local buyer', 'customer_phone' => '01001234567', 'customer_email' => 'fixture@example.test', 'shipping_address' => ['line1' => 'Local address', 'city' => 'Cairo'], 'subtotal' => 250, 'shipping_total' => 25, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => 275, 'payment_method' => 'cod', 'payment_status' => 'pending', 'placed_at' => now()]);
        $order->items()->create(['store_id' => $this->store->id, 'store_product_id' => $this->product->id, 'name' => 'Immutable product name', 'sku' => 'LOCAL-SKU', 'unit_price' => 250, 'quantity' => 1, 'line_total' => 250]);

        return $order;
    }

    public function test_owner_creation_is_replay_safe_and_gets_never_reveal_secret_or_payload(): void
    {
        $body = $this->body();
        $first = $this->postJson('/api/v1/my-store/webhooks', $body)->assertCreated();
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $first->headers->get('Cache-Control'));
        $secret = $first->json('secret');
        $this->assertSame(64, strlen($secret));
        $this->postJson('/api/v1/my-store/webhooks', $body)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('secret', $secret);
        $this->postJson('/api/v1/my-store/webhooks', array_replace($body, ['url' => 'https://other.example.test/orders']))->assertConflict();
        $this->assertDatabaseCount('store_order_webhooks', 1);
        $this->assertStringNotContainsString($secret, DB::table('store_order_webhooks')->value('secret'));
        foreach (['/api/v1/my-store/webhooks', '/api/v1/my-store/webhooks/'.$first->json('data.id')] as $url) {
            $response = $this->getJson($url)->assertOk();
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString('request_key', $response->getContent());
        }
    }

    public function test_public_destinations_are_validated_and_mixed_private_dns_never_sends(): void
    {
        foreach (['http://hooks.example.test', 'https://root:password@hooks.example.test', 'https://hooks.example.test/#secret', 'https://hooks.example.test:0', 'https://127.0.0.1', 'https://169.254.169.254', 'https://100.64.1.2', 'https://[::1]', 'https://[::ffff:127.0.0.1]', 'https://[2001:db8::1]', 'https://gateway.internal'] as $url) {
            $this->postJson('/api/v1/my-store/webhooks', $this->body(['url' => $url]))->assertUnprocessable()->assertJsonValidationErrors('url');
        }
        $this->hook();
        app(StoreOrderWebhooks::class)->created($this->order());
        $this->destination->ips = ['8.8.8.8', '10.0.0.1'];
        app(StoreOrderWebhooks::class)->deliver();
        $this->assertDatabaseHas('store_order_webhook_deliveries', ['status' => 'failed', 'error_code' => 'unsafe_destination', 'payload' => null]);
        Http::assertNothingSent();
    }

    public function test_employee_and_foreign_store_access_are_denied_but_authorized_admin_routes_work(): void
    {
        $hook = $this->hook();
        $employee = User::factory()->create(['parent_user_id' => $this->owner->id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $employee->givePermissionTo('store.settings.manage');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($employee));
        $this->getJson('/api/v1/my-store/webhooks')->assertForbidden();
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        $foreign = Store::create(['owner_user_id' => $other->id, 'owner_type' => 'merchant', 'slug' => 'other-webhook-fixture', 'name' => 'Other fixture', 'status' => 'active']);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($other));
        $this->getJson('/api/v1/my-store/webhooks/'.$hook->id)->assertNotFound();
        $this->getJson('/api/v1/stores/'.$this->store->id.'/webhooks')->assertNotFound();
        $admin = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $admin->assignRole('Admin');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($admin));
        $this->getJson('/api/v1/stores/'.$this->store->id.'/webhooks')->assertOk()->assertJsonPath('data.0.id', $hook->id);
        $this->getJson('/api/v1/stores/'.$foreign->id.'/webhooks/'.$hook->id)->assertNotFound();
    }

    public function test_checkout_replay_records_one_encrypted_event_and_delivery_uses_immutable_items(): void
    {
        $hook = $this->hook();
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'webhooks.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true, 'test_mode' => true, 'credentials' => []]);
        $body = ['customer_name' => 'Local buyer', 'customer_email' => 'fixture@example.test', 'customer_phone' => '01001234567', 'shipping_address' => ['line1' => 'Local address', 'city' => 'Cairo', 'country' => 'EG'], 'items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'payment_method' => 'cod'];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $url = 'http://webhooks.sellchase.com/api/v1/storefront/checkout';
        $this->postJson($url, $body, $headers)->assertCreated();
        $this->postJson($url, $body, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseCount('store_order_webhook_deliveries', 1);
        Http::assertNothingSent();
        $row = StoreOrderWebhookDelivery::firstOrFail();
        $this->assertStringNotContainsString('fixture@example.test', $row->getRawOriginal('payload'));
        $this->product->update(['name' => 'Later catalog name']);
        Http::fake(['https://hooks.example.test/*' => Http::response([], 204)]);
        app(StoreOrderWebhooks::class)->deliver();
        app(StoreOrderWebhooks::class)->deliver();
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->hasHeader('secret', $hook->secret)
            && $request->hasHeader('X-Sellchaze-Event-Id', $row->id) && $request['cart_items'][0]['product']['name'] === 'Webhook fixture product');
        Http::assertSentCount(1);
        $this->assertDatabaseHas('store_order_webhook_deliveries', ['id' => $row->id, 'status' => 'sent', 'payload' => null, 'attempts' => 1]);
    }

    public function test_status_transition_records_each_event_once_and_rollback_never_sends(): void
    {
        $this->hook(['type' => 'order-status-update']);
        $order = $this->order();
        app(StoreOrderService::class)->transition($order, 'confirmed', $this->owner->id);
        $change = $order->statusChanges()->latest('id')->firstOrFail();
        app(StoreOrderWebhooks::class)->statusChanged($order, 'pending', $change->id);
        $this->assertDatabaseCount('store_order_webhook_deliveries', 1);
        $payload = StoreOrderWebhookDelivery::firstOrFail()->payload;
        $this->assertSame('pending', $payload['old_status']);
        $this->assertSame('confirmed', $payload['new_status']);
        DB::beginTransaction();
        app(StoreOrderService::class)->transition($order, 'processing', $this->owner->id);
        $this->assertDatabaseCount('store_order_webhook_deliveries', 2);
        DB::rollBack();
        $this->assertDatabaseCount('store_order_webhook_deliveries', 1);
        $this->assertSame('confirmed', $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_retry_keeps_event_id_and_body_and_never_follows_redirects(): void
    {
        $this->hook();
        app(StoreOrderWebhooks::class)->created($this->order());
        $row = StoreOrderWebhookDelivery::firstOrFail();
        $payload = $row->payload;
        Http::fakeSequence()->push([], 302, ['Location' => 'http://127.0.0.1/private'])->push([], 503)->push([], 200);
        app(StoreOrderWebhooks::class)->deliver();
        $this->assertSame('retry', $row->fresh()->status);
        $this->assertSame($payload, $row->fresh()->payload);
        $this->travel(2)->minutes();
        app(StoreOrderWebhooks::class)->deliver();
        $this->travel(3)->minutes();
        app(StoreOrderWebhooks::class)->deliver();
        Http::assertSentCount(3);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('https://hooks.example.test/orders', $request->url());
            $this->assertSame($payload, $request->data());
            $this->assertTrue($request->hasHeader('X-Sellchaze-Event-Id', $row->id));
        }
        $this->assertSame('sent', $row->fresh()->status);
        $this->assertNull($row->fresh()->payload);
    }

    public function test_transport_pins_validated_dns_keeps_tls_verification_and_disables_proxies_and_redirects(): void
    {
        $this->hook();
        app(StoreOrderWebhooks::class)->created($this->order());
        $options = null;
        Http::fake(function ($request, $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response([], 200);
        });
        app(StoreOrderWebhooks::class)->deliver();
        $this->assertFalse($options['allow_redirects']);
        $this->assertTrue($options['verify']);
        $this->assertSame('', $options['proxy']);
        $this->assertSame(['hooks.example.test:443:8.8.8.8'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('', $options['curl'][CURLOPT_PROXY]);
        $this->assertSame('*', $options['curl'][CURLOPT_NOPROXY]);
    }

    public function test_versions_disable_old_queue_and_deleted_hooks_are_not_recreated_by_replay(): void
    {
        $body = $this->body();
        $created = $this->postJson('/api/v1/my-store/webhooks', $body)->assertCreated()->json('data');
        app(StoreOrderWebhooks::class)->created($this->order());
        $url = '/api/v1/my-store/webhooks/'.$created['id'];
        $this->putJson($url, $body + ['version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($url, $body + ['version' => 1])->assertConflict();
        app(StoreOrderWebhooks::class)->deliver();
        $this->assertDatabaseHas('store_order_webhook_deliveries', ['status' => 'skipped', 'payload' => null]);
        $this->deleteJson($url, ['version' => 1])->assertConflict();
        $this->deleteJson($url, ['version' => 2])->assertOk();
        $this->getJson($url)->assertNotFound();
        $this->postJson('/api/v1/my-store/webhooks', $body)->assertConflict();
        $this->assertDatabaseCount('store_order_webhooks', 1);
        Http::assertNothingSent();
    }

    public function test_wallet_debt_blocks_charged_private_orders_until_funding_and_owner_changes_skip(): void
    {
        $this->hook();
        $wallet = app(PlatformWallet::class);
        config(['platform_wallet.fees_enabled' => true]);
        $wallet->activateOrderFees();
        for ($index = 0; $index < 5; $index++) {
            $order = $this->order();
            $wallet->orderFee($order);
            app(StoreOrderWebhooks::class)->created($order);
        }
        app(StoreOrderWebhooks::class)->deliver();
        $this->assertSame(5, StoreOrderWebhookDelivery::where('status', 'blocked')->count());
        $hook = StoreOrderWebhook::firstOrFail();
        $history = $this->getJson('/api/v1/my-store/webhooks/'.$hook->id)->assertOk();
        foreach ($history->json('deliveries') as $delivery) {
            $this->assertNull($delivery['event_key']);
        }
        $this->assertStringNotContainsString('fixture@example.test', $history->getContent());
        Http::assertNothingSent();
        $wallet->funding($this->owner, 1000, 'local-fixture-receipt');
        $this->travel(6)->minutes();
        Http::fake(['https://hooks.example.test/*' => Http::response([], 200)]);
        app(StoreOrderWebhooks::class)->deliver();
        $this->assertSame(5, StoreOrderWebhookDelivery::where('status', 'sent')->count());
        app(StoreOrderWebhooks::class)->created($this->order());
        $other = User::factory()->create();
        $this->store->update(['owner_user_id' => $other->id]);
        app(StoreOrderWebhooks::class)->deliver();
        Http::assertSentCount(5);
        $this->assertDatabaseHas('store_order_webhook_deliveries', ['status' => 'skipped', 'payload' => null]);
    }

    public function test_expired_crashed_deliveries_clear_payload_and_populated_migration_refuses_reversal(): void
    {
        $this->hook();
        app(StoreOrderWebhooks::class)->created($this->order());
        $row = StoreOrderWebhookDelivery::firstOrFail();
        $row->update(['status' => 'processing', 'lease_token' => Str::uuid(), 'lease_until' => now()->subMinute()]);
        $this->travel(2)->days();
        app(StoreOrderWebhooks::class)->deliver();
        $this->assertSame('expired', $row->fresh()->status);
        $this->assertNull($row->fresh()->payload);
        Http::assertNothingSent();
        $migration = require database_path('migrations/2026_10_13_060000_create_store_order_webhooks.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_previous_owner_configurations_do_not_consume_the_new_owner_quota(): void
    {
        $hook = $this->hook(['enabled' => false]);
        for ($index = 1; $index < 50; $index++) {
            $copy = $hook->replicate();
            $copy->request_key = (string) Str::uuid();
            $copy->save();
        }
        $newOwner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $newOwner->assignRole('Merchant');
        $this->store->update(['owner_user_id' => $newOwner->id]);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($newOwner));
        $this->postJson('/api/v1/my-store/webhooks', $this->body(['enabled' => false]))->assertCreated();
        $this->getJson('/api/v1/my-store/webhooks')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/my-store/webhooks/'.$hook->id)->assertNotFound();
        $this->assertDatabaseCount('store_order_webhooks', 51);
        Http::assertNothingSent();
    }
}
