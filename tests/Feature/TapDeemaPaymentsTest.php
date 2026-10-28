<?php

namespace Tests\Feature;

use App\Models\PaymentWebhookEvent;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use App\Models\User;
use App\Services\Commerce\CheckoutFields;
use App\Services\Commerce\PaymentGatewayCatalog;
use App\Services\Commerce\StorePaymentService;
use App\Services\Commerce\TapPaymentSettings;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\StorePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TapDeemaPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $gateway = 'deema', string $currency = 'KWD', string $amount = '150.25', bool $test = true): array
    {
        Http::preventStrayRequests();
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $store = Store::create(['owner_user_id' => $user->id, 'owner_type' => 'merchant', 'name' => 'Synthetic Tap', 'slug' => 'tap-'.$user->id,
            'currency' => $currency, 'status' => 'active']);
        $tap = StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => 'tap', 'enabled' => true, 'test_mode' => $test,
            'credentials' => ['secret_key' => $test ? 'sk_test_synthetic' : 'sk_live_synthetic']]);
        $setting = $gateway === 'tap' ? $tap : StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => 'deema', 'enabled' => true,
            'test_mode' => ! $test, 'credentials' => []]);
        $order = StoreOrder::withoutGlobalScopes()->create(['store_id' => $store->id, 'order_number' => 'SYNTHETIC-'.$user->id, 'status' => 'pending',
            'currency' => $currency, 'customer_name' => 'Synthetic Buyer', 'customer_email' => 'buyer@example.test', 'customer_phone' => '+96550012345',
            'payment_method' => $gateway, 'payment_status' => 'pending', 'subtotal' => $amount, 'grand_total' => $amount]);

        return [$store, $order, $setting, $tap, $user];
    }

    private function started(string $gateway = 'deema'): array
    {
        [$store, $order, $setting, $tap, $user] = $this->fixture($gateway);
        Http::fake(['api.tap.company/*' => Http::response(['id' => 'chg_synthetic', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://deema.tap.company/payment/synthetic']])]);
        $result = app(StorePaymentService::class)->start($store, $order, $setting);
        $transaction = StorePaymentTransaction::findOrFail($result['transaction_id']);

        return [$store, $order, $setting, $tap, $user, $transaction];
    }

    private function charge(StorePaymentTransaction $transaction, StoreOrder $order): array
    {
        return ['id' => $transaction->provider_reference ?: 'chg_synthetic', 'status' => 'CAPTURED', 'amount' => $transaction->amount,
            'currency' => $transaction->currency, 'live_mode' => false,
            'reference' => ['transaction' => (string) $transaction->id, 'order' => $order->order_number],
            'metadata' => ['transaction_id' => (string) $transaction->id, 'store_id' => (string) $transaction->store_id,
                'store_order_id' => (string) $order->id, 'gateway' => $transaction->gateway, 'tap_contract_version' => '1'],
            'source' => ['id' => $transaction->gateway === 'deema' ? 'src_deema' : 'tok_synthetic', 'payment_method' => $transaction->gateway === 'deema' ? 'DEEMA' : 'VISA']];
    }

    private function fakeCharge(array $event): void
    {
        // Replace the HTTP factory: adding another wildcard fake would leave the earlier create stub first.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.tap.company/*' => Http::response($event)]);
    }

    public function test_deema_uses_only_its_store_tap_secret_environment_and_stable_idempotency(): void
    {
        [$store, $order, $setting, $tap] = $this->fixture();
        $setting->update(['credentials' => ['secret_key' => 'must-never-be-used']]);
        Http::fake(['api.tap.company/*' => Http::response(['id' => 'chg_synthetic', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://deema.tap.company/payment/synthetic']])]);
        $first = app(StorePaymentService::class)->start($store, $order, $setting);
        $this->assertSame($first, app(StorePaymentService::class)->start($store, $order, $setting));
        $transaction = StorePaymentTransaction::findOrFail($first['transaction_id']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk_test_synthetic')
            && $request['source']['id'] === 'src_deema' && $request['currency'] === 'KWD' && $request['amount'] === 150.25
            && $request['reference']['idempotent'] === $transaction->idempotency_key
            && $request['customer']['phone'] === ['country_code' => '965', 'number' => '50012345']
            && $request['metadata']['store_id'] === (string) $store->id && str_ends_with($request['post']['url'], '/'.$store->id.'/deema'));
        Http::assertSentCount(1);
        $this->assertTrue($transaction->metadata['tap']['test_mode']);
        $this->assertSame('src_deema', $transaction->metadata['tap']['source_id']);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_tap_remains_all_sources_and_accepts_real_card_source_on_verified_settlement(): void
    {
        [$store, $order, $setting, , , $transaction] = $this->started('tap');
        Http::assertSent(fn ($request) => $request['source']['id'] === 'src_all');
        $event = $this->charge($transaction, $order);
        $this->fakeCharge($event);
        $this->postJson('/api/v1/payment-webhooks/'.$store->id.'/tap', ['id' => 'chg_synthetic', 'status' => 'FAILED'])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->postJson('/api/v1/payment-webhooks/'.$store->id.'/tap', ['id' => 'chg_synthetic'])->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('payment_webhook_events', 1);
    }

    public function test_only_the_server_retrieved_status_can_settle_and_terminal_capture_is_idempotent(): void
    {
        [$store, $order, , , , $transaction] = $this->started();
        $event = $this->charge($transaction, $order);
        $event['status'] = 'INITIATED';
        $this->fakeCharge($event);
        $this->postJson('/api/v1/payment-webhooks/'.$store->id.'/deema', ['id' => 'chg_synthetic', 'status' => 'CAPTURED', 'amount' => '150.25'])->assertOk();
        $this->assertSame('pending', $order->fresh()->payment_status);
        $event['status'] = 'CAPTURED';
        $this->fakeCharge($event);
        $this->postJson('/api/v1/payment-webhooks/'.$store->id.'/deema', ['id' => 'chg_synthetic'])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->postJson('/api/v1/payment-webhooks/'.$store->id.'/deema', ['id' => 'chg_synthetic'])->assertOk()->assertJsonPath('duplicate', true);
    }

    public static function invalidCharges(): array
    {
        return [
            'amount' => ['amount', '150.26'], 'fraction' => ['amount', '150.2501'], 'currency' => ['currency', 'USD'],
            'transaction reference' => ['reference.transaction', '999'], 'order reference' => ['reference.order', 'WRONG'],
            'tenant metadata' => ['metadata.store_id', '999'], 'order metadata' => ['metadata.store_order_id', '999'],
            'gateway metadata' => ['metadata.gateway', 'tap'], 'environment' => ['live_mode', true],
            'source' => ['source.id', 'src_card'], 'source method' => ['source.payment_method', 'VISA'],
        ];
    }

    #[DataProvider('invalidCharges')]
    public function test_mismatched_verified_charges_never_settle_or_poison_event_receipts(string $field, mixed $value): void
    {
        [$store, $order, , , , $transaction] = $this->started();
        $event = $this->charge($transaction, $order);
        data_set($event, $field, $value);
        $this->fakeCharge($event);
        $this->postJson('/api/v1/payment-webhooks/'.$store->id.'/deema', ['id' => 'chg_synthetic'])->assertStatus(422);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('redirect_pending', $transaction->fresh()->status);
        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    public function test_a_wrong_store_callback_does_not_block_the_correct_store_even_with_the_same_tap_account(): void
    {
        [$store, $order, , , , $transaction] = $this->started();
        [$other] = $this->fixture();
        $event = $this->charge($transaction, $order);
        $this->fakeCharge($event);
        $this->postJson('/api/v1/payment-webhooks/'.$other->id.'/deema', ['id' => 'chg_synthetic'])->assertStatus(422);
        $this->assertDatabaseCount('payment_webhook_events', 0);
        $this->postJson('/api/v1/payment-webhooks/'.$store->id.'/deema', ['id' => 'chg_synthetic'])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame($store->id, PaymentWebhookEvent::firstOrFail()->store_id);
    }

    public function test_verified_callback_can_win_the_initiation_race_without_losing_the_paid_state(): void
    {
        [$store, $order, $setting] = $this->fixture();
        Http::fake(['api.tap.company/*' => function ($request) use ($store, $order) {
            $transaction = StorePaymentTransaction::firstOrFail();
            $event = $this->charge($transaction, $order);
            if ($request->method() === 'GET') {
                return Http::response($event);
            }
            $this->postJson('/api/v1/payment-webhooks/'.$store->id.'/deema', ['id' => 'chg_synthetic'])->assertOk();

            return Http::response(['id' => 'chg_synthetic', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://deema.tap.company/payment/synthetic']]);
        }]);
        $result = app(StorePaymentService::class)->start($store, $order, $setting);
        $this->assertSame('paid', $result['status']);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('chg_synthetic', StorePaymentTransaction::firstOrFail()->provider_reference);
        $this->assertDatabaseCount('store_payment_transactions', 1);
    }

    public static function failedCreates(): array
    {
        return ['Tap server error' => ['tap', false], 'Tap timeout' => ['tap', true],
            'Deema server error' => ['deema', false], 'Deema timeout' => ['deema', true]];
    }

    #[DataProvider('failedCreates')]
    public function test_delayed_verified_capture_settles_a_failed_unbound_attempt_once_without_retry(string $gateway, bool $timeout): void
    {
        [$store, $order, $transaction] = $this->failedStart($gateway, $timeout);
        $this->fakeCharge($this->charge($transaction, $order));
        $url = '/api/v1/payment-webhooks/'.$store->id.'/'.$gateway;
        $this->postJson($url, ['id' => 'chg_synthetic'])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('paid', $transaction->fresh()->status);
        $this->assertSame('chg_synthetic', $transaction->fresh()->provider_reference);
        $this->postJson($url, ['id' => 'chg_synthetic'])->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('store_payment_transactions', 1);
        $this->assertDatabaseCount('payment_webhook_events', 1);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    #[DataProvider('invalidCharges')]
    public function test_failed_unbound_attempt_rejects_a_wrong_contract_without_blocking_the_valid_capture(string $field, mixed $value): void
    {
        [$store, $order, $transaction] = $this->failedStart('deema');
        $valid = $this->charge($transaction, $order);
        $invalid = $valid;
        data_set($invalid, $field, $value);
        $this->fakeCharge($invalid);
        $url = '/api/v1/payment-webhooks/'.$store->id.'/deema';
        $this->postJson($url, ['id' => 'chg_synthetic'])->assertStatus(422);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('failed', $transaction->fresh()->status);
        $this->assertNull($transaction->fresh()->provider_reference);
        $this->assertDatabaseCount('payment_webhook_events', 0);
        $this->fakeCharge($valid);
        $this->postJson($url, ['id' => 'chg_synthetic'])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_a_legacy_failed_unbound_attempt_cannot_bind_a_delayed_charge(): void
    {
        [$store, $order, $transaction] = $this->failedStart('tap');
        $transaction->update(['metadata' => []]);
        $this->fakeCharge($this->charge($transaction, $order));
        $this->postJson('/api/v1/payment-webhooks/'.$store->id.'/tap', ['id' => 'chg_synthetic'])->assertStatus(422);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($transaction->fresh()->provider_reference);
        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    private function failedStart(string $gateway, bool $timeout = false): array
    {
        [$store, $order, $setting] = $this->fixture($gateway);
        $calls = 0;
        Http::fake(['api.tap.company/*' => function () use ($timeout, &$calls) {
            $calls++;
            if ($timeout) {
                throw new ConnectionException('Synthetic create timeout.');
            }

            return Http::response([], 500);
        }]);
        try {
            app(StorePaymentService::class)->start($store, $order, $setting);
            $this->fail('A failed create response was accepted.');
        } catch (ValidationException) {
            $transaction = StorePaymentTransaction::firstOrFail();
            $this->assertSame('failed', $transaction->status);
            $this->assertNull($transaction->provider_reference);
        }
        $this->assertSame(1, $calls);

        return [$store, $order, $transaction];
    }

    public function test_legacy_pending_tap_charge_keeps_its_unversioned_contract_but_money_is_checked(): void
    {
        [$store, $order, $setting] = $this->fixture('tap');
        $transaction = StorePaymentTransaction::create(['store_id' => $store->id, 'store_order_id' => $order->id, 'gateway' => 'tap', 'idempotency_key' => 'legacy-synthetic',
            'provider_reference' => 'chg_legacy', 'status' => 'redirect_pending', 'amount' => $order->amountDue(), 'currency' => 'KWD', 'metadata' => []]);
        $event = $this->charge($transaction, $order);
        $event['metadata'] = ['transaction_id' => (string) $transaction->id];
        unset($event['live_mode']);
        $event['amount'] = '1';
        $this->fakeCharge($event);
        $this->postJson('/api/v1/payment-webhooks/'.$store->id.'/tap', ['id' => 'chg_legacy'])->assertStatus(422);
        $event['amount'] = $order->amountDue();
        $this->fakeCharge($event);
        $this->postJson('/api/v1/payment-webhooks/'.$store->id.'/tap', ['id' => 'chg_legacy'])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public static function invalidStarts(): array
    {
        return ['wrong currency' => ['USD', '150', true, '+96550012345'], 'sandbox below' => ['KWD', '99.99', true, '+96550012345'],
            'sandbox above' => ['KWD', '200.01', true, '+96550012345'], 'live below' => ['KWD', '9.99', false, '+96550012345'],
            'missing country code' => ['KWD', '150', true, '50012345'], 'invalid phone' => ['KWD', '150', true, 'not-a-number']];
    }

    #[DataProvider('invalidStarts')]
    public function test_ineligible_deema_starts_never_make_a_provider_request(string $currency, string $amount, bool $test, string $phone): void
    {
        [$store, $order, $setting] = $this->fixture('deema', $currency, $amount, $test);
        $order->update(['customer_phone' => $phone]);
        try {
            app(StorePaymentService::class)->start($store, $order, $setting);
            $this->fail('Ineligible Deema payment started.');
        } catch (ValidationException) {
            $this->assertSame('pending', $order->fresh()->payment_status);
        }
        Http::assertNothingSent();
    }

    public static function boundaries(): array
    {
        return ['sandbox minimum' => ['100', true], 'sandbox maximum' => ['200', true], 'live minimum' => ['10', false]];
    }

    #[DataProvider('boundaries')]
    public function test_documented_amount_boundaries_start_using_the_inherited_environment(string $amount, bool $test): void
    {
        [$store, $order, $setting] = $this->fixture('deema', 'KWD', $amount, $test);
        Http::fake(['api.tap.company/*' => Http::response(['id' => 'chg_boundary', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://deema.tap.company/payment/boundary']])]);
        $this->assertSame('redirect_pending', app(StorePaymentService::class)->start($store, $order, $setting)['status']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.($test ? 'sk_test_synthetic' : 'sk_live_synthetic')) && $request['amount'] === (float) $amount);
    }

    public function test_tap_key_environment_mismatch_and_invalid_charge_paths_make_no_http_requests(): void
    {
        [$store, $order, $setting, $tap] = $this->fixture();
        $tap->update(['test_mode' => false]);
        try {
            app(StorePaymentService::class)->start($store, $order, $setting);
            $this->fail('A test key was used in live mode.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('store_payment_transactions', 0);
        }
        $this->postJson('/api/v1/payment-webhooks/'.$store->id.'/deema', ['id' => '../not-a-charge'])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_public_deema_option_never_converts_or_appears_for_a_non_kwd_store(): void
    {
        [$store, , $setting, $tap] = $this->fixture();
        StoreDomain::create(['store_id' => $store->id, 'host' => 'tap.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->getJson('http://tap.sellchase.com/api/v1/storefront/payment-methods')->assertOk()->assertJsonFragment(['slug' => 'deema', 'test_mode' => true]);
        $store->update(['currency' => 'USD']);
        $this->assertFalse(PaymentGatewayCatalog::offered($setting, 'USD'));
        $this->getJson('http://tap.sellchase.com/api/v1/storefront/payment-methods')->assertOk()->assertJsonMissing(['slug' => 'deema']);
        $this->assertSame('USD', $store->fresh()->currency);
        $tap->delete();
        $this->assertFalse(TapPaymentSettings::configured($setting, 'KWD'));
        Http::assertNothingSent();
    }

    public function test_deema_checkout_contact_is_visible_required_and_validated_before_payment_creation(): void
    {
        [$store] = $this->fixture();
        $fields = app(CheckoutFields::class);
        $phone = collect($fields->effective($store, 'deema', false))->firstWhere('key', 'phone');
        $this->assertTrue($phone['enabled']);
        $this->assertTrue($phone['required']);
        $rules = $fields->rules($store, 'deema', false);
        foreach (['', '50012345', 'invalid'] as $invalid) {
            $this->assertTrue(Validator::make(['customer_name' => 'Synthetic Buyer', 'customer_email' => 'buyer@example.test', 'customer_phone' => $invalid], $rules)->fails());
        }
        $this->assertFalse(Validator::make(['customer_name' => 'Synthetic Buyer', 'customer_email' => 'buyer@example.test', 'customer_phone' => '+96550012345'], $rules)->fails());
        Http::assertNothingSent();
    }

    public function test_settings_batch_inherits_tap_without_duplicate_secrets_and_rejects_foreign_credentials(): void
    {
        [$store, , $setting, $tap, $user] = $this->fixture();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class, StorePermissionsSeeder::class]);
        $user->assignRole('Merchant');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($user)];
        $body = ['gateways' => [['slug' => 'deema', 'enabled' => true, 'test_mode' => false], ['slug' => 'tap', 'enabled' => true, 'test_mode' => true,
            'credentials' => ['public_key' => 'pk_test_synthetic', 'secret_key' => 'sk_test_synthetic']]]];
        $response = $this->postJson('/api/v1/my-store/payments', $body, $headers)->assertOk();
        $deema = collect($response->json('data'))->firstWhere('slug', 'deema');
        $this->assertSame([], $deema['credentials']);
        $this->assertSame([], $deema['credential_fields']);
        $this->assertTrue($deema['configured']);
        $this->assertTrue($deema['test_mode']);
        $this->assertSame('tap', $deema['connection_gateway']);
        $this->assertSame([], $setting->fresh()->credentials);
        $this->assertSame('sk_test_synthetic', $tap->fresh()->credentials['secret_key']);
        $this->assertStringNotContainsString('sk_test_synthetic', $response->getContent());
        $this->postJson('/api/v1/my-store/payments', ['gateways' => [['slug' => 'deema', 'enabled' => true, 'credentials' => ['secret_key' => 'separate-is-not-allowed']]]], $headers)->assertUnprocessable();
        $store->update(['currency' => 'USD']);
        $this->postJson('/api/v1/my-store/payments', ['gateways' => [['slug' => 'deema', 'enabled' => true]]], $headers)->assertUnprocessable();
        $this->assertSame('USD', $store->fresh()->currency);
        Http::assertNothingSent();
    }

    public function test_retry_reuses_the_same_tap_idempotency_key_after_a_provider_failure(): void
    {
        [$store, $order, $setting] = $this->fixture();
        $calls = 0;
        Http::fake(['api.tap.company/*' => function () use (&$calls) {
            return ++$calls === 1 ? Http::response([], 500) : Http::response(['id' => 'chg_retry', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://deema.tap.company/payment/retry']]);
        }]);
        try {
            app(StorePaymentService::class)->start($store, $order, $setting);
            $this->fail('Provider failure was accepted.');
        } catch (ValidationException) {
            $this->assertSame('failed', StorePaymentTransaction::firstOrFail()->status);
        }
        $transaction = StorePaymentTransaction::firstOrFail();
        $result = app(StorePaymentService::class)->retry($store, $order, $setting, $transaction);
        $this->assertSame('redirect_pending', $result['status']);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame($transaction->idempotency_key, $request['reference']['idempotent']);
        }
        $this->assertSame(2, $calls);
        $this->assertDatabaseCount('store_payment_transactions', 1);
    }

    public function test_immediate_capture_settles_only_after_a_matching_server_retrieval(): void
    {
        [$store, $order, $setting] = $this->fixture();
        Http::fake(['api.tap.company/*' => function ($request) use ($order) {
            if ($request->method() === 'GET') {
                return Http::response($this->charge(StorePaymentTransaction::firstOrFail(), $order));
            }

            return Http::response(['id' => 'chg_synthetic', 'status' => 'CAPTURED']);
        }]);
        $result = app(StorePaymentService::class)->start($store, $order, $setting);
        $this->assertSame('paid', $result['status']);
        $this->assertNull($result['redirect_url']);
        $this->assertSame('paid', $order->fresh()->payment_status);
        Http::assertSentCount(2);
    }

    public function test_retry_never_moves_a_sandbox_attempt_to_a_live_tap_connection(): void
    {
        [$store, $order, $setting, $tap, , $transaction] = $this->started();
        $transaction->update(['status' => 'failed']);
        $tap->update(['test_mode' => false, 'credentials' => ['secret_key' => 'sk_live_synthetic']]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        try {
            app(StorePaymentService::class)->retry($store, $order, $setting, $transaction->fresh());
            $this->fail('A sandbox attempt crossed into the live environment.');
        } catch (ValidationException) {
            $this->assertSame('failed', $transaction->fresh()->status);
            $this->assertSame('pending', $order->fresh()->payment_status);
        }
        Http::assertNothingSent();
    }
}
