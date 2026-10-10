<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreBotChallenge;
use App\Models\StoreDomain;
use App\Models\StorePaymentGateway;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CheckoutBotProtection;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\PaymentRetryToken;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CheckoutBotProtectionTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private array $auth;

    private string $nonce = '';

    private array $providerFields = [];

    private int $providerStatus = 200;

    private bool $connectionFails = false;

    private string $base = 'http://bot-review.sellchase.com/api/v1/storefront';

    private string $settings = '/api/v1/my-store/bot-protection';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Bot review', 'slug' => 'bot-review', 'currency' => 'EGP', 'status' => 'active', 'default_locale' => 'en', 'supported_locales' => ['en', 'ar']]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'bot-review.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 5]);
        Http::fake(function ($request) {
            $this->assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $request->url());
            if ($this->connectionFails) {
                throw new ConnectionException('LOCAL simulated response loss');
            }

            return Http::response(array_replace(['success' => true, 'hostname' => 'bot-review.sellchase.com', 'action' => CheckoutBotProtection::ACTION,
                'cdata' => $this->nonce, 'challenge_ts' => now()->toIso8601String()], $this->providerFields), $this->providerStatus);
        });
    }

    private function enable(): void
    {
        $version = app(CheckoutBotProtection::class)->configuration($this->store->fresh())['version'];
        $this->putJson($this->settings, ['enabled' => true, 'version' => $version, 'credentials' => ['site_key' => 'LOCAL_CF_SITE', 'secret_key' => 'LOCAL_CF_SECRET']], $this->auth)->assertOk()->assertJsonPath('data.enabled', true);
        $this->store->refresh();
    }

    private function verify(?string $nonce = null, ?string $token = null): TestResponse
    {
        $this->nonce = $nonce ?? bin2hex(random_bytes(32));

        return $this->postJson($this->base.'/checkout/bot/verify', ['nonce' => $this->nonce, 'token' => $token ?? 'LOCAL-CF-'.Str::uuid()]);
    }

    private function proof(): string
    {
        $this->verify()->assertOk()->assertJsonPath('data.state', 'verified');

        return $this->nonce;
    }

    private function payload(?string $nonce = null): array
    {
        return ['customer_name' => 'LOCAL bot review', 'customer_email' => 'bot@example.test', 'customer_phone' => '01001234567',
            'bot_proof' => $nonce, 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]];
    }

    public function test_settings_permissions_encryption_versions_clear_and_public_configuration(): void
    {
        $this->getJson($this->settings)->assertUnauthorized();
        $this->getJson($this->settings, $this->auth)->assertOk()->assertJsonPath('data.enabled', false);
        $this->putJson($this->settings, ['enabled' => true, 'version' => 1], $this->auth)->assertUnprocessable();
        $this->enable();
        $response = $this->getJson($this->settings, $this->auth)->assertOk();
        $this->assertStringNotContainsString('LOCAL_CF_SECRET', $response->getContent());
        $this->assertStringNotContainsString('LOCAL_CF_SECRET', $this->store->toJson());
        $this->assertStringNotContainsString('LOCAL_CF_SECRET', DB::table('stores')->where('id', $this->store->id)->value('bot_protection_credentials'));
        $this->getJson($this->base.'/checkout/fields')->assertOk()->assertJsonPath('bot_protection.site_key', 'LOCAL_CF_SITE')->assertJsonPath('bot_protection.required', true);
        $this->putJson($this->settings, ['enabled' => false, 'version' => 1], $this->auth)->assertStatus(409);
        $this->putJson($this->settings, ['enabled' => true, 'version' => 2, 'clear_credentials' => true], $this->auth)->assertUnprocessable();
        $this->putJson($this->settings, ['enabled' => false, 'version' => 2, 'clear_credentials' => true], $this->auth)->assertOk()->assertJsonPath('data.credentials_configured', false);
        $this->assertNull($this->store->fresh()->bot_protection_credentials);
        Http::assertNothingSent();
    }

    public function test_only_verified_store_domains_are_allowed_and_test_keys_are_rejected_outside_local_testing(): void
    {
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'pending.example.test', 'type' => 'custom', 'status' => 'pending']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'verified.example.test', 'type' => 'custom', 'status' => 'verified']);
        $this->assertContains('verified.example.test', app(CheckoutBotProtection::class)->hostnames($this->store));
        $this->assertNotContains('pending.example.test', app(CheckoutBotProtection::class)->hostnames($this->store));
        $this->app->detectEnvironment(fn () => 'production');
        try {
            $this->assertNotContains('localhost', app(CheckoutBotProtection::class)->hostnames($this->store));
            $this->putJson($this->settings, ['enabled' => true, 'version' => 1, 'credentials' => ['site_key' => '1x00000000000000000000AA', 'secret_key' => '1x0000000000000000000000000000000AA']], $this->auth)->assertUnprocessable();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
        $this->assertNull($this->store->fresh()->bot_protection_credentials);
    }

    public function test_provider_token_is_verified_once_and_same_request_replay_does_not_extend_expiry(): void
    {
        $this->enable();
        $nonce = bin2hex(random_bytes(32));
        $token = 'LOCAL-CF-'.Str::uuid();
        $first = $this->verify($nonce, $token)->assertOk()->assertJsonPath('data.state', 'verified');
        $expiry = $first->json('data.expires_at');
        $this->travel(2)->seconds();
        $this->verify($nonce, $token)->assertOk()->assertJsonPath('data.expires_at', $expiry);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['secret'] === 'LOCAL_CF_SECRET' && $request['response'] === $token && $request['remoteip'] === '127.0.0.1');
        $this->verify($nonce, 'different')->assertStatus(409);
        $this->verify(token: $token)->assertUnprocessable();
        $record = StoreBotChallenge::first();
        $this->assertStringNotContainsString($token, $record->toJson());
        $this->assertNotEquals($token, $record->provider_token_hash);
        $this->assertNotEquals($nonce, $record->nonce_hash);
        $this->assertDatabaseCount('store_bot_challenges', 1);
        Http::assertSentCount(1);
    }

    public function test_provider_success_alone_cannot_bypass_hostname_action_cdata_or_timestamp_checks(): void
    {
        // Keep the six-second future case outside the five-second tolerance throughout the request.
        $this->freezeSecond();
        $this->enable();
        foreach ([['success' => 'true'], ['hostname' => 'attacker.test'], ['action' => 'login'], ['cdata' => 'wrong'],
            ['challenge_ts' => now()->subSeconds(300)->toIso8601String()], ['challenge_ts' => now()->addSeconds(6)->toIso8601String()],
            ['challenge_ts' => 'not-a-date'], ['challenge_ts' => null], ['hostname' => null]] as $fields) {
            $this->providerFields = $fields;
            $this->verify()->assertOk()->assertJsonPath('data.state', 'failed')->assertJsonPath('data.expires_at', null);
        }
        $this->assertSame(0, StoreBotChallenge::where('state', 'verified')->count());
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_failed_or_ambiguous_transport_never_grants_proof_or_automatically_resends(): void
    {
        $this->enable();
        $this->providerStatus = 502;
        $nonce = bin2hex(random_bytes(32));
        $token = 'LOCAL-CF-'.Str::uuid();
        $this->verify($nonce, $token)->assertOk()->assertJsonPath('data.state', 'failed');
        $this->providerStatus = 200;
        $this->verify($nonce, $token)->assertOk()->assertJsonPath('data.state', 'failed');
        Http::assertSentCount(1);
        $this->postJson($this->base.'/checkout', $this->payload($nonce))->assertUnprocessable()->assertJsonValidationErrors('bot_proof');
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertSame(5, $this->product->fresh()->stock_quantity);
    }

    public function test_consumption_is_atomic_authoritative_and_new_orders_cannot_reuse_proof(): void
    {
        $this->enable();
        $nonce = $this->proof();
        $cart = app(CartService::class)->create($this->store, null);
        app(CartService::class)->addItem($cart, $this->product->id, 1);
        $contact = ['name' => 'LOCAL', 'email' => 'bot@example.test', 'phone' => '01001234567', 'bot_proof' => $nonce, 'bot_ip' => '127.0.0.1'];
        $this->product->update(['price' => 101]);
        try {
            app(CheckoutService::class)->place($this->store, $cart, null, $contact, null, 'cod');
            $this->fail('Stale checkout allowed');
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey('items', $failure->errors());
        }
        $this->assertSame('verified', StoreBotChallenge::first()->state);
        $this->assertDatabaseCount('store_orders', 0);
        $this->product->update(['price' => 100]);
        $order = app(CheckoutService::class)->place($this->store, $cart, null, $contact, null, 'cod');
        $this->assertSame('used', StoreBotChallenge::first()->state);
        $this->assertSame($order->id, StoreBotChallenge::first()->store_order_id);
        $this->postJson($this->base.'/checkout', $this->payload($nonce))->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertSame(5, $this->product->fresh()->stock_quantity);
        $this->assertSame(1, $this->product->fresh()->reserved_quantity);
    }

    public function test_missing_proof_changed_ip_expiry_settings_and_other_store_reject(): void
    {
        $this->enable();
        $this->postJson($this->base.'/checkout', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('bot_proof');
        $nonce = $this->proof();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.45'])->postJson($this->base.'/checkout', $this->payload($nonce))->assertUnprocessable();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->travel(300)->seconds();
        $this->postJson($this->base.'/checkout', $this->payload($nonce))->assertUnprocessable();
        $this->travelBack();
        $other = Store::create(['owner_user_id' => $this->store->owner_user_id, 'name' => 'Other', 'owner_type' => 'merchant', 'slug' => 'other-bot', 'status' => 'active', 'bot_protection_configuration' => $this->store->bot_protection_configuration, 'bot_protection_credentials' => $this->store->bot_protection_credentials]);
        try {
            DB::transaction(fn () => app(CheckoutBotProtection::class)->consume($other, $nonce, '127.0.0.1'));
            $this->fail('Cross-store proof accepted');
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey('bot_proof', $failure->errors());
        }
        $this->putJson($this->settings, ['enabled' => true, 'version' => 2], $this->auth)->assertOk();
        $this->postJson($this->base.'/checkout', $this->payload($nonce))->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_idempotent_direct_checkout_replay_receipts_and_payment_retry_survive_expiry(): void
    {
        $this->enable();
        $nonce = $this->proof();
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true, 'credentials' => []]);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $payload = $this->payload($nonce) + ['payment_method' => 'bank_transfer', 'cart_mode' => 'direct'];
        $first = $this->postJson($this->base.'/checkout', $payload, $headers)->assertCreated();
        $this->travel(301)->seconds();
        $this->postJson($this->base.'/checkout', $payload, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->postJson($this->base.'/checkout/receipt', ['token' => $first->json('receipt.token')])->assertOk();
        $retry = app(PaymentRetryToken::class)->make($this->store->id, $first->json('data.id'));
        $this->postJson($this->base.'/checkout/payment/retry', ['order_id' => $first->json('data.id'), 'token' => $retry])->assertOk();
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseCount('store_payment_transactions', 1);
    }

    public function test_ip_budget_is_durable_and_default_disabled_preserves_old_flows(): void
    {
        $this->postJson($this->base.'/checkout', $this->payload())->assertCreated();
        $this->enable();
        $this->proof();
        $source = StoreBotChallenge::first()->getAttributes();
        for ($i = 0; $i < 39; $i++) {
            StoreBotChallenge::create(array_replace(array_diff_key($source, ['id' => true]), ['nonce_hash' => bin2hex(random_bytes(32)), 'provider_token_hash' => bin2hex(random_bytes(32)), 'state' => 'failed']));
        }
        $this->verify()->assertStatus(429);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('store_bot_challenges', 40);
    }

    public function test_pruning_removes_only_old_unused_checks_and_retains_order_audit(): void
    {
        $this->enable();
        $this->proof();
        StoreBotChallenge::first()->update(['created_at' => now()->subDays(31)]);
        $nonce = $this->proof();
        $this->postJson($this->base.'/checkout', $this->payload($nonce))->assertCreated();
        StoreBotChallenge::where('state', 'used')->update(['created_at' => now()->subDays(31)]);
        $this->proof();
        $this->artisan('checkout-bot:prune')->assertSuccessful();
        $this->assertDatabaseCount('store_bot_challenges', 2);
        $this->assertSame(1, StoreBotChallenge::whereNotNull('store_order_id')->count());
        $this->assertSame(1, StoreBotChallenge::where('state', 'verified')->count());
    }

    public function test_settings_change_during_provider_verification_cannot_grant_a_proof(): void
    {
        $this->enable();
        // A fresh client binding avoids appending fake callbacks to the earlier transport.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function () {
            app(CheckoutBotProtection::class)->save($this->store, ['enabled' => true, 'version' => 2]);

            return Http::response(['success' => true, 'hostname' => 'bot-review.sellchase.com', 'action' => CheckoutBotProtection::ACTION, 'cdata' => $this->nonce, 'challenge_ts' => now()->toIso8601String()]);
        });
        $this->verify()->assertOk()->assertJsonPath('data.state', 'failed');
        $this->postJson($this->base.'/checkout', $this->payload($this->nonce))->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_connection_failure_is_durable_and_requires_a_fresh_widget_proof(): void
    {
        $this->enable();
        $this->connectionFails = true;
        $nonce = bin2hex(random_bytes(32));
        $token = 'LOCAL-CF-'.Str::uuid();
        $this->verify($nonce, $token)->assertOk()->assertJsonPath('data.state', 'failed');
        $this->connectionFails = false;
        $this->verify($nonce, $token)->assertOk()->assertJsonPath('data.state', 'failed');
        $this->proof();
        $this->assertSame(1, StoreBotChallenge::where('state', 'verified')->count());
        $this->assertSame(1, StoreBotChallenge::where('state', 'failed')->count());
    }

    public function test_order_employee_and_foreign_owner_cannot_read_or_change_protection_settings(): void
    {
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $employee->givePermissionTo('store.orders.manage');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee)];
        $this->getJson($this->settings, $headers)->assertForbidden();
        $this->putJson($this->settings, ['enabled' => false, 'version' => 1], $headers)->assertForbidden();
        $employee->givePermissionTo('store.settings.manage');
        $this->travel(31)->seconds();
        $this->getJson($this->settings, $headers)->assertOk();
        $this->travelBack();
        $foreign = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $foreign->assignRole('Merchant');
        $this->getJson('/api/v1/stores/'.$this->store->id.'/bot-protection', ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($foreign)])->assertNotFound();
    }
}
