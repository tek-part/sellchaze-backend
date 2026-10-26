<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreCustomerPasswordReset;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StoreOrderFulfillment;
use App\Models\StoreOrderStatusChange;
use App\Models\StorePhoneChallenge;
use App\Models\User;
use App\Services\Commerce\CheckoutPhoneVerification;
use App\Services\Commerce\CustomerLoginMethods;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G2 (#48): WhatsApp-code and Google sign-in for storefront customers, merchant login-method
 * settings and guest order tracking — store-scoped, enumeration-safe and off by default.
 */
class StorefrontCustomerLoginTest extends TestCase
{
    use RefreshDatabase;

    private Store $alpha;

    private Store $beta;

    private array $owner;

    private array $codes = [];

    private array $claims = [];

    private string $settings = '/api/v1/my-store/customer-login';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['app.url' => 'https://api.sellchase.test', 'services.google.client_id' => 'LOCAL-client.apps.googleusercontent.com',
            'services.google.client_secret' => 'LOCAL-google-secret']);
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $this->owner = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($user)];
        $this->alpha = $this->makeStore('login-alpha', $user);
        $this->beta = $this->makeStore('login-beta', User::factory()->create());
        app(CurrentStore::class)->set($this->alpha);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/session/info')) {
                return Http::response(['status' => 'WORKING']);
            }
            if (str_contains($request->url(), 'api.wawp.net')) {
                preg_match('/\b([0-9]{6})\b/', $request['message'] ?? '', $match);
                $this->codes[] = $match[1] ?? '';

                return Http::response(['id' => ['_serialized' => 'LOCAL-login-message']]);
            }
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return $request['redirect_uri'] === 'https://api.sellchase.test/api/v1/storefront-oauth/google/callback' && $request['code'] === 'LOCAL-google-code'
                    ? Http::response(['id_token' => 'LOCAL-id-token', 'access_token' => 'LOCAL-access']) : Http::response(['error' => 'invalid_grant'], 400);
            }
            if (str_starts_with($request->url(), 'https://oauth2.googleapis.com/tokeninfo')) {
                return Http::response($this->claims);
            }

            return Http::response([], 404);
        });
    }

    private function makeStore(string $slug, User $owner): Store
    {
        $store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => Str::title($slug), 'slug' => $slug,
            'currency' => 'EGP', 'status' => 'active', 'default_locale' => 'en', 'supported_locales' => ['en', 'ar']]);
        StoreDomain::create(['store_id' => $store->id, 'host' => "{$slug}.sellchase.com", 'type' => 'subdomain', 'is_primary' => true]);

        return $store;
    }

    private function api(Store $store, string $path): string
    {
        return "http://{$store->slug}.sellchase.com/api/v1/storefront/{$path}";
    }

    /** A verified WhatsApp connection (checkout OTP itself stays off) and, optionally, phone login on. */
    private function connect(Store $store, bool $phoneLogin = true): void
    {
        $store->update(['phone_otp_credentials' => ['instance_id' => 'LOCAL-instance', 'access_token' => 'LOCAL-token'],
            'phone_otp_configuration' => ['enabled' => false, 'version' => 2, 'generation' => (string) Str::uuid(), 'verified' => true],
            'customer_login_configuration' => ['phone_otp' => $phoneLogin, 'google' => false, 'version' => 1]]);
    }

    private function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function send(Store $store, string $phone = '01001234567'): string
    {
        $token = $this->token();
        $this->postJson($this->api($store, 'auth/phone/send'), ['phone' => $phone, 'token' => $token])->assertOk()->assertJsonPath('data.state', 'sent');

        return $token;
    }

    public function test_merchant_settings_are_permissioned_off_by_default_and_need_a_ready_method(): void
    {
        $this->getJson($this->settings)->assertUnauthorized();
        $employee = User::factory()->create(['parent_user_id' => $this->alpha->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $employee->givePermissionTo('store.orders.manage');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee)];
        $this->getJson('/api/v1/stores/'.$this->alpha->id.'/customer-login', $headers)->assertForbidden();
        $this->putJson('/api/v1/stores/'.$this->alpha->id.'/customer-login', ['phone_otp' => false, 'google' => false, 'version' => 1], $headers)->assertForbidden();

        $this->getJson($this->settings, $this->owner)->assertOk()->assertJsonPath('data.phone_otp', false)->assertJsonPath('data.google', false)
            ->assertJsonPath('data.whatsapp_ready', false)->assertJsonPath('data.google_ready', false);
        $this->getJson($this->api($this->alpha, 'auth/methods'))->assertOk()->assertJsonPath('data.email', true)
            ->assertJsonPath('data.phone', false)->assertJsonPath('data.google', false);
        $this->putJson($this->settings, ['phone_otp' => true, 'google' => false, 'version' => 1], $this->owner)->assertUnprocessable()->assertJsonValidationErrors('phone_otp');
        $this->putJson($this->settings, ['phone_otp' => false, 'google' => true, 'version' => 1], $this->owner)->assertUnprocessable()->assertJsonValidationErrors('google');

        $this->connect($this->alpha, false);
        Setting::set(CustomerLoginMethods::GOOGLE_SETTING, '1');
        $this->putJson($this->settings, ['phone_otp' => true, 'google' => true, 'version' => 1], $this->owner)->assertOk()
            ->assertJsonPath('data.phone_otp_active', true)->assertJsonPath('data.google_active', true)->assertJsonPath('data.version', 2);
        $this->putJson($this->settings, ['phone_otp' => false, 'google' => false, 'version' => 1], $this->owner)->assertStatus(409);
        $this->getJson($this->api($this->alpha, 'auth/methods'))->assertJsonPath('data.phone', true)->assertJsonPath('data.google', true);
        // Another store is untouched.
        $this->getJson($this->api($this->beta, 'auth/methods'))->assertJsonPath('data.phone', false)->assertJsonPath('data.google', false);
        // The administrator switch hides Google everywhere again.
        Setting::set(CustomerLoginMethods::GOOGLE_SETTING, '0');
        $this->getJson($this->api($this->alpha, 'auth/methods'))->assertJsonPath('data.google', false);
    }

    public function test_phone_code_is_unavailable_until_enabled_and_sending_does_not_reveal_accounts(): void
    {
        $this->postJson($this->api($this->alpha, 'auth/phone/send'), ['phone' => '01001234567', 'token' => $this->token()])->assertUnprocessable();
        $this->connect($this->alpha);
        StoreCustomer::create(['store_id' => $this->alpha->id, 'name' => 'Known', 'email' => 'known@example.test', 'password' => bcrypt('password123'), 'phone' => '01001234567', 'is_active' => true]);
        $known = $this->postJson($this->api($this->alpha, 'auth/phone/send'), ['phone' => '01001234567', 'token' => $this->token()])->assertOk()->json('data');
        $unknown = $this->postJson($this->api($this->alpha, 'auth/phone/send'), ['phone' => '01001234568', 'token' => $this->token()])->assertOk()->json('data');
        $this->assertSame(array_keys($known), array_keys($unknown));
        $this->assertSame($known['state'], $unknown['state']);
        $this->assertCount(2, $this->codes);
        $this->assertSame('login', StorePhoneChallenge::withoutGlobalScopes()->latest('id')->value('purpose'));
        $this->assertNull(StorePhoneChallenge::withoutGlobalScopes()->where('code_hash', $this->codes[0])->first(), 'codes are stored hashed');
        // One resend a minute per phone.
        $this->postJson($this->api($this->alpha, 'auth/phone/send'), ['phone' => '01001234567', 'token' => $this->token()])->assertStatus(429);
        $this->postJson($this->api($this->alpha, 'auth/phone/send'), ['phone' => 'not a phone', 'token' => $this->token()])->assertUnprocessable();
    }

    public function test_new_phone_signs_up_after_a_name_and_wrong_codes_lock_the_challenge(): void
    {
        $this->connect($this->alpha);
        $token = $this->send($this->alpha);
        $code = end($this->codes);
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->api($this->alpha, 'auth/phone/verify'), ['token' => $token, 'code' => $wrong])->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        $this->postJson($this->api($this->alpha, 'auth/phone/verify'), ['token' => $token, 'code' => $code])->assertUnprocessable();

        $this->travel(2)->minutes();
        $token = $this->send($this->alpha);
        // Without a code nothing is proven yet.
        $this->postJson($this->api($this->alpha, 'auth/phone/verify'), ['token' => $token, 'name' => 'Nour'])->assertUnprocessable();
        $this->postJson($this->api($this->alpha, 'auth/phone/verify'), ['token' => $token, 'code' => end($this->codes)])->assertOk()
            ->assertJsonPath('data.status', 'name_required')->assertJsonMissingPath('token');
        $this->assertSame(0, StoreCustomer::withoutGlobalScopes()->count());
        $auth = $this->postJson($this->api($this->alpha, 'auth/phone/verify'), ['token' => $token, 'name' => 'Nour'])->assertOk()
            ->assertJsonPath('data.name', 'Nour')->assertJsonPath('data.email', null)->json('token');
        $this->getJson($this->api($this->alpha, 'account'), ['Authorization' => "Bearer {$auth}"])->assertOk()
            ->assertJsonPath('data.phone', '+201001234567')->assertJsonPath('data.phone_verified', true)->assertJsonPath('data.has_password', false);
        // The proof is spent.
        $this->postJson($this->api($this->alpha, 'auth/phone/verify'), ['token' => $token, 'name' => 'Nour'])->assertUnprocessable();
        // A password-less account sets its first password without a current one, then needs it.
        $this->putJson($this->api($this->alpha, 'account/password'), ['password' => 'password123', 'password_confirmation' => 'password123'], ['Authorization' => "Bearer {$auth}"])->assertOk();
        $this->putJson($this->api($this->alpha, 'account/password'), ['password' => 'password456', 'password_confirmation' => 'password456'], ['Authorization' => "Bearer {$auth}"])->assertUnprocessable();
        $this->getJson($this->api($this->alpha, 'account'), ['Authorization' => "Bearer {$auth}"])->assertJsonPath('data.has_password', true);
        // The customer's token does not open another store.
        $this->getJson($this->api($this->beta, 'account'), ['Authorization' => "Bearer {$auth}"])->assertUnauthorized();
        // E-mail login is unaffected for this password-less row.
        $this->postJson($this->api($this->alpha, 'auth/login'), ['email' => 'nobody@example.test', 'password' => 'password123'])->assertUnprocessable();
    }

    public function test_a_typed_phone_never_identifies_an_account_until_its_owner_confirms_it(): void
    {
        $this->connect($this->alpha);
        $this->connect($this->beta);
        // Someone registers with the victim's number typed in; it must not capture the victim's sign-in.
        $squatter = StoreCustomer::create(['store_id' => $this->alpha->id, 'name' => 'Squatter', 'email' => 'squatter@example.test', 'password' => bcrypt('password123'), 'phone' => '0100 123 4567', 'is_active' => true]);
        $foreign = StoreCustomer::withoutGlobalScopes()->create(['store_id' => $this->beta->id, 'name' => 'Other store', 'email' => 'known@example.test', 'password' => bcrypt('password123'), 'phone' => '01001234567', 'phone_normalized' => '+201001234567', 'is_active' => true]);

        $token = $this->send($this->alpha, '+20 100 123 4567');
        // A challenge belongs to its store.
        $this->postJson($this->api($this->beta, 'auth/phone/verify'), ['token' => $token, 'code' => end($this->codes)])->assertUnprocessable();
        $this->postJson($this->api($this->alpha, 'auth/phone/verify'), ['token' => $token, 'code' => end($this->codes)])->assertOk()
            ->assertJsonPath('data.status', 'name_required');
        $owner = $this->postJson($this->api($this->alpha, 'auth/phone/verify'), ['token' => $token, 'name' => 'Phone owner'])->assertOk()
            ->assertJsonPath('data.name', 'Phone owner')->assertJsonPath('data.phone_verified', true);
        $this->assertNotSame($squatter->id, $owner->json('data.id'));
        $this->assertNull($squatter->fresh()->phone_normalized);
        $this->assertSame('+201001234567', $foreign->fresh()->phone_normalized, 'the other store keeps its own identity');

        // The squatter cannot confirm a number already confirmed on another account.
        $squatterToken = $this->postJson($this->api($this->alpha, 'auth/login'), ['email' => 'squatter@example.test', 'password' => 'password123'])->assertOk()->json('token');
        $this->travel(2)->minutes();
        $confirm = $this->token();
        $this->postJson($this->api($this->alpha, 'account/phone/send'), ['phone' => '01001234567', 'token' => $confirm], ['Authorization' => "Bearer {$squatterToken}"])->assertOk();
        $this->postJson($this->api($this->alpha, 'account/phone/verify'), ['token' => $confirm, 'code' => end($this->codes)], ['Authorization' => "Bearer {$squatterToken}"])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertNull($squatter->fresh()->phone_normalized);
    }

    public function test_signed_in_customer_confirms_their_phone_and_then_signs_in_with_it(): void
    {
        $this->connect($this->alpha);
        $customer = StoreCustomer::create(['store_id' => $this->alpha->id, 'name' => 'Known', 'email' => 'known@example.test', 'password' => bcrypt('password123'), 'phone' => '0100 123 4567', 'is_active' => true]);
        $auth = $this->postJson($this->api($this->alpha, 'auth/login'), ['email' => 'known@example.test', 'password' => 'password123'])->assertOk()->json('token');
        $headers = ['Authorization' => "Bearer {$auth}"];
        $this->postJson($this->api($this->alpha, 'account/phone/send'), ['phone' => '01001234567', 'token' => $this->token()])->assertUnauthorized();

        $token = $this->token();
        $this->postJson($this->api($this->alpha, 'account/phone/send'), ['phone' => '01001234567', 'token' => $token], $headers)->assertOk()->assertJsonPath('data.state', 'sent');
        $this->assertSame('verify_phone', StorePhoneChallenge::withoutGlobalScopes()->latest('id')->value('purpose'));
        // A confirmation code is not a sign-in code.
        $this->postJson($this->api($this->alpha, 'auth/phone/verify'), ['token' => $token, 'code' => end($this->codes)])->assertUnprocessable();
        $this->postJson($this->api($this->alpha, 'account/phone/verify'), ['token' => $token, 'code' => '00000'], $headers)->assertUnprocessable();
        $this->postJson($this->api($this->alpha, 'account/phone/verify'), ['token' => $token, 'code' => end($this->codes)], $headers)->assertOk()
            ->assertJsonPath('data.id', $customer->id)->assertJsonPath('data.phone_verified', true)->assertJsonPath('data.phone', '+201001234567');
        // Nothing else changes: the session and password still work.
        $this->getJson($this->api($this->alpha, 'account'), $headers)->assertOk();
        $this->postJson($this->api($this->alpha, 'auth/login'), ['email' => 'known@example.test', 'password' => 'password123'])->assertOk();

        $this->travel(2)->minutes();
        $login = $this->send($this->alpha);
        $this->postJson($this->api($this->alpha, 'auth/phone/verify'), ['token' => $login, 'code' => end($this->codes)])->assertOk()
            ->assertJsonPath('data.id', $customer->id);
        $this->assertSame(1, StoreCustomer::withoutGlobalScopes()->count());

        // Typing another number drops the confirmed sign-in phone.
        $this->patchJson($this->api($this->alpha, 'account'), ['phone' => '01001234569'], $headers)->assertOk();
        $this->assertNull($customer->fresh()->phone_normalized);
    }

    public function test_inactive_accounts_and_checkout_challenges_cannot_sign_in(): void
    {
        $this->connect($this->alpha);
        StoreCustomer::create(['store_id' => $this->alpha->id, 'name' => 'Blocked', 'email' => 'blocked@example.test', 'password' => bcrypt('password123'),
            'phone' => '01001234567', 'phone_normalized' => '+201001234567', 'is_active' => false]);
        $token = $this->send($this->alpha);
        $this->postJson($this->api($this->alpha, 'auth/phone/verify'), ['token' => $token, 'code' => end($this->codes)])->assertUnprocessable();

        $checkout = $this->token();
        $otp = app(CheckoutPhoneVerification::class);
        StorePhoneChallenge::create(['store_id' => $this->alpha->id, 'phone_normalized' => '+201001234570', 'token_hash' => $otp->hash($checkout),
            'code_hash' => null, 'ip_hash' => $otp->hash('127.0.0.1'), 'generation' => $otp->configuration($this->alpha->fresh())['generation'],
            'expires_at' => now()->addMinutes(5), 'resend_at' => now()->addMinute(), 'state' => 'verified', 'verified_at' => now(), 'proof_expires_at' => now()->addMinutes(5)]);
        $this->postJson($this->api($this->alpha, 'auth/phone/verify'), ['token' => $checkout, 'name' => 'Checkout'])->assertUnprocessable();
        $this->assertSame(1, StoreCustomer::withoutGlobalScopes()->count());

        // And the other way round: checkout never accepts a sign-in challenge as phone proof.
        $this->alpha->update(['phone_otp_configuration' => array_replace($this->alpha->fresh()->phone_otp_configuration, ['enabled' => true])]);
        $login = $this->send($this->alpha, '01001234572');
        $this->postJson($this->api($this->alpha, 'checkout/phone/verify'), ['token' => $login, 'code' => end($this->codes)])->assertUnprocessable();
        $this->assertSame('sent', StorePhoneChallenge::withoutGlobalScopes()->where('token_hash', $otp->hash($login))->value('state'));
        $this->assertNull(StorePhoneChallenge::withoutGlobalScopes()->where('token_hash', $otp->hash($login))->value('verified_at'));
        $this->alpha->update(['phone_otp_configuration' => array_replace($this->alpha->fresh()->phone_otp_configuration, ['enabled' => false])]);

        // Turning the method off stops verification of codes already sent.
        $token = $this->send($this->alpha, '01001234571');
        $this->alpha->update(['customer_login_configuration' => ['phone_otp' => false, 'google' => false, 'version' => 3]]);
        $this->postJson($this->api($this->alpha, 'auth/phone/verify'), ['token' => $token, 'code' => end($this->codes), 'name' => 'Late'])->assertUnprocessable();
    }

    /** @return array{0: string, 1: array<string, string>} verifier and the Google consent query */
    private function startGoogle(Store $store): array
    {
        $verifier = Str::random(64);
        $url = $this->getJson($this->api($store, 'auth/google/start?challenge='.hash('sha256', $verifier)))->assertOk()->json('data.url');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('LOCAL-client.apps.googleusercontent.com', $query['client_id']);
        $this->assertSame('https://api.sellchase.test/api/v1/storefront-oauth/google/callback', $query['redirect_uri']);
        $this->claims = ['aud' => 'LOCAL-client.apps.googleusercontent.com', 'iss' => 'https://accounts.google.com', 'exp' => (string) (time() + 600),
            'nonce' => $query['nonce'], 'email_verified' => 'true', 'sub' => '1100220033', 'email' => 'Ada@Example.test', 'name' => 'Ada Lovelace'];

        return [$verifier, $query];
    }

    private function googleReturn(array $query, string $code = 'LOCAL-google-code'): string
    {
        return (string) $this->get('/api/v1/storefront-oauth/google/callback?'.http_build_query(['code' => $code, 'state' => $query['state']]))
            ->assertRedirect()->headers->get('Location');
    }

    private function enableGoogle(Store $store): void
    {
        Setting::set(CustomerLoginMethods::GOOGLE_SETTING, '1');
        $store->update(['customer_login_configuration' => ['phone_otp' => false, 'google' => true, 'version' => 2]]);
    }

    public function test_google_sign_in_is_hidden_until_the_platform_and_the_store_enable_it(): void
    {
        $challenge = hash('sha256', 'x');
        $this->getJson($this->api($this->alpha, 'auth/google/start?challenge='.$challenge))->assertNotFound();
        $this->alpha->update(['customer_login_configuration' => ['phone_otp' => false, 'google' => true, 'version' => 2]]);
        $this->getJson($this->api($this->alpha, 'auth/google/start?challenge='.$challenge))->assertNotFound();
        Setting::set(CustomerLoginMethods::GOOGLE_SETTING, '1');
        config(['services.google.client_secret' => '']);
        $this->getJson($this->api($this->alpha, 'auth/google/start?challenge='.$challenge))->assertNotFound();
        config(['services.google.client_secret' => 'LOCAL-google-secret']);
        $this->getJson($this->api($this->alpha, 'auth/google/start?challenge='.$challenge))->assertOk();
        $this->getJson($this->api($this->beta, 'auth/google/start?challenge='.$challenge))->assertNotFound();
        $this->getJson($this->api($this->alpha, 'auth/google/start?challenge=short'))->assertUnprocessable();
    }

    public function test_google_callback_returns_a_one_time_code_only_the_starting_browser_can_exchange(): void
    {
        $this->enableGoogle($this->alpha);
        [$verifier, $query] = $this->startGoogle($this->alpha);
        $location = $this->googleReturn($query);
        $this->assertMatchesRegularExpression('~^http://login-alpha\.sellchase\.com/login/google#code=[A-Za-z0-9]{64}$~', $location);
        $code = Str::after($location, '#code=');
        $customer = StoreCustomer::withoutGlobalScopes()->where('store_id', $this->alpha->id)->sole();
        $this->assertSame('ada@example.test', $customer->email);
        $this->assertSame('1100220033', $customer->google_sub);
        $this->assertNotNull($customer->email_verified_at);
        $this->assertNull($customer->password);

        // Another store host cannot redeem it; the right host with the wrong verifier burns it.
        $this->postJson($this->api($this->beta, 'auth/google/exchange'), ['code' => $code, 'verifier' => $verifier])->assertUnprocessable();
        $auth = $this->postJson($this->api($this->alpha, 'auth/google/exchange'), ['code' => $code, 'verifier' => $verifier])->assertOk()
            ->assertJsonPath('data.google_linked', true)->json('token');
        $this->getJson($this->api($this->alpha, 'account'), ['Authorization' => "Bearer {$auth}"])->assertOk()->assertJsonPath('data.name', 'Ada Lovelace');
        $this->postJson($this->api($this->alpha, 'auth/google/exchange'), ['code' => $code, 'verifier' => $verifier])->assertUnprocessable();

        [$verifier, $query] = $this->startGoogle($this->alpha);
        $code = Str::after($this->googleReturn($query), '#code=');
        $this->postJson($this->api($this->alpha, 'auth/google/exchange'), ['code' => $code, 'verifier' => Str::random(64)])->assertUnprocessable();
        $this->postJson($this->api($this->alpha, 'auth/google/exchange'), ['code' => $code, 'verifier' => $verifier])->assertUnprocessable();
        $this->assertSame(1, StoreCustomer::withoutGlobalScopes()->count(), 'the same Google identity signs in to the same account');

        // Codes expire after one minute.
        [$verifier, $query] = $this->startGoogle($this->alpha);
        $code = Str::after($this->googleReturn($query), '#code=');
        $this->travel(61)->seconds();
        $this->postJson($this->api($this->alpha, 'auth/google/exchange'), ['code' => $code, 'verifier' => $verifier])->assertUnprocessable();
    }

    public function test_google_takes_back_an_unverified_email_account_from_whoever_registered_it(): void
    {
        $this->enableGoogle($this->alpha);
        // An attacker pre-registers the victim's address with a password they know and keeps a session.
        $attacker = $this->postJson($this->api($this->alpha, 'auth/register'), ['name' => 'Attacker', 'email' => 'ada@example.test', 'password' => 'attacker-pass'])->assertCreated();
        $attackerToken = $attacker->json('token');
        $existing = StoreCustomer::withoutGlobalScopes()->findOrFail($attacker->json('data.id'));
        $this->postJson($this->api($this->alpha, 'auth/forgot-password'), ['email' => 'ada@example.test'])->assertOk();
        $foreign = StoreCustomer::withoutGlobalScopes()->create(['store_id' => $this->beta->id, 'name' => 'Ada B', 'email' => 'ada@example.test', 'password' => bcrypt('password123'), 'is_active' => true]);
        $foreignToken = $this->postJson($this->api($this->beta, 'auth/login'), ['email' => 'ada@example.test', 'password' => 'password123'])->assertOk()->json('token');

        [$verifier, $query] = $this->startGoogle($this->alpha);
        $code = Str::after($this->googleReturn($query), '#code=');
        $victim = $this->postJson($this->api($this->alpha, 'auth/google/exchange'), ['code' => $code, 'verifier' => $verifier])->assertOk()
            ->assertJsonPath('data.id', $existing->id)->assertJsonPath('data.has_password', false)->json('token');
        $existing->refresh();
        $this->assertSame('1100220033', $existing->google_sub);
        $this->assertNull($existing->password);
        $this->assertNotNull($existing->email_verified_at);
        // The attacker's session, password and pending reset link are gone; the victim's session works.
        $this->getJson($this->api($this->alpha, 'account'), ['Authorization' => "Bearer {$attackerToken}"])->assertUnauthorized();
        $this->postJson($this->api($this->alpha, 'auth/login'), ['email' => 'ada@example.test', 'password' => 'attacker-pass'])->assertUnprocessable();
        $this->assertSame(0, StoreCustomerPasswordReset::withoutGlobalScopes()->where('store_customer_id', $existing->id)->count());
        $this->getJson($this->api($this->alpha, 'account'), ['Authorization' => "Bearer {$victim}"])->assertOk();
        // Other stores are untouched.
        $this->assertNull($foreign->fresh()->google_sub);
        $this->getJson($this->api($this->beta, 'account'), ['Authorization' => "Bearer {$foreignToken}"])->assertOk();

        // Once verified, a later Google sign-in keeps the owner's own sessions and password.
        $this->putJson($this->api($this->alpha, 'account/password'), ['password' => 'owner-pass-1', 'password_confirmation' => 'owner-pass-1'], ['Authorization' => "Bearer {$victim}"])->assertOk();
        [$verifier, $query] = $this->startGoogle($this->alpha);
        $code = Str::after($this->googleReturn($query), '#code=');
        $this->postJson($this->api($this->alpha, 'auth/google/exchange'), ['code' => $code, 'verifier' => $verifier])->assertOk()->assertJsonPath('data.has_password', true);
        $this->getJson($this->api($this->alpha, 'account'), ['Authorization' => "Bearer {$victim}"])->assertOk();
    }

    public function test_google_links_a_verified_email_account_and_rejects_bad_tokens(): void
    {
        $this->enableGoogle($this->alpha);
        $existing = StoreCustomer::create(['store_id' => $this->alpha->id, 'name' => 'Ada', 'email' => 'ada@example.test', 'password' => bcrypt('password123'), 'email_verified_at' => now(), 'is_active' => true]);
        $session = $this->postJson($this->api($this->alpha, 'auth/login'), ['email' => 'ada@example.test', 'password' => 'password123'])->assertOk()->json('token');
        [$verifier, $query] = $this->startGoogle($this->alpha);
        $code = Str::after($this->googleReturn($query), '#code=');
        $this->postJson($this->api($this->alpha, 'auth/google/exchange'), ['code' => $code, 'verifier' => $verifier])->assertOk()
            ->assertJsonPath('data.id', $existing->id)->assertJsonPath('data.has_password', true);
        $this->assertSame('1100220033', $existing->fresh()->google_sub);
        $this->getJson($this->api($this->alpha, 'account'), ['Authorization' => "Bearer {$session}"])->assertOk();

        $failed = 'http://login-alpha.sellchase.com/login?google=failed';
        foreach ([['email_verified' => 'false'], ['aud' => 'someone-else'], ['nonce' => 'replayed'], ['iss' => 'https://evil.test'], ['exp' => (string) (time() - 10)]] as $bad) {
            [, $query] = $this->startGoogle($this->alpha);
            $this->claims = array_replace($this->claims, $bad, ['sub' => 'other-'.Str::random(6), 'email' => 'new-'.Str::random(6).'@example.test']);
            $this->assertSame($failed, $this->googleReturn($query));
        }
        [, $query] = $this->startGoogle($this->alpha);
        $this->assertSame($failed, $this->googleReturn($query, 'LOCAL-wrong-code'));
        // A different Google identity cannot take over an account already linked to another one.
        [, $query] = $this->startGoogle($this->alpha);
        $this->claims['sub'] = '9999';
        $this->assertSame($failed, $this->googleReturn($query));
        $this->assertSame(1, StoreCustomer::withoutGlobalScopes()->where('store_id', $this->alpha->id)->count());
    }

    public function test_google_callback_rejects_tampered_expired_or_withdrawn_state(): void
    {
        $this->enableGoogle($this->alpha);
        [, $query] = $this->startGoogle($this->alpha);
        [$body, $mac] = explode('.', $query['state']);
        $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        $payload['o'] = 'https://evil.example';
        $forged = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=').'.'.$mac;
        $this->get('/api/v1/storefront-oauth/google/callback?'.http_build_query(['code' => 'LOCAL-google-code', 'state' => $forged]))->assertStatus(400);
        $this->get('/api/v1/storefront-oauth/google/callback?code=LOCAL-google-code')->assertStatus(400);
        $this->travel(11)->minutes();
        $this->get('/api/v1/storefront-oauth/google/callback?'.http_build_query(['code' => 'LOCAL-google-code', 'state' => $query['state']]))->assertStatus(400);
        $this->travelBack();
        [, $query] = $this->startGoogle($this->alpha);
        $this->alpha->update(['customer_login_configuration' => ['phone_otp' => false, 'google' => false, 'version' => 3]]);
        $this->get('/api/v1/storefront-oauth/google/callback?'.http_build_query(['code' => 'LOCAL-google-code', 'state' => $query['state']]))->assertStatus(400);
        // The user cancelled on Google's screen.
        $this->enableGoogle($this->alpha);
        [, $query] = $this->startGoogle($this->alpha);
        $this->assertSame('http://login-alpha.sellchase.com/login?google=failed', (string) $this->get('/api/v1/storefront-oauth/google/callback?'.http_build_query(['error' => 'access_denied', 'state' => $query['state']]))->headers->get('Location'));
        $this->assertSame(0, StoreCustomer::withoutGlobalScopes()->count());
    }

    public function test_admin_google_settings_show_the_storefront_callback_and_switch(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $admin->assignRole('Admin');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($admin)];
        $this->getJson('/api/v1/admin/settings/google', $headers)->assertOk()
            ->assertJsonPath('storefront_callback_url', 'https://api.sellchase.test/api/v1/storefront-oauth/google/callback')
            ->assertJsonPath('storefront_enabled', false);
        $this->putJson('/api/v1/admin/settings/google', ['client_id' => 'LOCAL-client.apps.googleusercontent.com', 'client_secret' => 'LOCAL-google-secret', 'storefront_enabled' => true], $headers)->assertOk()
            ->assertJsonPath('storefront_enabled', true)->assertJsonPath('storefront_ready', true);
        $this->assertTrue(CustomerLoginMethods::googleReady());
        $this->putJson('/api/v1/admin/settings/google', ['client_id' => 'x', 'storefront_enabled' => true], $this->owner)->assertForbidden();
    }

    /** An order of the alpha store with a shipment, a status transition and a private note. */
    private function order(): StoreOrder
    {
        $order = StoreOrder::create(['store_id' => $this->alpha->id, 'order_number' => 'TRK-1001', 'status' => 'shipped', 'currency' => 'EGP',
            'customer_name' => 'Synthetic buyer', 'customer_email' => 'Buyer@Example.test', 'customer_phone' => '01001234567', 'customer_phone_normalized' => '+201001234567',
            'shipping_address' => ['line1' => 'LOCAL private street', 'city' => 'Cairo'], 'notes' => 'LOCAL customer note',
            'subtotal' => '200.00', 'shipping_total' => '50.00', 'discount_total' => '0.00', 'tax_total' => '0.00', 'grand_total' => '250.00',
            'payment_method' => 'cod', 'payment_status' => 'pending', 'fulfillment_status' => 'fulfilled', 'placed_at' => now()->subDay()]);
        $item = $order->items()->create(['store_id' => $this->alpha->id, 'name' => 'Shirt', 'unit_price' => 100, 'quantity' => 2, 'line_total' => 200]);
        StoreOrderStatusChange::create(['store_id' => $this->alpha->id, 'store_order_id' => $order->id, 'from_status' => 'pending', 'to_status' => 'shipped', 'notes' => 'LOCAL transition reason']);
        StoreOrderStatusChange::create(['store_id' => $this->alpha->id, 'store_order_id' => $order->id, 'from_status' => 'shipped', 'to_status' => 'shipped', 'notes' => 'LOCAL private note']);
        StoreOrderFulfillment::create(['store_id' => $this->alpha->id, 'store_order_id' => $order->id, 'status' => 'in_transit', 'carrier' => 'aramex',
            'tracking_number' => 'AR123', 'items' => [['store_order_item_id' => $item->id, 'quantity' => 2]], 'shipped_at' => now(), 'note' => 'LOCAL fulfilment note']);

        return $order;
    }

    public function test_guest_tracking_needs_the_order_contact_and_never_leaks_private_details(): void
    {
        $order = $this->order();
        $response = $this->postJson($this->api($this->alpha, 'orders/track'), ['order_number' => 'TRK-1001', 'contact' => '+20 100 123 4567'])->assertOk()
            ->assertJsonPath('data.number', 'TRK-1001')->assertJsonPath('data.status', 'shipped')->assertJsonPath('data.grand_total', '250.00')
            ->assertJsonPath('data.items.0.name', 'Shirt')->assertJsonPath('data.fulfillments.0.tracking_number', 'AR123')
            ->assertJsonPath('data.fulfillments.0.carrier_name', 'Aramex')->assertJsonPath('data.timeline.0.status', 'placed')
            ->assertJsonPath('data.timeline.1.status', 'shipped')->assertJsonCount(2, 'data.timeline');
        $body = $response->getContent();
        foreach (['LOCAL private street', 'LOCAL customer note', 'LOCAL private note', 'LOCAL transition reason', 'LOCAL fulfilment note', 'Synthetic buyer', '1234567', 'Buyer@'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $body);
        }
        $this->postJson($this->api($this->alpha, 'orders/track'), ['order_number' => 'TRK-1001', 'contact' => ' buyer@example.TEST '])->assertOk();

        $message = $this->postJson($this->api($this->alpha, 'orders/track'), ['order_number' => 'TRK-1001', 'contact' => '01001234568'])->assertNotFound()->json('message');
        $this->assertSame($message, $this->postJson($this->api($this->alpha, 'orders/track'), ['order_number' => 'TRK-9999', 'contact' => '01001234567'])->assertNotFound()->json('message'));
        $this->assertSame($message, $this->postJson($this->api($this->beta, 'orders/track'), ['order_number' => 'TRK-1001', 'contact' => '01001234567'])->assertNotFound()->json('message'));
        $this->postJson($this->api($this->alpha, 'orders/track'), ['order_number' => 'TRK-1001', 'contact' => 'other@example.test'])->assertNotFound();
        $this->postJson($this->api($this->alpha, 'orders/track'), ['order_number' => 'TRK 1001; drop', 'contact' => 'x'])->assertUnprocessable();
        $this->assertSame('TRK-1001', $order->order_number);
    }

    public function test_guest_tracking_locks_an_order_number_after_repeated_misses(): void
    {
        $this->order();
        for ($i = 0; $i < 7; $i++) {
            // Route throttle is 10/minute; stay under it while spending the per-order budget.
            $this->postJson($this->api($this->alpha, 'orders/track'), ['order_number' => 'TRK-1001', 'contact' => '0100123400'.$i])->assertNotFound();
        }
        $this->travel(61)->seconds();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson($this->api($this->alpha, 'orders/track'), ['order_number' => 'TRK-1001', 'contact' => 'guess'.$i.'@example.test'])->assertNotFound();
        }
        $this->postJson($this->api($this->alpha, 'orders/track'), ['order_number' => 'TRK-1001', 'contact' => '01001234567'])->assertNotFound();
        $this->travel(61)->minutes();
        $this->postJson($this->api($this->alpha, 'orders/track'), ['order_number' => 'TRK-1001', 'contact' => '01001234567'])->assertOk();
    }

    public function test_account_order_detail_includes_the_status_timeline_and_shipments(): void
    {
        $order = $this->order();
        $auth = $this->postJson($this->api($this->alpha, 'auth/register'), ['name' => 'Buyer', 'email' => 'buyer@example.test', 'password' => 'password123'])->assertCreated()->json('token');
        $order->update(['store_customer_id' => StoreCustomer::withoutGlobalScopes()->where('email', 'buyer@example.test')->value('id')]);
        $this->getJson($this->api($this->alpha, 'orders/TRK-1001'), ['Authorization' => "Bearer {$auth}"])->assertOk()
            ->assertJsonPath('data.timeline.1.status', 'shipped')->assertJsonCount(2, 'data.timeline')
            ->assertJsonPath('data.fulfillments.0.tracking_url', 'https://www.aramex.com/track/results?ShipmentNumber=AR123');
    }
}
