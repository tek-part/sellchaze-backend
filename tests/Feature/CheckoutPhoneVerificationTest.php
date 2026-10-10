<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductDigitalCode;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreDomain;
use App\Models\StorePaymentGateway;
use App\Models\StorePhoneChallenge;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CheckoutFields;
use App\Services\Commerce\CheckoutPhoneVerification;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\PaymentRetryToken;
use App\Services\Commerce\PhoneBlocking;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CheckoutPhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private array $auth;

    private array $codes = [];

    private string $transport = 'accepted';

    private string $base = 'http://otp-review.sellchase.com/api/v1/storefront';

    private string $settings = '/api/v1/my-store/phone-verification';

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
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'OTP review', 'slug' => 'otp-review', 'currency' => 'EGP', 'status' => 'active', 'default_locale' => 'en', 'supported_locales' => ['en', 'ar']]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'otp-review.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 5]);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/session/info')) {
                return Http::response(['status' => 'WORKING']);
            }
            if ($this->transport !== 'accepted') {
                return Http::response(['status' => 'unavailable'], $this->transport === 'uncertain' ? 502 : 401);
            }
            preg_match('/\b([0-9]{6})\b/', $request['message'] ?? '', $match);
            $this->codes[] = $match[1] ?? '';

            return Http::response(['id' => ['_serialized' => 'LOCAL-OTP-message']]);
        });
    }

    private function enable(): void
    {
        $version = app(CheckoutPhoneVerification::class)->configuration($this->store->fresh())['version'];
        $this->putJson($this->settings, ['enabled' => true, 'version' => $version, 'credentials' => ['instance_id' => 'LOCAL-OTP-instance', 'access_token' => 'LOCAL-OTP-secret']], $this->auth)->assertOk()->assertJsonPath('data.enabled', true);
        $this->store->refresh();
    }

    private function send(?string $token = null, string $phone = '01001234567'): string
    {
        $token ??= bin2hex(random_bytes(32));
        $this->postJson($this->base.'/checkout/phone/send', ['phone' => $phone, 'token' => $token])->assertOk()->assertJsonPath('data.state', 'sent');

        return $token;
    }

    private function proof(string $phone = '01001234567'): string
    {
        $token = $this->send(phone: $phone);
        $this->postJson($this->base.'/checkout/phone/verify', ['token' => $token, 'code' => end($this->codes)])->assertOk()->assertJsonPath('data.state', 'verified');

        return $token;
    }

    private function payload(?string $token = null, string $phone = '01001234567'): array
    {
        return ['customer_name' => 'LOCAL review', 'customer_email' => 'otp@example.test', 'customer_phone' => $phone,
            'phone_verification' => $token, 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]];
    }

    public function test_settings_require_permission_verified_connection_and_do_not_disclose_secrets(): void
    {
        $this->getJson($this->settings)->assertUnauthorized();
        $this->getJson($this->settings, $this->auth)->assertOk()->assertJsonPath('data.enabled', false);
        $this->putJson($this->settings, ['enabled' => true, 'version' => 1], $this->auth)->assertUnprocessable();
        $this->enable();
        $body = $this->getJson($this->settings, $this->auth)->assertOk()->json();
        $this->assertStringNotContainsString('LOCAL-OTP-secret', json_encode($body));
        $this->assertStringNotContainsString('LOCAL-OTP-secret', $this->store->toJson());
        $this->assertStringNotContainsString('LOCAL-OTP-secret', DB::table('stores')->where('id', $this->store->id)->value('phone_otp_credentials'));
        $this->putJson($this->settings, ['enabled' => false, 'version' => 1], $this->auth)->assertStatus(409);
        $this->putJson($this->settings, ['enabled' => true, 'version' => 2, 'clear_credentials' => true], $this->auth)->assertUnprocessable();
        $this->putJson($this->settings, ['enabled' => false, 'version' => 2, 'clear_credentials' => true], $this->auth)->assertOk()->assertJsonPath('data.credentials_configured', false);
        $this->assertNull($this->store->fresh()->phone_otp_credentials);
        $this->getJson($this->base.'/phone-verification')->assertNotFound();
    }

    public function test_send_retry_is_durable_and_otp_digests_are_private_and_one_time(): void
    {
        $this->enable();
        $token = $this->send();
        $code = end($this->codes);
        $this->send($token, '+20 100 123 4567');
        Http::assertSentCount(2); // One connection check, one message.
        $this->assertDatabaseCount('store_phone_challenges', 1);
        $challenge = StorePhoneChallenge::first();
        $this->assertNotSame($token, $challenge->token_hash);
        $this->assertNotSame($code, $challenge->code_hash);
        foreach ([$token, $code, $challenge->token_hash, $challenge->code_hash] as $secret) {
            $this->assertStringNotContainsString($secret, $challenge->toJson());
        }
        $this->postJson($this->base.'/checkout/phone/verify', ['token' => $token, 'code' => $code])->assertOk();
        $this->assertNull($challenge->fresh()->code_hash);
        $this->postJson($this->base.'/checkout/phone/verify', ['token' => $token, 'code' => $code])->assertOk();
        $this->postJson($this->base.'/checkout', $this->payload($token, '٠١٠٠١٢٣٤٥٦٧'))->assertCreated();
        $this->assertSame('used', $challenge->fresh()->state);
        $this->assertNotNull($challenge->fresh()->store_order_id);
        $this->postJson($this->base.'/checkout', $this->payload($token))->assertUnprocessable()->assertJsonValidationErrors('phone_verification');
        $this->assertDatabaseCount('store_orders', 1);
        Mail::assertNothingSent();
    }

    public function test_employee_and_foreign_owner_cannot_manage_another_stores_otp_connection(): void
    {
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee)];
        $this->getJson($this->settings, $headers)->assertForbidden();
        $this->putJson($this->settings, ['enabled' => false, 'version' => 1], $headers)->assertForbidden();
        $employee->givePermissionTo('store.orders.manage');
        $this->travel(31)->seconds();
        $this->getJson('/api/v1/my-store/blocked-phones', $headers)->assertOk();
        $this->getJson($this->settings, $headers)->assertForbidden();
        $employee->givePermissionTo('store.settings.manage');
        $this->travel(31)->seconds();
        $this->getJson($this->settings, $headers)->assertOk();
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        $foreign = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($other)];
        $this->getJson('/api/v1/stores/'.$this->store->id.'/phone-verification', $foreign)->assertNotFound();
        $this->putJson('/api/v1/stores/'.$this->store->id.'/phone-verification', ['enabled' => false, 'version' => 1], $foreign)->assertNotFound();
        $this->travelBack();
    }

    public function test_wrong_attempts_commit_and_expiry_is_strict(): void
    {
        $this->enable();
        $token = $this->send();
        $correct = end($this->codes);
        $wrong = $correct === '999999' ? '000000' : '999999';
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson($this->base.'/checkout/phone/verify', ['token' => $token, 'code' => $wrong])->assertUnprocessable();
            $this->assertSame($i, StorePhoneChallenge::first()->attempts);
        }
        $this->postJson($this->base.'/checkout/phone/verify', ['token' => $token, 'code' => $correct])->assertUnprocessable();
        $this->assertNull(StorePhoneChallenge::first()->code_hash);
        $this->travel(61)->seconds();
        $second = $this->send();
        $this->travel(300)->seconds();
        $this->postJson($this->base.'/checkout/phone/verify', ['token' => $second, 'code' => end($this->codes)])->assertUnprocessable();
        $this->travelBack();
    }

    public function test_cooldown_and_canonical_phone_send_limits_cannot_be_bypassed(): void
    {
        $this->enable();
        $first = $this->send();
        $this->postJson($this->base.'/checkout/phone/send', ['phone' => '+201001234567', 'token' => bin2hex(random_bytes(32))])->assertStatus(429);
        for ($i = 0; $i < 2; $i++) {
            $this->travel(61)->seconds();
            $this->send(phone: '٠١٠٠١٢٣٤٥٦٧');
        }
        $this->travel(61)->seconds();
        $this->postJson($this->base.'/checkout/phone/send', ['phone' => '+201001234567', 'token' => bin2hex(random_bytes(32))])->assertStatus(429);
        $this->assertDatabaseCount('store_phone_challenges', 3);
        $this->postJson($this->base.'/checkout/phone/verify', ['token' => $first, 'code' => $this->codes[0]])->assertUnprocessable();
        $this->assertSame('superseded', StorePhoneChallenge::where('token_hash', app(CheckoutPhoneVerification::class)->hash($first))->firstOrFail()->state);
        $this->travelBack();
    }

    public function test_uncertain_and_failed_delivery_never_auto_retry_or_verify(): void
    {
        $this->enable();
        $this->transport = 'uncertain';
        $token = bin2hex(random_bytes(32));
        for ($i = 0; $i < 2; $i++) {
            $this->postJson($this->base.'/checkout/phone/send', ['phone' => '01001234567', 'token' => $token])->assertOk()->assertJsonPath('data.state', 'uncertain');
        }
        $this->assertDatabaseCount('store_phone_challenges', 1);
        $this->assertNull(StorePhoneChallenge::first()->code_hash);
        $this->postJson($this->base.'/checkout/phone/verify', ['token' => $token, 'code' => '123456'])->assertUnprocessable();
        Http::assertSentCount(2);
        $this->travel(61)->seconds();
        $this->transport = 'failed';
        $this->postJson($this->base.'/checkout/phone/send', ['phone' => '01001234567', 'token' => bin2hex(random_bytes(32))])->assertOk()->assertJsonPath('data.state', 'failed');
        Http::assertSentCount(3);
        $this->travelBack();
    }

    public function test_otp_blocks_are_separate_scoped_and_invalidate_existing_proof(): void
    {
        $this->enable();
        $token = $this->proof();
        $endpoint = '/api/v1/my-store/blocked-phones';
        $block = $this->postJson($endpoint, ['phone' => '01001234567'], $this->auth)->assertOk()->json('data');
        $this->getJson('/api/v1/my-store/blocked-phone-numbers', $this->auth)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($endpoint, $this->auth)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/my-store/blocked-phone-numbers/'.$block['id'].'/history', $this->auth)->assertNotFound();
        $this->putJson('/api/v1/my-store/blocked-phone-numbers/'.$block['id'], ['active' => false, 'note' => null, 'version' => 1], $this->auth)->assertNotFound();
        $this->postJson($this->base.'/checkout/phone/send', ['phone' => '01001234567', 'token' => bin2hex(random_bytes(32))])->assertUnprocessable();
        $this->postJson($this->base.'/checkout/phone/verify', ['token' => $token, 'code' => end($this->codes)])->assertUnprocessable();
        $this->postJson($this->base.'/checkout', $this->payload($token))->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
        $this->putJson($this->settings, ['enabled' => false, 'version' => 2], $this->auth)->assertOk();
        $this->postJson($this->base.'/checkout', $this->payload())->assertCreated(); // OTP list is not a purchase ban.
        app(PhoneBlocking::class)->add($this->store, '01001234567', null, $this->store->owner_user_id);
        $this->assertDatabaseCount('store_phone_blocks', 2);
        $this->postJson($this->base.'/checkout', $this->payload())->assertUnprocessable();
    }

    public function test_failed_cart_validation_rolls_back_proof_consumption_and_direct_call_is_guarded(): void
    {
        $this->enable();
        $token = $this->proof();
        $cart = app(CartService::class)->create($this->store, null);
        app(CartService::class)->addItem($cart, $this->product->id, 1);
        try {
            app(CheckoutService::class)->place($this->store, $cart, null, ['name' => 'LOCAL', 'email' => 'otp@example.test', 'phone' => '01001234567'], null, 'cod');
            $this->fail('Direct service bypassed OTP.');
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey('phone_verification', $failure->errors());
        }
        $this->product->update(['price' => 200]);
        try {
            app(CheckoutService::class)->place($this->store, $cart, null, ['name' => 'LOCAL', 'email' => 'otp@example.test', 'phone' => '01001234567', 'phone_verification' => $token], null, 'cod');
            $this->fail('Stale price allowed.');
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey('items', $failure->errors());
        }
        $this->assertSame('verified', StorePhoneChallenge::first()->state);
        $this->assertSame('active', $cart->fresh()->status);
        $this->assertDatabaseCount('store_orders', 0);
        $this->product->update(['price' => 100]);
        app(CheckoutService::class)->place($this->store, $cart, null, ['name' => 'LOCAL', 'email' => 'otp@example.test', 'phone' => '01001234567', 'phone_verification' => $token], null, 'cod');
        $this->assertSame('used', StorePhoneChallenge::first()->state);
    }

    public function test_changed_phone_settings_expired_proof_and_other_store_reject(): void
    {
        $this->enable();
        $token = $this->proof();
        $this->postJson($this->base.'/checkout', $this->payload($token, '+201001234568'))->assertUnprocessable();
        $other = Store::create(['owner_user_id' => $this->store->owner_user_id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-otp', 'status' => 'active', 'phone_otp_configuration' => $this->store->phone_otp_configuration, 'phone_otp_credentials' => $this->store->phone_otp_credentials]);
        StoreDomain::create(['store_id' => $other->id, 'host' => 'other-otp.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->postJson('http://other-otp.sellchase.com/api/v1/storefront/checkout/phone/verify', ['token' => $token, 'code' => end($this->codes)])->assertUnprocessable();
        app(CurrentStore::class)->set($this->store);
        $this->travel(300)->seconds();
        $this->postJson($this->base.'/checkout', $this->payload($token))->assertUnprocessable();
        $this->travelBack();
        $this->putJson($this->settings, ['enabled' => true, 'version' => 2], $this->auth)->assertOk();
        $this->postJson($this->base.'/checkout', $this->payload($token))->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_replay_receipt_and_payment_retry_do_not_require_fresh_otp(): void
    {
        $this->enable();
        $token = $this->proof();
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true, 'credentials' => []]);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $payload = $this->payload($token) + ['payment_method' => 'bank_transfer', 'cart_mode' => 'direct'];
        $first = $this->postJson($this->base.'/checkout', $payload, $headers)->assertCreated();
        $this->travel(301)->seconds();
        $this->postJson($this->base.'/checkout', $payload, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->postJson($this->base.'/checkout/receipt', ['token' => $first->json('receipt.token')])->assertOk();
        $retry = app(PaymentRetryToken::class)->make($this->store->id, $first->json('data.id'));
        $this->postJson($this->base.'/checkout/payment/retry', ['token' => $retry])->assertOk();
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseCount('store_payment_transactions', 1);
        $this->travelBack();
    }

    public function test_digital_hidden_phone_is_forced_and_no_code_allocated_without_proof(): void
    {
        $this->enable();
        $fields = app(CheckoutFields::class)->defaults(false);
        foreach ($fields as &$field) {
            if ($field['key'] === 'phone') {
                $field['enabled'] = $field['required'] = false;
            }
        }
        unset($field);
        $this->store->update(['checkout_fields' => $fields]);
        $this->product->update(['digital_type' => 'codes', 'track_inventory' => false]);
        ProductDigitalCode::create(['store_id' => $this->store->id, 'product_id' => $this->product->id, 'value' => 'LOCAL-OTP-product-code', 'digest' => hash('sha256', 'LOCAL-OTP-product-code')]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true, 'credentials' => []]);
        $response = $this->getJson($this->base.'/checkout/fields?product_ids[]='.$this->product->id)->assertOk()->assertJsonPath('phone_verification.required', true);
        $phone = collect($response->json('data'))->firstWhere('key', 'phone');
        $this->assertTrue($phone['required']);
        $this->assertTrue($phone['phone_verification_required']);
        $this->postJson($this->base.'/checkout?lang=ar', $this->payload() + ['payment_method' => 'bank_transfer'])->assertUnprocessable()->assertJsonValidationErrors('phone_verification');
        $this->assertNull(ProductDigitalCode::first()->store_order_item_id);
        $token = $this->proof();
        $this->postJson($this->base.'/checkout', $this->payload($token) + ['payment_method' => 'bank_transfer'])->assertCreated();
        $this->assertNotNull(ProductDigitalCode::first()->store_order_item_id);
    }

    public function test_customer_signin_does_not_bypass_phone_verification(): void
    {
        $this->enable();
        StoreCustomer::create(['store_id' => $this->store->id, 'name' => 'LOCAL shopper', 'email' => 'shopper@example.test', 'password' => bcrypt('LocalCustomerPassword1!'), 'is_active' => true]);
        $login = $this->postJson($this->base.'/auth/login', ['email' => 'shopper@example.test', 'password' => 'LocalCustomerPassword1!'])->assertOk();
        $headers = ['Authorization' => 'Bearer '.$login->json('data.token')];
        $this->postJson($this->base.'/checkout', $this->payload(), $headers)->assertUnprocessable()->assertJsonValidationErrors('phone_verification');
        $token = $this->proof();
        $this->postJson($this->base.'/checkout', $this->payload($token), $headers)->assertCreated();
        $this->assertDatabaseCount('store_orders', 1);
    }

    public function test_pruning_removes_unused_records_and_preserves_order_audit(): void
    {
        $this->enable();
        $token = $this->proof();
        $this->postJson($this->base.'/checkout', $this->payload($token))->assertCreated();
        $this->send(phone: '+201001234568');
        StorePhoneChallenge::withoutGlobalScopes()->update(['created_at' => now()->subDays(31), 'expires_at' => now()->subDays(30)]);
        $this->artisan('checkout-otp:prune')->assertExitCode(0);
        $this->assertDatabaseCount('store_phone_challenges', 1);
        $remaining = StorePhoneChallenge::withoutGlobalScopes()->first();
        $this->assertSame('used', $remaining->state);
        $this->assertNotNull($remaining->store_order_id);
        $this->assertNull($remaining->code_hash);
    }
}
