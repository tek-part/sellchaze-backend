<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StorePaymentGateway;
use App\Models\User;
use App\Services\Commerce\GiftCards;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeemaCheckoutEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://deema-limits.sellchase.com/api/v1/storefront';

    private Store $store;

    private Product $product;

    private StorePaymentGateway $tap;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Synthetic Deema limits', 'slug' => 'deema-limits', 'status' => 'active', 'currency' => 'KWD', 'shipping_enabled' => false]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'deema-limits.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Synthetic item', 'slug' => 'synthetic', 'price' => 150, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 10]);
        $this->tap = StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'tap', 'enabled' => true, 'test_mode' => true, 'credentials' => ['secret_key' => 'sk_test_synthetic']]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'deema', 'enabled' => true, 'test_mode' => false, 'credentials' => []]);
    }

    private function body(array $extra = []): array
    {
        return $extra + ['cart_mode' => 'direct', 'payment_method' => 'deema', 'customer_name' => 'Synthetic Buyer', 'customer_email' => 'buyer@example.test',
            'customer_phone' => '+96550012345', 'shipping_address' => ['line1' => 'Synthetic street', 'city' => 'Kuwait City', 'country' => 'KW'],
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]]];
    }

    private function assertNoOrderSideEffects(): void
    {
        foreach (['store_orders', 'store_order_items', 'store_payment_transactions', 'store_inventory_movements', 'outbox_messages', 'platform_wallet_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, (int) $this->product->fresh()->reserved_quantity);
        $this->assertSame(10, (int) $this->product->fresh()->stock_quantity);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_public_limits_follow_the_inherited_tap_environment_without_exposing_a_secret(): void
    {
        $response = $this->getJson(self::HOST.'/payment-methods')->assertOk();
        $methods = collect($response->json('data'));
        $this->assertSame(['currency' => 'KWD', 'minimum' => '100.000', 'maximum' => '200.000'], $methods->firstWhere('slug', 'deema')['amount_limits']);
        $this->assertArrayNotHasKey('amount_limits', $methods->firstWhere('slug', 'tap'));
        $this->assertStringNotContainsString('sk_test_synthetic', $response->getContent());
        $this->tap->update(['test_mode' => false, 'credentials' => ['secret_key' => 'sk_live_synthetic']]);
        $methods = collect($this->getJson(self::HOST.'/payment-methods')->assertOk()->json('data'));
        $this->assertSame(['currency' => 'KWD', 'minimum' => '10.000', 'maximum' => null], $methods->firstWhere('slug', 'deema')['amount_limits']);
        $this->assertFalse($methods->firstWhere('slug', 'deema')['test_mode']);
        $this->assertNoOrderSideEffects();
    }

    public static function boundaries(): array
    {
        return ['below sandbox' => [true, '99.99', false], 'sandbox minimum' => [true, '100.00', true], 'sandbox maximum' => [true, '200.00', true],
            'above sandbox' => [true, '200.01', false], 'below live' => [false, '9.99', false], 'live minimum' => [false, '10.00', true], 'no live maximum' => [false, '250.25', true]];
    }

    #[DataProvider('boundaries')]
    public function test_quote_and_actual_checkout_enforce_the_same_boundaries_before_order_creation(bool $test, string $amount, bool $eligible): void
    {
        $this->product->update(['price' => $amount]);
        $this->tap->update(['test_mode' => $test, 'credentials' => ['secret_key' => $test ? 'sk_test_synthetic' : 'sk_live_synthetic']]);
        $body = $this->body();
        $quote = $this->postJson(self::HOST.'/checkout/quote', $body);
        if (! $eligible) {
            $quote->assertUnprocessable()->assertJsonValidationErrors('payment_method');
            $this->postJson(self::HOST.'/checkout', $body, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('payment_method')->assertJsonMissingPath('data');
            $this->assertNoOrderSideEffects();

            return;
        }
        $quote->assertOk()->assertJsonPath('data.amount_due', $amount);
        $this->assertNoOrderSideEffects();
        Http::fake(['api.tap.company/*' => Http::response(['id' => 'chg_synthetic', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://sandbox.payments.tap.company/synthetic']])]);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $first = $this->postJson(self::HOST.'/checkout', $body, $headers)->assertCreated();
        $this->postJson(self::HOST.'/checkout', $body, $headers)->assertCreated()->assertJsonPath('data.number', $first->json('data.number'));
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseCount('store_payment_transactions', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['source']['id'] === 'src_deema' && $request['amount'] === (float) $amount);
    }

    public function test_final_amount_includes_shipping_and_payment_adjustments_not_just_product_price(): void
    {
        $this->product->update(['price' => '80.00']);
        $this->store->update(['shipping_enabled' => true, 'shipping_flat_rate' => '10.00']);
        StorePaymentGateway::where('store_id', $this->store->id)->where('gateway', 'deema')->update(['presentation' => ['price_adjustment' => ['enabled' => true, 'type' => 'fixed', 'direction' => 'add', 'value' => '10.00']]]);
        $quote = $this->postJson(self::HOST.'/checkout/quote', $this->body())->assertOk()->assertJsonPath('data.amount_due', '100.00');
        Http::fake(['api.tap.company/*' => Http::response(['id' => 'chg_synthetic', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://sandbox.payments.tap.company/synthetic']])]);
        $this->postJson(self::HOST.'/checkout', $this->body(['payment_adjustment_token' => $quote->json('data.payment_adjustment_token')]), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        Http::assertSent(fn ($request) => (float) $request['amount'] === 100.0);
    }

    public function test_discounted_and_partly_gift_paid_baskets_reject_the_actual_deema_remainder_without_redeeming_cards(): void
    {
        Coupon::create(['store_id' => $this->store->id, 'code' => 'SYNTHETIC60', 'type' => 'fixed', 'value' => 60, 'is_active' => true]);
        $discount = $this->body(['coupon_code' => 'SYNTHETIC60']);
        $this->postJson(self::HOST.'/checkout/quote', $discount)->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->postJson(self::HOST.'/checkout', $discount, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $code = app(GiftCards::class)->issue($this->store, ['initial_value' => '60.00'], $this->store->owner_user_id)['code'];
        $gift = $this->body(['gift_cards' => [$code]]);
        $this->postJson(self::HOST.'/checkout/quote', $gift)->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->postJson(self::HOST.'/checkout', $gift, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertSame('60.00', app(GiftCards::class)->lookup($this->store, $code)->balance);
        $this->assertNoOrderSideEffects();
    }

    public function test_precommit_uses_current_environment_and_price_even_when_an_older_quote_was_valid(): void
    {
        $this->tap->update(['test_mode' => false, 'credentials' => ['secret_key' => 'sk_live_synthetic']]);
        $this->product->update(['price' => '15.00']);
        $this->postJson(self::HOST.'/checkout/quote', $this->body())->assertOk();
        $this->tap->update(['test_mode' => true, 'credentials' => ['secret_key' => 'sk_test_synthetic']]);
        $this->postJson(self::HOST.'/checkout', $this->body(), ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->product->update(['price' => '150.00']);
        $this->postJson(self::HOST.'/checkout/quote', $this->body())->assertOk();
        $this->product->update(['price' => '90.00']);
        $this->postJson(self::HOST.'/checkout', $this->body(), ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertNoOrderSideEffects();
    }

    public function test_a_fully_gift_paid_order_does_not_require_a_deema_charge_or_minimum(): void
    {
        $this->product->update(['price' => '50.00']);
        $code = app(GiftCards::class)->issue($this->store, ['initial_value' => '50.00'], $this->store->owner_user_id)['code'];
        $body = $this->body(['gift_cards' => [$code]]);
        $this->postJson(self::HOST.'/checkout/quote', $body)->assertOk()->assertJsonPath('data.amount_due', '0.00');
        $this->postJson(self::HOST.'/checkout', $body, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.payment_method', 'gift_card')->assertJsonPath('data.payment_status', 'paid');
        Http::assertNothingSent();
    }
}
