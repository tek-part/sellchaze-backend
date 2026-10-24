<?php

namespace Tests\Feature;

use App\Models\PlatformWalletPayment;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\PlatformWallet;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformWalletTest extends TestCase
{
    use RefreshDatabase;

    private PlatformWallet $wallet;

    private User $referrer;

    private User $buyer;

    private User $admin;

    private function configureFunding(bool $test = false): void
    {
        config(['sellchase.frontend_url' => 'https://sellchaze.test', 'platform_wallet.fawaterak.enabled' => true, 'platform_wallet.fawaterak.test_mode' => $test,
            'platform_wallet.fawaterak.api_key' => 'local-fake-api-key', 'platform_wallet.fawaterak.vendor_key' => 'local-fake-vendor-key', 'platform_wallet.fawaterak.webhook_base_url' => 'https://api.sellchaze.test']);
    }

    private function invoiceData(PlatformWalletPayment $payment, array $change = []): array
    {
        return ['status' => 'success', 'data' => $change + ['invoice_id' => 5001, 'invoice_key' => 'local_invoice_key', 'currency' => 'USD', 'total' => '5.00', 'paid' => 1, 'pay_load' => ['wallet_payment_id' => $payment->id]]];
    }

    private function paidEvent(PlatformWalletPayment $payment): array
    {
        return ['invoice_id' => 5001, 'invoice_key' => 'local_invoice_key', 'payment_method' => 'Card', 'invoice_status' => 'paid', 'pay_load' => ['wallet_payment_id' => $payment->id],
            'hashKey' => hash_hmac('sha256', 'InvoiceId=5001&InvoiceKey=local_invoice_key&PaymentMethod=Card', 'local-fake-vendor-key')];
    }

    public function test_fawaterak_paid_api_verification_creates_credit_and_cash_once_and_hides_credentials(): void
    {
        $this->configureFunding();
        Http::fake(['*/createInvoiceLink' => Http::response(['status' => 'success', 'data' => ['invoiceId' => 5001, 'invoiceKey' => 'local_invoice_key', 'url' => 'https://app.fawaterk.com/link/local-review']])]);
        $body = ['amount_milli' => 5000, 'request_key' => (string) Str::uuid()];
        $first = $this->postJson('/api/v1/me/platform-wallet/fund', $body, $this->auth($this->buyer))->assertOk()->assertJsonPath('status', 'pending');
        $this->postJson('/api/v1/me/platform-wallet/fund', $body, $this->auth($this->buyer))->assertOk()->assertJsonPath('id', $first->json('id'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['currency'] === 'USD' && $request['cartTotal'] === '5.00' && $request['sendEmail'] === false && $request['sendSMS'] === false);
        $payment = PlatformWalletPayment::findOrFail($first->json('id'));
        $raw = DB::table('platform_wallet_payments')->where('id', $payment->id)->value('credentials');
        $this->assertStringNotContainsString('local-fake-api-key', $raw);
        $this->assertDatabaseCount('platform_wallet_entries', 0);
        Http::fake(['*/getInvoiceData/5001' => Http::response($this->invoiceData($payment))]);
        $event = $this->paidEvent($payment);
        $this->postJson('/api/v1/platform-wallet/fawaterak_json', $event + ['amount' => 600000])->assertOk();
        $this->postJson('/api/v1/platform-wallet/fawaterak_json', $event)->assertOk();
        $this->postJson('/api/v1/me/platform-wallet/payments/'.$payment->id.'/refresh', [], $this->auth($this->buyer))->assertOk()->assertJsonPath('status', 'paid')->assertJsonMissing(['credentials' => 'local-fake-api-key']);
        $this->assertDatabaseCount('platform_wallet_entries', 1);
        $this->assertDatabaseHas('platform_wallet_accounts', ['user_id' => $this->buyer->id, 'balance_milli' => 5000]);
        $this->assertDatabaseHas('platform_commission_entries', ['amount_milli' => 1000]);
        $this->postJson('/api/v1/me/platform-wallet/payments/'.$payment->id.'/refresh', [], $this->auth($this->referrer))->assertNotFound();
        $this->postJson('/api/v1/me/platform-wallet/fund', array_replace($body, ['amount_milli' => 10000]), $this->auth($this->buyer))->assertConflict();
    }

    public function test_fawaterak_forged_pending_or_mismatched_invoices_cannot_credit_a_wallet(): void
    {
        $this->configureFunding();
        Http::fake(['*/createInvoiceLink' => Http::response(['status' => 'success', 'data' => ['invoiceId' => 5001, 'invoiceKey' => 'local_invoice_key', 'url' => 'https://app.fawaterk.com/link/local-review']])]);
        $first = $this->postJson('/api/v1/me/platform-wallet/fund', ['amount_milli' => 5000, 'request_key' => (string) Str::uuid()], $this->auth($this->buyer))->assertOk();
        $payment = PlatformWalletPayment::findOrFail($first->json('id'));
        $event = $this->paidEvent($payment);
        $this->postJson('/api/v1/platform-wallet/fawaterak_json', array_replace($event, ['hashKey' => str_repeat('0', 64)]))->assertUnauthorized();
        $changes = [['currency' => 'EGP'], ['total' => '4.99'], ['invoice_key' => 'different'], ['pay_load' => ['wallet_payment_id' => (string) Str::uuid()]]];
        $responses = Http::sequence();
        foreach ($changes as $change) {
            $responses->push($this->invoiceData($payment, $change));
        }
        $responses->push($this->invoiceData($payment, ['paid' => 0]));
        Http::fake(['*/getInvoiceData/5001' => $responses]);
        foreach ($changes as $change) {
            $this->postJson('/api/v1/platform-wallet/fawaterak_json', $event)->assertConflict();
        }
        $this->postJson('/api/v1/platform-wallet/fawaterak_json', $event)->assertOk();
        $this->assertDatabaseHas('platform_wallet_payments', ['id' => $payment->id, 'status' => 'pending']);
        $this->assertDatabaseCount('platform_wallet_entries', 0);
        $this->assertDatabaseCount('platform_commission_entries', 0);
    }

    public function test_uncertain_invoice_creation_is_retained_without_resubmit_and_signed_callback_recovers_it(): void
    {
        $this->configureFunding();
        Http::fake(['*/createInvoiceLink' => Http::response([], 500)]);
        $body = ['amount_milli' => 5000, 'request_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/me/platform-wallet/fund', $body, $this->auth($this->buyer))->assertStatus(502);
        $this->postJson('/api/v1/me/platform-wallet/fund', $body, $this->auth($this->buyer))->assertOk()->assertJsonPath('status', 'uncertain');
        Http::assertSentCount(1);
        $payment = PlatformWalletPayment::firstOrFail();
        Http::fake(['*/getInvoiceData/5001' => Http::response($this->invoiceData($payment))]);
        $this->postJson('/api/v1/platform-wallet/fawaterak_json', $this->paidEvent($payment))->assertOk();
        $this->assertDatabaseHas('platform_wallet_payments', ['id' => $payment->id, 'status' => 'paid', 'invoice_id' => 5001]);
        $this->assertDatabaseCount('platform_wallet_entries', 1);
    }

    public function test_sandbox_invoices_do_not_credit_real_wallets_and_unconfigured_gateway_is_closed(): void
    {
        $body = ['amount_milli' => 5000, 'request_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/me/platform-wallet/fund', $body, $this->auth($this->buyer))->assertStatus(503);
        $this->assertDatabaseCount('platform_wallet_payments', 0);
        $this->configureFunding(true);
        Http::fake(['*/createInvoiceLink' => Http::response(['status' => 'success', 'data' => ['invoiceId' => 5001, 'invoiceKey' => 'local_invoice_key', 'url' => 'https://staging.fawaterk.com/link/local-review']])]);
        $first = $this->postJson('/api/v1/me/platform-wallet/fund', $body, $this->auth($this->buyer))->assertOk();
        $payment = PlatformWalletPayment::findOrFail($first->json('id'));
        Http::fake(['*/getInvoiceData/5001' => Http::response($this->invoiceData($payment))]);
        $this->postJson('/api/v1/platform-wallet/fawaterak_json', $this->paidEvent($payment))->assertOk();
        $this->assertDatabaseHas('platform_wallet_payments', ['id' => $payment->id, 'status' => 'paid', 'wallet_entry_id' => null]);
        $this->assertDatabaseCount('platform_wallet_entries', 0);
        $this->assertDatabaseCount('platform_commission_entries', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Notification::fake();
        config(['sellchase.frontend_url' => 'https://sellchaze.test', 'platform_wallet.commissions_enabled' => true, 'platform_wallet.fees_enabled' => false]);
        $this->wallet = app(PlatformWallet::class);
        $this->referrer = $this->owner();
        $this->buyer = $this->owner();
        $this->admin = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->admin->assignRole('Admin');
        $this->wallet->attachSignup($this->buyer, $this->wallet->profile($this->referrer)->code);
    }

    private function owner(): User
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        Store::create(['owner_user_id' => $user->id, 'owner_type' => 'merchant', 'name' => 'Wallet fixture', 'slug' => 'wallet-'.$user->id, 'status' => 'draft', 'currency' => 'EGP']);

        return $user;
    }

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($user)];
    }

    private function command(array $change = []): array
    {
        return $change + ['currency' => 'USD', 'user_id' => $this->buyer->id, 'operation' => 'funding', 'amount_milli' => 5000, 'reference' => 'confirmed-local-bank-1', 'confirmed' => true];
    }

    public function test_owner_reads_actual_scoped_ledger_and_cannot_credit_or_access_another_account(): void
    {
        $this->getJson('/api/v1/me/platform-wallet', $this->auth($this->buyer))->assertOk()->assertJsonPath('balance_milli', 0);
        $this->assertDatabaseCount('platform_wallet_accounts', 0);
        $this->postJson('/api/v1/admin/platform-wallet/reconcile', $this->command(), $this->auth($this->buyer))->assertForbidden();
        $this->postJson('/api/v1/admin/platform-wallet/reconcile', $this->command(), $this->auth($this->admin))->assertOk();
        $this->getJson('/api/v1/me/platform-wallet?user_id='.$this->buyer->id, $this->auth($this->referrer))->assertOk()->assertJsonPath('balance_milli', 0);
        $this->getJson('/api/v1/me/platform-wallet/entries', $this->auth($this->buyer))->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.entries_total', 1)->assertJsonPath('data.1.row_kind', 'balance')->assertJsonMissing(['event_key' => 'topup:confirmed-local-bank-1']);
        $this->getJson('/api/v1/me/platform-referral', $this->auth($this->referrer))->assertOk()->assertJsonPath('commission_milli', 1000)->assertJsonPath('referred_stores', 1);
        $this->assertDatabaseHas('platform_wallet_entries', ['recorded_by_user_id' => $this->admin->id, 'amount_milli' => 5000]);
    }

    public function test_funding_replay_is_exact_and_cannot_create_double_or_retroactive_commissions(): void
    {
        config(['platform_wallet.commissions_enabled' => false]);
        $entry = $this->wallet->funding($this->buyer, 5000, 'settlement-1', $this->admin);
        config(['platform_wallet.commissions_enabled' => true]);
        $replay = $this->wallet->funding($this->buyer, 5000, 'settlement-1', $this->admin);
        $this->assertSame($entry->id, $replay->id);
        $this->assertDatabaseCount('platform_wallet_entries', 1);
        $this->assertDatabaseCount('platform_commission_entries', 0);
        $this->postJson('/api/v1/admin/platform-wallet/reconcile', $this->command(['amount_milli' => 6000, 'reference' => 'settlement-1']), $this->auth($this->admin))->assertConflict();
        $this->postJson('/api/v1/admin/platform-wallet/reconcile', $this->command(['user_id' => $this->referrer->id, 'reference' => 'settlement-1']), $this->auth($this->admin))->assertConflict();
        $this->assertDatabaseHas('platform_wallet_accounts', ['user_id' => $this->buyer->id, 'balance_milli' => 5000]);
        $this->assertDatabaseCount('platform_wallet_accounts', 1);
    }

    public function test_partial_refunds_reverse_cash_exactly_with_cumulative_rounding_and_replay(): void
    {
        $entry = $this->wallet->funding($this->buyer, 5001, 'rounding-credit');
        $a = $this->wallet->refund($this->buyer, $entry->id, 1, 'rounding-refund-1');
        $this->assertSame($a->id, $this->wallet->refund($this->buyer, $entry->id, 1, 'rounding-refund-1')->id);
        $this->wallet->refund($this->buyer, $entry->id, 5000, 'rounding-refund-2');
        $this->assertSame(0, (int) DB::table('platform_commission_entries')->sum('amount_milli'));
        $this->assertSame(0, (int) DB::table('platform_wallet_accounts')->where('user_id', $this->buyer->id)->value('balance_milli'));
        $this->postJson('/api/v1/admin/platform-wallet/reconcile', $this->command(['operation' => 'refund', 'funding_id' => $entry->id, 'amount_milli' => 1, 'reference' => 'over-refund']), $this->auth($this->admin))->assertUnprocessable();
        $this->assertDatabaseCount('platform_wallet_entries', 3);
    }

    public function test_cash_payout_requires_ten_distinct_referred_store_owners_and_does_not_fund_wallet(): void
    {
        $this->wallet->funding($this->buyer, 5000, 'payout-seed');
        $this->postJson('/api/v1/admin/platform-wallet/reconcile', $this->command(['operation' => 'payout', 'user_id' => $this->referrer->id, 'amount_milli' => 1000, 'reference' => 'cash-payout']), $this->auth($this->admin))->assertUnprocessable();
        $profile = $this->wallet->profile($this->referrer);
        for ($i = 0; $i < 9; $i++) {
            $invitee = $this->owner();
            $this->wallet->attachSignup($invitee, $profile->code);
        }
        $payload = $this->command(['operation' => 'payout', 'user_id' => $this->referrer->id, 'amount_milli' => 1000, 'reference' => 'cash-payout']);
        $this->postJson('/api/v1/admin/platform-wallet/reconcile', $payload, $this->auth($this->admin))->assertOk();
        $this->postJson('/api/v1/admin/platform-wallet/reconcile', $payload, $this->auth($this->admin))->assertOk();
        $this->assertSame(0, (int) DB::table('platform_commission_entries')->sum('amount_milli'));
        $this->assertDatabaseMissing('platform_wallet_accounts', ['user_id' => $this->referrer->id]);
        $this->assertDatabaseCount('platform_commission_entries', 2);
    }

    public function test_fee_accounting_is_off_by_default_and_idempotent_when_explicitly_enabled(): void
    {
        $store = $this->buyer->store;
        $order = StoreOrder::create(['store_id' => $store->id, 'order_number' => 'PLATFORM-FEE-TEST', 'customer_name' => 'Named local fee buyer', 'customer_phone' => '+201001234567', 'status' => 'pending', 'currency' => 'EGP', 'subtotal' => 100, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0, 'grand_total' => 100]);
        $this->wallet->orderFee($order);
        $this->assertDatabaseCount('platform_wallet_entries', 0);
        config(['platform_wallet.fees_enabled' => true]);
        $this->wallet->activateOrderFees();
        $this->wallet->orderFee($order);
        $this->assertDatabaseCount('platform_wallet_entries', 0);
        $order = StoreOrder::create(array_merge($order->getAttributes(), ['id' => null, 'order_number' => 'PLATFORM-FEE-NEW']));
        $this->wallet->orderFee($order);
        $this->wallet->orderFee($order);
        $this->getJson('/api/v1/me/platform-wallet', $this->auth($this->buyer))->assertOk()->assertJsonPath('balance_milli', 0)->assertJsonPath('amount_due_milli', 40);
        $this->assertDatabaseCount('platform_wallet_entries', 1);
        $this->assertSame('EGP', $order->fresh()->currency);
        $this->assertSame('100.00', $order->fresh()->grand_total);
    }

    public function test_debt_locks_only_charged_new_orders_and_funding_restores_private_access(): void
    {
        $store = $this->buyer->store;
        $create = fn ($number) => StoreOrder::create(['store_id' => $store->id, 'order_number' => $number, 'customer_name' => 'Private buyer', 'customer_phone' => '+201001234567', 'status' => 'pending', 'currency' => 'EGP', 'subtotal' => 100, 'grand_total' => 100]);
        $old = $create('LEGACY-WALLET-ORDER');
        $old->update(['customer_name' => 'Legacy buyer']);
        config(['platform_wallet.fees_enabled' => true]);
        $boundary = $this->wallet->activateOrderFees();
        $base = '/api/v1/stores/'.$store->id.'/orders';
        $this->wallet->orderFee($old);
        for ($i = 1; $i <= 4; $i++) {
            $order = $create('NEW-WALLET-'.$i);
            $this->wallet->orderFee($order);
        }
        $this->getJson($base.'/'.$order->id, $this->auth($this->buyer))->assertOk();
        $fifth = $create('NEW-WALLET-5');
        $this->wallet->orderFee($fifth);
        $this->wallet->orderFee($fifth);
        $this->assertSame($boundary, $this->wallet->activateOrderFees());
        $this->getJson($base, $this->auth($this->buyer))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.locked_orders', 5)->assertJsonPath('data.0.order_number', $old->order_number)->assertJsonMissing(['name' => 'Private buyer']);
        $this->getJson($base.'/'.$fifth->id, $this->auth($this->buyer))->assertStatus(402)->assertJsonMissing(['phone' => '+201001234567']);
        $this->getJson($base.'/'.$old->id, $this->auth($this->buyer))->assertOk();
        $sixth = $create('NEW-WALLET-6');
        $this->wallet->orderFee($sixth);
        $this->assertDatabaseCount('platform_wallet_entries', 6);
        $this->wallet->funding($this->buyer, 5000, 'debt-settled', $this->admin);
        $this->getJson($base.'/'.$fifth->id, $this->auth($this->buyer))->assertOk();
        $this->getJson($base, $this->auth($this->buyer))->assertOk()->assertJsonPath('meta.total', 7)->assertJsonPath('meta.locked_orders', 0);
        $migration = require database_path('migrations/2026_10_12_100000_create_platform_wallet_billing_boundary.php');
        try {
            $migration->down();
            $this->fail('An activated boundary cannot be deleted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('preserved', $e->getMessage());
        }
    }

    public function test_checkout_continues_at_debt_limit_and_recovery_never_charges_twice(): void
    {
        $store = $this->buyer->store;
        $store->update(['status' => 'active']);
        StoreDomain::create(['store_id' => $store->id, 'host' => 'wallet-checkout.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $product = Product::create(['store_id' => $store->id, 'user_id' => $this->buyer->id, 'name' => 'Local wallet product', 'slug' => 'wallet-product', 'price' => 100, 'is_active' => true]);
        config(['platform_wallet.fees_enabled' => true]);
        $this->wallet->activateOrderFees();
        $base = 'http://wallet-checkout.sellchase.com/api/v1/storefront';
        for ($i = 0; $i < 6; $i++) {
            $cart = $this->postJson($base.'/cart/items', ['store_product_id' => $product->id, 'quantity' => 1])->assertCreated()->json('data.token');
            $headers = ['X-Cart-Token' => $cart, 'Idempotency-Key' => (string) Str::uuid()];
            $body = ['customer_name' => 'Local checkout buyer', 'customer_email' => 'checkout@example.test'];
            $first = $this->postJson($base.'/checkout', $body, $headers)->assertCreated()->assertJsonPath('data.grand_total', '100.00');
            $this->postJson($base.'/checkout', $body, $headers)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        }
        $this->assertDatabaseCount('store_orders', 6);
        $this->assertDatabaseCount('platform_wallet_entries', 6);
        $this->getJson('/api/v1/me/platform-wallet', $this->auth($this->buyer))->assertOk()->assertJsonPath('amount_due_milli', 240)->assertJsonPath('orders_locked', true);
    }

    public function test_signup_uses_real_new_account_and_store_and_retains_first_valid_platform_referral(): void
    {
        $profile = $this->wallet->profile($this->referrer);
        $response = $this->postJson('/api/v1/auth/register', ['name' => 'Named wallet signup', 'email' => 'wallet-signup@example.test', 'password' => 'CorrectHorseBattery123!', 'password_confirmation' => 'CorrectHorseBattery123!', 'registration_role' => 'Merchant', 'platform_referral_code' => $profile->code]);
        $response->assertCreated();
        $user = User::query()->where('email', 'wallet-signup@example.test')->firstOrFail();
        $this->assertDatabaseHas('platform_referrals', ['invitee_user_id' => $user->id, 'profile_id' => $profile->id, 'commission_basis_points' => 2000]);
        $this->wallet->attachSignup($user, $this->wallet->profile($this->buyer)->code);
        $this->assertDatabaseHas('platform_referrals', ['invitee_user_id' => $user->id, 'profile_id' => $profile->id]);
        $this->assertSame(2, $this->wallet->referredStores($profile->id));
        $this->assertDatabaseCount('platform_wallet_entries', 0);
        $this->assertDatabaseCount('platform_commission_entries', 0);
    }

    public function test_invalid_and_unconfirmed_money_commands_are_rejected_before_writes(): void
    {
        foreach ([['confirmed' => false], ['currency' => 'EGP'], ['amount_milli' => 0], ['amount_milli' => 600001], ['reference' => "bad\nref"], ['operation' => 'set-balance']] as $change) {
            $this->postJson('/api/v1/admin/platform-wallet/reconcile', $this->command($change), $this->auth($this->admin))->assertUnprocessable();
        }
        $this->getJson('/api/v1/me/platform-wallet')->assertUnauthorized();
        $employee = User::factory()->create();
        $employee->assignRole('Employee');
        $this->getJson('/api/v1/me/platform-referral', $this->auth($employee))->assertForbidden();
        $this->getJson('/api/v1/me/platform-wallet/entries?page=0', $this->auth($this->buyer))->assertUnprocessable();
        $this->assertDatabaseCount('platform_wallet_accounts', 0);
        $this->assertDatabaseCount('platform_commission_entries', 0);
    }

    public function test_new_google_signup_keeps_referral_but_existing_signin_never_rebinds_it(): void
    {
        config(['services.google.client_id' => 'wallet-google-test']);
        Http::fake(['https://oauth2.googleapis.com/tokeninfo*' => Http::response(['aud' => 'wallet-google-test', 'sub' => 'wallet-google-fixture', 'email' => 'wallet-google@example.test', 'email_verified' => 'true', 'name' => 'Wallet Google test'], 200)]);
        $profile = $this->wallet->profile($this->referrer);
        $this->postJson('/api/v1/auth/google', ['id_token' => 'named-local-fixture', 'registration_role' => 'Merchant', 'platform_referral_code' => $profile->code])->assertCreated();
        $user = User::query()->where('email', 'wallet-google@example.test')->firstOrFail();
        $this->assertDatabaseHas('platform_referrals', ['profile_id' => $profile->id, 'invitee_user_id' => $user->id]);
        $this->assertSame(1, $this->wallet->referredStores($profile->id));
        $this->postJson('/api/v1/auth/google', ['id_token' => 'named-local-fixture', 'platform_referral_code' => $this->wallet->profile($this->buyer)->code])->assertOk();
        $this->assertDatabaseHas('platform_referrals', ['profile_id' => $profile->id, 'invitee_user_id' => $user->id]);
        $this->assertDatabaseCount('platform_commission_entries', 0);
    }

    public function test_profile_is_stable_and_populated_migration_refuses_rollback(): void
    {
        $a = $this->postJson('/api/v1/me/platform-referral', [], $this->auth($this->referrer))->assertOk()->json('link');
        $b = $this->postJson('/api/v1/me/platform-referral', [], $this->auth($this->referrer))->assertOk()->json('link');
        $this->assertSame($a, $b);
        // Referral links open sign-up in the dashboard, where ?view= is captured.
        $this->assertStringStartsWith('https://sellchaze.test/register?view=', $a);
        $migration = require database_path('migrations/2026_10_12_090000_create_platform_wallet_and_referrals.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
