<?php

namespace Tests\Feature;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCheckoutAttempt;
use App\Models\StoreCustomer;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use App\Models\User;
use App\Services\Commerce\CheckoutAttempts;
use App\Services\Commerce\CustomerAuthService;
use App\Services\Commerce\StorePaymentService;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StoreCheckoutIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private string $url = 'http://repeat.sellchase.com/api/v1/storefront/checkout';

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([BridgeStorefrontOrderJob::class]);
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Repeat', 'slug' => 'repeat', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'repeat.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 1]);
        $this->headers = ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function payload(): array
    {
        return ['customer_name' => 'Replay buyer', 'customer_email' => 'replay@example.test', 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]];
    }

    private function request(): Request
    {
        app(CurrentStore::class)->set($this->store);

        return Request::create($this->url, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $this->headers['Idempotency-Key']], json_encode($this->payload(), JSON_THROW_ON_ERROR));
    }

    public function test_cart_and_direct_checkout_replay_without_repeating_inventory_coupon_or_job(): void
    {
        foreach (['cart', 'direct'] as $mode) {
            app(CurrentStore::class)->set($this->store);
            $coupon = Coupon::create(['store_id' => $this->store->id, 'code' => strtoupper($mode), 'type' => 'fixed', 'value' => 10, 'is_active' => true]);
            $this->product->update(['stock_quantity' => $mode === 'cart' ? 1 : 2]);
            $headers = ['Idempotency-Key' => (string) Str::uuid()];
            $payload = $this->payload() + ['cart_mode' => $mode, 'coupon_code' => $coupon->code];
            $first = $this->postJson($this->url, $payload, $headers)->assertCreated();
            $this->postJson($this->url.'?lang=ar', array_reverse($payload, true), $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
            app(CurrentStore::class)->set($this->store);
            $this->assertSame(1, $coupon->usages()->count());
        }
        $this->assertDatabaseCount('store_orders', 2);
        $this->assertDatabaseCount('store_inventory_movements', 2);
        $this->assertDatabaseCount('store_payment_transactions', 2);
        Queue::assertPushed(BridgeStorefrontOrderJob::class, 2);
    }

    public function test_changed_body_is_rejected_but_recovery_survives_product_removal_from_sale(): void
    {
        $first = $this->postJson($this->url, $this->payload(), $this->headers)->assertCreated();
        $this->postJson($this->url, array_merge($this->payload(), ['customer_name' => 'Changed']), $this->headers)->assertConflict();
        $this->product->update(['is_active' => false, 'price' => 999]);
        $this->postJson($this->url, $this->payload(), $this->headers)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson($this->url.'/recover', [], $this->headers)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('store_orders', 1);
    }

    public function test_validation_releases_claim_with_explicit_safe_rejection(): void
    {
        $this->postJson($this->url, ['customer_email' => 'bad'], $this->headers)->assertUnprocessable()->assertJsonPath('checkout_rejected', true);
        $this->assertDatabaseCount('store_checkout_attempts', 0);
        $this->postJson($this->url, $this->payload(), $this->headers)->assertCreated();
        $this->assertDatabaseCount('store_orders', 1);
    }

    public function test_malformed_keys_are_rejected_and_legacy_requests_remain_supported(): void
    {
        $this->postJson($this->url, $this->payload(), ['Idempotency-Key' => 'predictable'])->assertUnprocessable();
        $this->assertDatabaseCount('store_checkout_attempts', 0);
        $this->postJson($this->url, $this->payload())->assertCreated();
        $this->assertDatabaseCount('store_checkout_attempts', 0);
    }

    public function test_keys_are_scoped_to_customer_cart_and_store(): void
    {
        app(CurrentStore::class)->set($this->store);
        $customer = StoreCustomer::create(['name' => 'Customer', 'email' => 'customer@example.test', 'password' => bcrypt('local-fixture-only'), 'is_active' => true]);
        $token = app(CustomerAuthService::class)->issueToken($customer);
        $headers = $this->headers + ['Authorization' => 'Bearer '.$token, 'X-Cart-Token' => (string) Str::uuid()];
        $this->postJson($this->url, $this->payload(), $headers)->assertCreated();
        $this->postJson($this->url.'/recover', [], $this->headers)->assertNotFound();
        $this->postJson($this->url.'/recover', [], $this->headers + ['Authorization' => 'Bearer '.$token])->assertNotFound();
        $this->postJson($this->url, $this->payload(), $this->headers)->assertConflict();
        $this->postJson($this->url.'/recover', [], $headers)->assertCreated();
        app(CurrentStore::class)->forget();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'repeat-other', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $other->id, 'host' => 'repeat-other.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->postJson(str_replace('repeat.', 'repeat-other.', $this->url).'/recover', [], $headers)->assertNotFound();
    }

    public function test_pending_claim_expires_without_permitting_the_old_worker_to_create_an_order(): void
    {
        $service = app(CheckoutAttempts::class);
        $request = $this->request();
        $old = $service->begin($request);
        $this->assertInstanceOf(StoreCheckoutAttempt::class, $old);
        $this->postJson($this->url, $this->payload(), $this->headers)->assertConflict()->assertJsonPath('checkout_pending', true);
        $this->postJson($this->url.'/recover', [], $this->headers)->assertConflict()->assertJsonPath('checkout_pending', true);
        $this->travel(3)->minutes();
        $this->postJson($this->url.'/recover', [], $this->headers)->assertConflict()->assertJsonPath('checkout_retry', true);
        $request = $this->request();
        $new = $service->begin($request);
        $this->assertInstanceOf(StoreCheckoutAttempt::class, $new);
        $this->assertNotSame($old->lease, $new->lease);
        $request->attributes->set(CheckoutAttempts::ATTRIBUTE, $old);
        try {
            DB::transaction(fn () => $service->lock($request));
            $this->fail('Expired worker must be fenced out.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('store_orders', 0);
        $this->travel(3)->minutes();
        $this->postJson($this->url, $this->payload(), $this->headers)->assertCreated();
        $this->assertDatabaseCount('store_orders', 1);
    }

    public function test_crash_after_order_commit_recovers_missing_payment_and_never_reorders(): void
    {
        $first = $this->postJson($this->url, $this->payload(), $this->headers)->assertCreated();
        StoreCheckoutAttempt::query()->update(['response_status' => null, 'response_body' => null]);
        StorePaymentTransaction::query()->delete();
        $recovery = $this->postJson($this->url.'/recover', [], $this->headers)->assertUnprocessable()->assertJsonPath('data.id', $first->json('data.id'));
        $token = $recovery->json('payment_retry.token');
        $payment = $this->postJson($this->url.'/payment/retry', ['token' => $token])->assertOk()->assertJsonPath('payment.status', 'pending');
        $this->postJson($this->url.'/payment/retry', ['token' => $token])->assertOk()->assertJsonPath('payment.transaction_id', $payment->json('payment.transaction_id'));
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseCount('store_payment_transactions', 1);
        $this->assertDatabaseCount('store_inventory_movements', 1);
    }

    public function test_cached_personal_data_is_encrypted_and_deleted_order_remains_a_tombstone(): void
    {
        $this->postJson($this->url, $this->payload(), $this->headers)->assertCreated();
        $raw = DB::table('store_checkout_attempts')->first();
        $this->assertStringNotContainsString('replay@example.test', $raw->response_body);
        $this->assertSame(hash('sha256', $this->headers['Idempotency-Key']), $raw->key_hash);
        $this->assertArrayNotHasKey('response_body', StoreCheckoutAttempt::firstOrFail()->toArray());
        StoreOrder::withoutGlobalScopes()->delete();
        $this->postJson($this->url.'/recover', [], $this->headers)->assertGone();
        $this->postJson($this->url, $this->payload(), $this->headers)->assertGone();
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_nested_payment_start_calls_only_one_provider_and_preserves_early_paid_callback(): void
    {
        app(CurrentStore::class)->set($this->store);
        $setting = StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'stripe', 'enabled' => true, 'credentials' => ['secret_key' => 'synthetic-test-key']]);
        Http::fake(function () use ($setting) {
            $transaction = StorePaymentTransaction::firstOrFail();
            $order = StoreOrder::withoutGlobalScopes()->firstOrFail();
            try {
                app(StorePaymentService::class)->start($this->store, $order, $setting);
                $this->fail('Second caller cannot start an in-flight transaction.');
            } catch (ValidationException) {
            }
            $transaction->update(['status' => 'paid', 'paid_at' => now()]);

            return Http::response(['id' => 'cs_synthetic', 'url' => 'https://checkout.stripe.com/synthetic']);
        });
        $first = $this->postJson($this->url, $this->payload() + ['payment_method' => 'stripe'], $this->headers)->assertCreated()->assertJsonPath('payment.status', 'paid');
        $this->postJson($this->url, $this->payload() + ['payment_method' => 'stripe'], $this->headers)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $recovered = $this->postJson($this->url.'/recover', [], $this->headers)->assertUnprocessable();
        $this->postJson($this->url.'/payment/retry', ['token' => $recovered->json('payment_retry.token')])->assertOk()->assertJsonPath('payment.status', 'paid')->assertJsonPath('payment.redirect_url', null);
        $this->assertDatabaseCount('store_payment_transactions', 1);
        Http::assertSentCount(1);
    }

    public function test_failed_provider_can_resume_same_transaction_after_recovering_order(): void
    {
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'stripe', 'enabled' => true, 'credentials' => ['secret_key' => 'synthetic-test-key']]);
        Http::fake(['api.stripe.com/*' => Http::sequence()->push([], 500)->push(['id' => 'cs_recovered', 'url' => 'https://checkout.stripe.com/recovered'])]);
        $payload = $this->payload() + ['payment_method' => 'stripe'];
        $this->postJson($this->url, $payload, $this->headers)->assertUnprocessable()->assertJsonMissingPath('checkout_rejected');
        $this->travel(3)->hours();
        $recovered = $this->postJson($this->url.'/recover', [], $this->headers)->assertUnprocessable();
        $token = $recovered->json('payment_retry.token');
        $this->postJson($this->url.'/payment/retry', ['token' => $token])->assertOk()->assertJsonPath('payment.redirect_url', 'https://checkout.stripe.com/recovered');
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseCount('store_payment_transactions', 1);
        $requests = Http::recorded();
        $this->assertSame($requests[0][0]->header('Idempotency-Key'), $requests[1][0]->header('Idempotency-Key'));
    }

    public function test_created_payment_resumes_once_and_late_provider_failure_cannot_undo_paid_callback(): void
    {
        $created = $this->postJson($this->url, $this->payload(), $this->headers)->assertCreated();
        app(CurrentStore::class)->set($this->store);
        $order = StoreOrder::findOrFail($created->json('data.id'));
        $order->update(['payment_method' => 'stripe']);
        $transaction = StorePaymentTransaction::firstOrFail();
        $transaction->update(['gateway' => 'stripe', 'status' => 'created']);
        $setting = StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'stripe', 'enabled' => true, 'credentials' => ['secret_key' => 'synthetic-test-key']]);
        Http::fake(function () use ($transaction) {
            $transaction->update(['status' => 'paid', 'paid_at' => now()]);

            return Http::response([], 500);
        });
        $result = app(StorePaymentService::class)->start($this->store, $order, $setting);
        $this->assertSame('paid', $result['status']);
        $this->assertSame($transaction->id, $result['transaction_id']);
        $this->assertDatabaseCount('store_payment_transactions', 1);
        $this->assertNull($transaction->fresh()->failed_at);
        Http::assertSentCount(1);
    }

    public function test_cancelled_order_does_not_replay_an_old_payment_redirect(): void
    {
        $this->postJson($this->url, $this->payload(), $this->headers)->assertCreated();
        StoreOrder::withoutGlobalScopes()->update(['status' => 'cancelled']);
        $this->postJson($this->url.'/recover', [], $this->headers)->assertConflict();
        $this->postJson($this->url, $this->payload(), $this->headers)->assertConflict();
        $this->assertDatabaseCount('store_orders', 1);
    }
}
