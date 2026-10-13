<?php

namespace Tests\Feature;

use App\Models\AuthSession;
use App\Models\MerchantPushSubscription;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Commerce\MerchantOrderPush;
use App\Services\Commerce\MerchantPushSettings;
use App\Services\Commerce\MerchantPushTransport;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\StorePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

class MerchantDevicePushTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private AuthSession $session;

    private string $url = '/api/v1/my-store/order-push';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class, StorePermissionsSeeder::class]);
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        $keys = VAPID::createVapidKeys();
        config(['merchant_push.public_key' => $keys['publicKey'], 'merchant_push.private_key' => $keys['privateKey'], 'merchant_push.subject' => 'mailto:push@example.test']);
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->session = $this->newSession($this->owner);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner, $this->session->id));
        $this->getJson('/api/v1/my-store')->assertOk();
        $this->store = $this->owner->fresh()->store;
    }

    private function newSession(User $user): AuthSession
    {
        return AuthSession::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'refresh_jti' => Str::random(64), 'expires_at' => now()->addDay(), 'last_used_at' => now(), 'device_name' => 'Fixture device']);
    }

    private function body(array $changes = []): array
    {
        return array_replace(['enabled' => true, 'version' => 0, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-device', 'keys' => ['p256dh' => config('merchant_push.public_key'), 'auth' => rtrim(strtr(base64_encode(str_repeat('a', 16)), '+/', '-_'), '=')]], $changes);
    }

    private function subscribe(): MerchantPushSubscription
    {
        $this->putJson($this->url, $this->body())->assertOk()->assertJsonPath('data.subscription.enabled', true);

        return MerchantPushSubscription::firstOrFail();
    }

    private function order(): StoreOrder
    {
        return StoreOrder::create(['store_id' => $this->store->id, 'order_number' => (string) Str::uuid(), 'customer_name' => 'Private buyer', 'customer_email' => 'private@example.test', 'currency' => 'EGP', 'status' => 'pending', 'grand_total' => 100]);
    }

    public function test_read_is_private_and_subscription_keys_are_encrypted_and_never_returned(): void
    {
        $response = $this->getJson($this->url)->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('merchant_push_subscriptions', 0);
        $subscription = $this->subscribe();
        $raw = DB::table('merchant_push_subscriptions')->value('subscription');
        $this->assertStringNotContainsString('fcm.googleapis.com', $raw);
        $response = $this->getJson($this->url.'?endpoint_hash='.hash('sha256', $this->body()['endpoint']))->assertOk();
        $this->assertSame(['id', 'version', 'enabled'], array_keys($response->json('data.subscription')));
        $this->assertSame($this->body()['endpoint'], $subscription->subscription['endpoint']);
        $this->assertStringNotContainsString(config('merchant_push.private_key'), $response->getContent());
    }

    public function test_stale_versions_disable_by_id_and_same_owner_session_rebinding(): void
    {
        $subscription = $this->subscribe();
        $this->putJson($this->url, $this->body())->assertConflict();
        $next = $this->newSession($this->owner);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner, $next->id));
        $this->getJson($this->url.'?subscription_id='.$subscription->id)->assertJsonPath('data.subscription.enabled', false);
        $this->putJson($this->url, $this->body(['version' => 1]))->assertOk()->assertJsonPath('data.subscription.version', 2);
        $this->assertSame($next->id, $subscription->fresh()->auth_session_id);
        $this->putJson($this->url, ['enabled' => false, 'version' => 2, 'subscription_id' => $subscription->id])->assertOk()->assertJsonPath('data.subscription.enabled', false);
        $this->putJson($this->url, $this->body(['version' => 2]))->assertConflict();
    }

    public function test_foreign_owner_cannot_read_or_reuse_or_disable_another_device(): void
    {
        $subscription = $this->subscribe();
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        $session = $this->newSession($other);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($other, $session->id));
        $this->getJson('/api/v1/my-store')->assertOk();
        $this->getJson($this->url.'?subscription_id='.$subscription->id)->assertOk()->assertJsonPath('data.subscription', null);
        $this->putJson($this->url, $this->body())->assertConflict();
        $this->putJson($this->url, ['enabled' => false, 'version' => 1, 'subscription_id' => $subscription->id])->assertNotFound();
        $this->assertTrue($subscription->fresh()->enabled);
    }

    public function test_missing_session_configuration_and_unsafe_endpoints_are_rejected(): void
    {
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
        $this->getJson($this->url)->assertUnprocessable();
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner, $this->session->id));
        foreach (['http://fcm.googleapis.com/x', 'https://127.0.0.1/x', 'https://fcm.googleapis.com.evil.test/x', 'https://user@fcm.googleapis.com/x', 'https://fcm.googleapis.com:8443/x', 'https://fcm.googleapis.com/x#fragment'] as $endpoint) {
            $this->putJson($this->url, $this->body(['endpoint' => $endpoint]))->assertUnprocessable();
        }
        $this->putJson($this->url, $this->body(['keys' => ['p256dh' => 'invalid', 'auth' => ['invalid']]]))->assertUnprocessable();
        config(['merchant_push.private_key' => '']);
        $this->getJson($this->url)->assertJsonPath('data.configured', false)->assertJsonPath('data.public_key', null);
        $this->putJson($this->url, $this->body())->assertStatus(503);
        $this->assertDatabaseCount('merchant_push_subscriptions', 0);
    }

    public function test_actual_checkout_records_one_durable_delivery_and_replay_sends_nothing_in_request(): void
    {
        $this->subscribe();
        $this->mock(MerchantPushTransport::class)->shouldNotReceive('send');
        $this->store->update(['status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'push.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true]);
        $url = 'http://push.sellchase.com/api/v1/storefront/checkout';
        $body = ['customer_name' => 'Private buyer', 'customer_email' => 'private@example.test', 'customer_phone' => '+201000000000', 'shipping_address' => ['line1' => 'Fixture address'], 'items' => [['product_id' => $product->id, 'quantity' => 1]]];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $first = $this->postJson($url, $body, $headers)->assertCreated();
        $this->postJson($url, $body, $headers)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('merchant_order_push_deliveries', 1);
        $this->assertDatabaseCount('store_orders', 1);
    }

    public function test_delivery_success_is_once_and_payload_does_not_include_customer_details(): void
    {
        $this->subscribe();
        $order = $this->order();
        $push = app(MerchantOrderPush::class);
        $push->record($order);
        $push->record($order);
        $this->mock(MerchantPushTransport::class)->shouldReceive('send')->once()->withArgs(function ($subscription, $payload, $topic) {
            $this->assertSame('/store/orders', $payload['url']);
            $this->assertStringNotContainsString('private@example.test', json_encode($payload));
            $this->assertSame(32, strlen($topic));

            return true;
        })->andReturn(201);
        $push = app(MerchantOrderPush::class);
        $this->assertSame(1, $push->deliver());
        $this->assertSame(0, $push->deliver());
        $this->assertDatabaseHas('merchant_order_push_deliveries', ['status' => 'sent', 'attempts' => 1]);
    }

    public function test_revoked_session_changed_version_expired_delivery_and_owner_change_skip_sending(): void
    {
        $subscription = $this->subscribe();
        $push = app(MerchantOrderPush::class);
        $this->mock(MerchantPushTransport::class)->shouldNotReceive('send');
        foreach (['revoke', 'version', 'expiry', 'owner'] as $reason) {
            $this->session->update(['revoked_at' => null]);
            $subscription->update(['version' => 1]);
            $this->store->update(['owner_user_id' => $this->owner->id]);
            $order = $this->order();
            $push->record($order);
            if ($reason === 'revoke') {
                $this->session->update(['revoked_at' => now()]);
            }
            if ($reason === 'version') {
                $subscription->update(['version' => 2]);
            }
            if ($reason === 'expiry') {
                DB::table('merchant_order_push_deliveries')->where('store_order_id', $order->id)->update(['expires_at' => now()->subSecond()]);
            }
            if ($reason === 'owner') {
                $this->store->update(['owner_user_id' => User::factory()->create()->id]);
            }
            app(MerchantOrderPush::class)->deliver();
            $this->assertDatabaseHas('merchant_order_push_deliveries', ['store_order_id' => $order->id, 'status' => $reason === 'expiry' ? 'expired' : 'skipped']);
        }
    }

    public function test_provider_retry_and_expired_subscription_and_reclaimed_attempt_limit(): void
    {
        $subscription = $this->subscribe();
        $push = app(MerchantOrderPush::class);
        $push->record($this->order());
        $transport = $this->mock(MerchantPushTransport::class);
        $transport->shouldReceive('send')->once()->andReturn(503);
        app(MerchantOrderPush::class)->deliver();
        $this->assertDatabaseHas('merchant_order_push_deliveries', ['status' => 'retry', 'attempts' => 1]);
        $this->assertSame(0, app(MerchantOrderPush::class)->deliver());
        $this->travel(16)->seconds();
        $transport->shouldReceive('send')->once()->andReturn(410);
        app(MerchantOrderPush::class)->deliver();
        $this->assertFalse($subscription->fresh()->enabled);
        $subscription->fresh()->update(['enabled' => true]);
        $push->record($this->order());
        DB::table('merchant_order_push_deliveries')->where('status', 'queued')->update(['status' => 'processing', 'attempts' => 8, 'lease_until' => now()->subSecond()]);
        $this->assertSame(0, app(MerchantOrderPush::class)->deliver());
        $this->assertDatabaseHas('merchant_order_push_deliveries', ['status' => 'failed', 'attempts' => 8]);
    }

    public function test_stale_worker_cannot_disable_a_renewed_subscription(): void
    {
        $subscription = $this->subscribe();
        app(MerchantOrderPush::class)->record($this->order());
        $this->mock(MerchantPushTransport::class)->shouldReceive('send')->once()->andReturnUsing(function () use ($subscription) {
            $subscription->update(['version' => 2]);

            return 410;
        });
        app(MerchantOrderPush::class)->deliver();
        $this->assertTrue($subscription->fresh()->enabled);
        $this->assertSame(2, $subscription->fresh()->version);
    }

    public function test_sessions_are_self_only_private_and_foreign_or_current_revocation_is_rejected(): void
    {
        $other = User::factory()->create();
        $foreign = $this->newSession($other);
        $ownOther = $this->newSession($this->owner);
        $response = $this->getJson('/api/v1/auth/sessions')->assertOk()->assertJsonCount(2, 'data');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($this->owner->email, $response->json('data.0.email'));
        $this->assertNotNull($response->json('data.0.created_at'));
        $this->assertStringNotContainsString($foreign->id, $response->getContent());
        $this->assertStringNotContainsString($ownOther->refresh_jti, $response->getContent());
        $this->deleteJson('/api/v1/auth/sessions/'.$this->session->id)->assertUnprocessable();
        $this->deleteJson('/api/v1/auth/sessions/'.$foreign->id)->assertNotFound();
        $token = JwtTokenService::fromConfig()->issueAccessToken($this->owner, $ownOther->id);
        $this->deleteJson('/api/v1/auth/sessions/'.$ownOther->id)->assertOk();
        $this->assertNull(JwtTokenService::fromConfig()->validateAccessToken($token));
        $this->assertNotNull(JwtTokenService::fromConfig()->validateAccessToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner, $this->session->id)));
    }

    public function test_configuration_and_endpoint_allowlist_fail_closed(): void
    {
        $settings = app(MerchantPushSettings::class);
        $this->assertTrue($settings->ready());
        foreach (['mailto:', 'mailto:bad', 'http://example.test', 'https://'] as $subject) {
            config(['merchant_push.subject' => $subject]);
            $this->assertFalse($settings->ready());
        }
        foreach (['https://updates.push.services.mozilla.com/x', 'https://web.push.apple.com/x', 'https://wns.notify.windows.com/x'] as $endpoint) {
            $this->assertTrue($settings->endpointAllowed($endpoint));
        }
        $this->assertFalse($settings->endpointAllowed('https://evil-notify.windows.com/x'));
    }

    public function test_damaged_credentials_do_not_block_deliveries_to_other_devices(): void
    {
        $broken = $this->subscribe();
        $this->putJson($this->url, $this->body(['endpoint' => 'https://fcm.googleapis.com/fcm/send/second-device']))->assertOk();
        app(MerchantOrderPush::class)->record($this->order());
        DB::table('merchant_push_subscriptions')->where('id', $broken->id)->update(['subscription' => 'damaged']);
        $this->mock(MerchantPushTransport::class)->shouldReceive('send')->once()->andReturn(201);
        $this->assertSame(1, app(MerchantOrderPush::class)->deliver());
        $this->assertDatabaseHas('merchant_order_push_deliveries', ['subscription_id' => $broken->id, 'status' => 'skipped']);
        $this->assertDatabaseHas('merchant_order_push_deliveries', ['status' => 'sent']);
    }

    public function test_existing_admin_session_deletion_still_works_and_suppresses_notifications(): void
    {
        $subscription = $this->subscribe();
        app(MerchantOrderPush::class)->record($this->order());
        $admin = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $admin->assignRole('Admin');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($admin));
        $this->postJson('/api/v1/admin/monitoring/sessions/bulk-destroy', ['ids' => [$this->session->id]])->assertOk()->assertJsonPath('deleted', 1);
        $this->assertNull($subscription->fresh()->auth_session_id);
        $this->mock(MerchantPushTransport::class)->shouldNotReceive('send');
        $this->assertSame(0, app(MerchantOrderPush::class)->deliver());
        $this->assertDatabaseHas('merchant_order_push_deliveries', ['status' => 'skipped']);
    }

    public function test_inactive_owner_is_suppressed_and_explicit_store_deletion_clears_its_device_queue(): void
    {
        $this->subscribe();
        app(MerchantOrderPush::class)->record($this->order());
        $this->owner->update(['is_active' => false]);
        $this->mock(MerchantPushTransport::class)->shouldNotReceive('send');
        $this->assertSame(0, app(MerchantOrderPush::class)->deliver());
        $this->assertDatabaseHas('merchant_order_push_deliveries', ['status' => 'skipped']);
        $this->store->delete();
        $this->assertDatabaseCount('merchant_push_subscriptions', 0);
        $this->assertDatabaseCount('merchant_order_push_deliveries', 0);
    }
}
