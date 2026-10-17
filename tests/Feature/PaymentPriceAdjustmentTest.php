<?php

namespace Tests\Feature;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\User;
use App\Services\Commerce\PaymentPriceAdjustment;
use App\Services\Commerce\StorePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentPriceAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_itemized_bnpl_payloads_balance_fees_and_discounts_without_negative_items(): void
    {
        [$store, $product, $gateway] = $this->fixture();
        $store->update(['currency' => 'SAR']);
        foreach (['tabby', 'tamara'] as $method) {
            foreach (['10.00', '-10.00'] as $adjustment) {
                Http::swap(new Factory);
                Http::preventStrayRequests();
                $gateway->update(['gateway' => $method, 'credentials' => ['secret_key' => 'synthetic', 'merchant_code' => 'synthetic', 'api_token' => 'synthetic', 'notification_token' => 'synthetic']]);
                $order = StoreOrder::withoutGlobalScopes()->create(['store_id' => $store->id, 'order_number' => (string) Str::uuid(), 'customer_name' => 'Synthetic Buyer', 'customer_email' => 'buyer@example.test', 'customer_phone' => '+966500000001',
                    'status' => 'pending', 'payment_method' => $method, 'payment_status' => 'pending', 'currency' => 'SAR', 'subtotal' => '100.00', 'shipping_total' => '5.00', 'tax_total' => '0.00', 'discount_total' => '0.00',
                    'grand_total' => bcadd('105.00', $adjustment, 2), 'payment_adjustment' => ['amount' => $adjustment]]);
                $order->items()->create(['store_id' => $store->id, 'store_product_id' => $product->id, 'name' => 'Bag', 'quantity' => 1, 'unit_price' => '100.00', 'line_total' => '100.00']);
                Http::fake(['*' => Http::response($method === 'tabby' ? ['status' => 'created', 'payment' => ['id' => 'bnpl-session'], 'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://checkout.tabby.ai/session']]]]] : ['order_id' => 'bnpl-session', 'checkout_url' => 'https://checkout.tamara.co/session'])]);
                app(StorePaymentService::class)->start($store, $order, $gateway);
                Http::assertSentCount(1);
                $request = Http::recorded()[0][0];
                $items = $method === 'tabby' ? $request['payment']['order']['items'] : $request['items'];
                $sum = '0.00';
                foreach ($items as $item) {
                    $unit = $method === 'tabby' ? $item['unit_price'] : $item['unit_price']['amount'];
                    $this->assertGreaterThanOrEqual(0, (float) $unit);
                    $sum = bcadd($sum, bcmul((string) $unit, (string) $item['quantity'], 2), 2);
                }
                $discount = $method === 'tabby' ? $request['payment']['order']['discount_amount'] : $request['discount']['amount']['amount'];
                $this->assertSame($order->grand_total, bcsub(bcadd($sum, '5.00', 2), (string) $discount, 2));
            }
        }
    }

    public function test_retry_after_invoice_failure_never_reapplies_a_changed_fee(): void
    {
        [$store, $product, $gateway, $body] = $this->fixture('stripe');
        $quote = $this->quote($body);
        $calls = 0;
        Http::fake(['api.stripe.com/*' => function () use (&$calls) {
            return ++$calls === 1 ? Http::response([], 500) : Http::response(['id' => 'cs_retry_fee', 'url' => 'https://checkout.stripe.com/c/pay/cs_retry_fee']);
        }]);
        $failed = $this->postJson('http://fees.sellchase.com/api/v1/storefront/checkout', $body + ['payment_adjustment_token' => $quote['payment_adjustment_token']], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('data.grand_total', '136.40');
        $gateway->update(['presentation' => ['price_adjustment' => ['enabled' => true, 'type' => 'fixed', 'direction' => 'add', 'value' => '99.00']]]);
        $this->postJson('http://fees.sellchase.com/api/v1/storefront/checkout/payment/retry', ['token' => $failed->json('payment_retry.token')])->assertOk()->assertJsonPath('data.grand_total', '136.40');
        $this->assertSame(2, $calls);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame(13640, $request['line_items'][0]['price_data']['unit_amount']);
        }
        $this->assertDatabaseCount('store_orders', 1);
    }

    private function fixture(string $method = 'cod'): array
    {
        Http::preventStrayRequests();
        Queue::fake([BridgeStorefrontOrderJob::class]);
        $owner = User::factory()->create();
        $store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Fees', 'slug' => 'fees', 'currency' => 'EGP', 'status' => 'active',
            'shipping_enabled' => true, 'shipping_flat_rate' => '25.00', 'tax_enabled' => true, 'tax_rate' => '10', 'tax_prices_include' => false]);
        StoreDomain::create(['store_id' => $store->id, 'host' => 'fees.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $product = Product::create(['store_id' => $store->id, 'user_id' => $owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 10]);
        $gateway = StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => $method, 'enabled' => true,
            'credentials' => ['secret_key' => 'sk_test_synthetic', 'webhook_secret' => 'whsec_synthetic'],
            'presentation' => ['price_adjustment' => ['enabled' => true, 'type' => 'percentage', 'direction' => 'add', 'value' => '10.00']]]);
        Coupon::create(['store_id' => $store->id, 'code' => 'TEN', 'type' => 'fixed', 'value' => 10, 'is_active' => true]);
        $body = ['customer_name' => 'Synthetic Buyer', 'customer_email' => 'buyer@example.test', 'payment_method' => $method, 'coupon_code' => 'TEN',
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'cart_mode' => 'direct'];

        return [$store, $product, $gateway, $body];
    }

    private function quote(array $body): array
    {
        return $this->postJson('http://fees.sellchase.com/api/v1/storefront/checkout/quote', $body)->assertOk()->json('data');
    }

    public function test_quote_order_and_idempotent_replay_preserve_one_frozen_adjustment(): void
    {
        [$store, $product, $gateway, $body] = $this->fixture();
        $quote = $this->quote($body);
        $this->assertSame('124.00', $quote['totals']['payment_adjustment']['base_total']);
        $this->assertSame('12.40', $quote['totals']['payment_adjustment_total']);
        $this->assertSame('136.40', $quote['totals']['grand_total']);
        $this->assertDatabaseCount('store_orders', 0);
        $body['payment_adjustment_token'] = $quote['payment_adjustment_token'];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $first = $this->postJson('http://fees.sellchase.com/api/v1/storefront/checkout', $body, $headers)->assertCreated()
            ->assertJsonPath('data.grand_total', '136.40')->assertJsonPath('data.payment_adjustment_total', '12.40');
        $gateway->update(['presentation' => null]);
        $this->postJson('http://fees.sellchase.com/api/v1/storefront/checkout', $body, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('data.grand_total', '136.40');
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
        $this->assertSame(1, (int) $product->fresh()->reserved_quantity);
        Http::assertNothingSent();
    }

    public function test_missing_forged_stale_or_other_method_quotes_do_not_create_orders_or_reserve_stock(): void
    {
        [$store, $product, $gateway, $body] = $this->fixture();
        $quote = $this->quote($body);
        foreach ([null, str_repeat('a', 64)] as $token) {
            $this->postJson('http://fees.sellchase.com/api/v1/storefront/checkout', $body + ['payment_adjustment_token' => $token], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        }
        $gateway->update(['presentation' => ['price_adjustment' => ['enabled' => true, 'type' => 'fixed', 'direction' => 'add', 'value' => '20']]]);
        $this->postJson('http://fees.sellchase.com/api/v1/storefront/checkout', $body + ['payment_adjustment_token' => $quote['payment_adjustment_token']], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => 'bank_transfer', 'enabled' => true]);
        $body['payment_method'] = 'bank_transfer';
        $this->postJson('http://fees.sellchase.com/api/v1/storefront/checkout', $body + ['payment_adjustment_token' => $quote['payment_adjustment_token']], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
        Http::assertNothingSent();
    }

    public function test_online_provider_receives_the_reviewed_total_and_receipt_keeps_the_snapshot(): void
    {
        [$store, $product, $gateway, $body] = $this->fixture('stripe');
        $quote = $this->quote($body);
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_fee', 'url' => 'https://checkout.stripe.com/c/pay/cs_fee'])]);
        $result = $this->postJson('http://fees.sellchase.com/api/v1/storefront/checkout', $body + ['payment_adjustment_token' => $quote['payment_adjustment_token']], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        Http::assertSent(fn ($request) => $request['line_items'][0]['price_data']['unit_amount'] === 13640);
        $gateway->update(['presentation' => null]);
        $this->postJson('http://fees.sellchase.com/api/v1/storefront/checkout/receipt', ['token' => $result->json('receipt.token')])->assertOk()->assertJsonPath('data.payment_adjustment_total', '12.40')->assertJsonPath('data.grand_total', '136.40');
    }

    public function test_rounding_discount_caps_and_currency_units_are_exact(): void
    {
        $service = app(PaymentPriceAdjustment::class);
        $totals = ['subtotal' => '100.00', 'grand_total' => '125.50'];
        $rule = ['enabled' => true, 'type' => 'percentage', 'direction' => 'subtract', 'value' => '10'];
        $this->assertSame('112.95', $service->apply($totals, 'EGP', 'cod', $rule)['grand_total']);
        $this->assertSame('87.85', $service->apply(['grand_total' => '100.00'], 'EGP', 'cod', array_replace($rule, ['value' => '12.15']))['grand_total']);
        $this->assertSame('88.00', $service->apply(['grand_total' => '100.00'], 'JPY', 'cod', array_replace($rule, ['value' => '12.15']))['grand_total']);
        $this->assertSame('0.00', $service->apply($totals, 'EGP', 'cod', array_replace($rule, ['type' => 'fixed', 'value' => '500']))['grand_total']);
        $this->assertSame('125.50', $service->apply($totals, 'EGP', 'cod', null)['grand_total']);
    }

    public function test_percentage_limits_and_tenant_quote_binding_are_enforced(): void
    {
        [$store] = $this->fixture();
        foreach (['0', '100', '100.00000000000001', '101', '-5', '1e1', 'arbitrary'] as $value) {
            try {
                PaymentPriceAdjustment::configuration(['enabled' => true, 'type' => 'percentage', 'direction' => 'add', 'value' => $value]);
                $this->fail('Invalid percentage accepted: '.$value);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('0.0001', PaymentPriceAdjustment::configuration(['enabled' => true, 'type' => 'percentage', 'direction' => 'add', 'value' => '0.0001'])['value']);
        $this->assertSame('99.99999999999999999999', PaymentPriceAdjustment::configuration(['enabled' => true, 'type' => 'percentage', 'direction' => 'subtract', 'value' => '99.99999999999999999999'])['value']);
        $snapshot = app(PaymentPriceAdjustment::class)->forMethod($store, 'cod', ['grand_total' => '100.00'])['payment_adjustment'];
        $token = app(PaymentPriceAdjustment::class)->token($store, $snapshot);
        $store->id = 99999;
        $this->expectException(ValidationException::class);
        app(PaymentPriceAdjustment::class)->assertQuoted($store, $snapshot, $token);
    }

    public function test_populated_adjustment_migration_refuses_to_discard_order_history(): void
    {
        [$store] = $this->fixture();
        StoreOrder::create(['store_id' => $store->id, 'order_number' => 'LEGACY', 'customer_name' => 'Synthetic Buyer', 'status' => 'pending', 'currency' => 'EGP', 'payment_adjustment' => ['amount' => '1.00']]);
        $migration = require database_path('migrations/2026_10_18_000002_add_payment_adjustment_to_store_orders.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
