<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\MerchantSubscriptions;
use App\Services\PlatformWallet;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private MerchantSubscriptions $billing;

    private PlatformWallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'slug' => 'subscription-fixture', 'name' => 'Local fixture', 'status' => 'active']);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
        $this->billing = app(MerchantSubscriptions::class);
        $this->wallet = app(PlatformWallet::class);
    }

    private function ready(): void
    {
        config(['platform_wallet.subscriptions.enabled' => true, 'platform_wallet.fees_enabled' => true, 'platform_wallet.fawaterak.enabled' => true, 'platform_wallet.fawaterak.test_mode' => false, 'platform_wallet.fawaterak.api_key' => 'local-fixture-only', 'platform_wallet.fawaterak.vendor_key' => 'local-fixture-only']);
        $this->wallet->activateOrderFees();
    }

    private function body(string $cycle = 'monthly', bool $renew = false): array
    {
        return ['cycle' => $cycle, 'quote' => $this->billing->quote($cycle), 'request_key' => (string) Str::uuid(), 'auto_renew' => $renew];
    }

    private function order(): StoreOrder
    {
        return StoreOrder::create(['store_id' => $this->store->id, 'order_number' => 'SUB-'.Str::uuid(), 'status' => 'pending', 'currency' => 'EGP', 'customer_name' => 'Local subscription buyer', 'customer_phone' => '01001234567', 'shipping_address' => ['line1' => 'Local address'], 'grand_total' => 100, 'subtotal' => 100, 'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0, 'payment_method' => 'cod', 'payment_status' => 'pending', 'placed_at' => now()]);
    }

    public function test_read_is_side_effect_free_and_default_disabled_billing_cannot_charge(): void
    {
        $this->getJson('/api/v1/me/merchant-subscription')->assertOk()->assertJsonPath('available', false)->assertJsonPath('plans.0.price_milli', 100000)->assertJsonPath('plans.1.price_milli', 600000)->assertJsonPath('active', null);
        $this->postJson('/api/v1/me/merchant-subscription', $this->body())->assertStatus(503);
        foreach (['platform_wallet_accounts', 'platform_wallet_entries', 'platform_merchant_subscriptions', 'platform_subscription_preferences', 'platform_order_fee_exemptions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNothingSent();
    }

    public function test_monthly_purchase_is_replay_safe_and_only_future_orders_are_exempt(): void
    {
        $legacy = $this->order();
        $this->ready();
        $this->wallet->funding($this->owner, 100000, 'local-plan-funding');
        $body = $this->body();
        $first = $this->postJson('/api/v1/me/merchant-subscription', $body)->assertCreated();
        $this->postJson('/api/v1/me/merchant-subscription', $body)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson('/api/v1/me/merchant-subscription', array_replace($body, ['auto_renew' => true]))->assertConflict();
        $this->postJson('/api/v1/me/merchant-subscription', $this->body('yearly'))->assertConflict();
        $new = $this->order();
        $this->wallet->orderFee($new);
        $this->wallet->orderFee($legacy);
        $this->assertDatabaseCount('platform_order_fee_exemptions', 1);
        $this->assertDatabaseCount('platform_merchant_subscriptions', 1);
        $this->assertSame(1, DB::table('platform_wallet_entries')->where('type', 'subscription')->count());
        $this->assertSame(0, DB::table('platform_wallet_entries')->where('type', 'order_fee')->count());
        $this->assertSame(0, (int) DB::table('platform_wallet_accounts')->value('balance_milli'));
        $this->travel(32)->days();
        $this->wallet->orderFee($new);
        $this->wallet->orderFee($this->order());
        $this->assertSame(1, DB::table('platform_wallet_entries')->where('type', 'order_fee')->count());
        $this->assertSame(-40, (int) DB::table('platform_wallet_accounts')->value('balance_milli'));
        $this->assertNull($this->billing->active($this->owner));
    }

    public function test_low_balance_stale_quote_and_invalid_cycle_are_atomic(): void
    {
        $this->ready();
        $this->postJson('/api/v1/me/merchant-subscription', $this->body())->assertStatus(402);
        $this->assertDatabaseCount('platform_wallet_accounts', 0);
        $this->assertDatabaseCount('platform_wallet_entries', 0);
        $this->wallet->funding($this->owner, 200000, 'local-plan-funding');
        $body = $this->body();
        config(['platform_wallet.subscriptions.monthly_milli' => 120000]);
        $this->postJson('/api/v1/me/merchant-subscription', $body)->assertConflict();
        $this->postJson('/api/v1/me/merchant-subscription', array_replace($body, ['cycle' => 'lifetime']))->assertUnprocessable();
        $this->assertDatabaseCount('platform_merchant_subscriptions', 0);
        $this->assertDatabaseCount('platform_wallet_entries', 1);
    }

    public function test_auto_renew_records_one_due_charge_and_lazy_renew_exempts_the_current_order(): void
    {
        $this->ready();
        $this->wallet->funding($this->owner, 100000, 'local-plan-funding');
        $this->postJson('/api/v1/me/merchant-subscription', $this->body('monthly', true))->assertCreated();
        $this->travel(32)->days();
        $current = $this->order();
        $this->wallet->orderFee($current);
        $this->billing->renew($this->owner);
        $this->artisan('merchant-subscriptions:renew')->assertSuccessful();
        $this->assertDatabaseCount('platform_merchant_subscriptions', 2);
        $this->assertSame(2, DB::table('platform_wallet_entries')->where('type', 'subscription')->count());
        $this->assertSame(-100000, (int) DB::table('platform_wallet_accounts')->value('balance_milli'));
        $this->assertDatabaseHas('platform_order_fee_exemptions', ['store_order_id' => $current->id]);
        $this->assertFalse($this->wallet->ordersLocked($this->owner));
        $this->putJson('/api/v1/me/merchant-subscription/preferences', ['auto_renew' => false, 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->assertNotNull($this->billing->active($this->owner));
        $this->putJson('/api/v1/me/merchant-subscription/preferences', ['auto_renew' => true, 'version' => 1])->assertConflict();
        $this->travel(32)->days();
        $this->wallet->orderFee($this->order());
        $this->assertDatabaseCount('platform_merchant_subscriptions', 2);
        $this->assertTrue($this->wallet->ordersLocked($this->owner));
    }

    public function test_renewal_keeps_accepted_price_and_never_backcharges_missed_months(): void
    {
        $this->ready();
        $this->wallet->funding($this->owner, 100000, 'local-plan-funding');
        $this->postJson('/api/v1/me/merchant-subscription', $this->body('monthly', true))->assertCreated();
        $this->travel(200)->days();
        config(['platform_wallet.subscriptions.monthly_milli' => 250000]);
        $row = $this->billing->renew($this->owner);
        $this->assertSame(100000, (int) $row->price_milli);
        $this->assertTrue(Carbon::parse($row->starts_at)->isSameDay(now()));
        $this->assertDatabaseCount('platform_merchant_subscriptions', 2);
        $this->assertSame(-100000, (int) DB::table('platform_wallet_accounts')->value('balance_milli'));
        $this->billing->renew($this->owner);
        $this->assertDatabaseCount('platform_merchant_subscriptions', 2);
    }

    public function test_annual_plan_uses_calendar_boundary_without_leap_day_overflow(): void
    {
        $this->travelTo(Carbon::parse('2028-02-29 12:00:00'));
        $this->ready();
        $this->wallet->funding($this->owner, 600000, 'local-annual-funding');
        $this->postJson('/api/v1/me/merchant-subscription', $this->body('yearly'))->assertCreated()->assertJsonPath('data.ends_at', '2029-02-28T12:00:00+00:00');
    }

    public function test_renewal_rechecks_account_status_instead_of_using_a_stale_owner(): void
    {
        $this->ready();
        $this->wallet->funding($this->owner, 100000, 'local-plan-funding');
        $this->postJson('/api/v1/me/merchant-subscription', $this->body('monthly', true))->assertCreated();
        $this->travel(32)->days();
        $stale = User::findOrFail($this->owner->id);
        $stale->load('roles');
        DB::table('users')->where('id', $stale->id)->update(['is_active' => false]);
        $this->assertNull($this->billing->renew($stale));
        $this->assertDatabaseCount('platform_merchant_subscriptions', 1);
        $this->assertSame(0, (int) DB::table('platform_wallet_accounts')->value('balance_milli'));
    }

    public function test_private_history_and_billing_are_owner_only_and_never_charge_another_user(): void
    {
        $this->ready();
        $this->wallet->funding($this->owner, 100000, 'local-plan-funding');
        $this->postJson('/api/v1/me/merchant-subscription', $this->body())->assertCreated();
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($other));
        $this->getJson('/api/v1/me/merchant-subscription')->assertOk()->assertJsonPath('active', null)->assertJsonCount(0, 'history');
        $this->putJson('/api/v1/me/merchant-subscription/preferences', ['auto_renew' => false, 'version' => 1])->assertNotFound();
        $employee = User::factory()->create(['parent_user_id' => $this->owner->id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Merchant');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($employee));
        $this->getJson('/api/v1/me/merchant-subscription')->assertForbidden();
        $this->postJson('/api/v1/me/merchant-subscription', $this->body())->assertForbidden();
        $this->assertDatabaseCount('platform_merchant_subscriptions', 1);
    }

    public function test_paid_order_exemption_survives_owner_transfer_and_schema_refuses_populated_rollback(): void
    {
        $this->ready();
        $this->wallet->funding($this->owner, 100000, 'local-plan-funding');
        $this->postJson('/api/v1/me/merchant-subscription', $this->body())->assertCreated();
        $order = $this->order();
        $this->wallet->orderFee($order);
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        $this->store->update(['owner_user_id' => $other->id]);
        $this->wallet->orderFee($order->fresh());
        $this->wallet->orderFee($this->order());
        $this->assertSame(1, DB::table('platform_wallet_entries')->where('type', 'order_fee')->count());
        $migration = require database_path('migrations/2026_10_13_080000_create_merchant_subscription_billing.php');
        try {
            $migration->down();
            $this->fail('Populated subscription data was dropped.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Refusing', $error->getMessage());
        }
        $this->assertDatabaseCount('platform_order_fee_exemptions', 1);
    }
}
