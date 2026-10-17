<?php

namespace Tests\Feature;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use App\Models\User;
use App\Services\Commerce\CheckoutFields;
use App\Services\Commerce\HostedPaymentGateways;
use App\Services\Commerce\HostedPaymentReconciliation;
use App\Services\Commerce\StorePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HostedPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_required_contact_fields_are_enforced_before_an_order_is_created(): void
    {
        [$store] = $this->fixtures('xpay');
        foreach (['xpay', 'paylink', 'easykash'] as $slug) {
            $fields = collect(app(CheckoutFields::class)->effective($store, $slug, false))->keyBy('key');
            $this->assertTrue($fields['phone']['required']);
            $rules = app(CheckoutFields::class)->rules($store, $slug, false);
            $validator = Validator::make(['customer_name' => 'Only', 'customer_email' => 'buyer@example.test', 'customer_phone' => 'invalid'], $rules);
            $this->assertTrue($validator->fails());
            $this->assertTrue($validator->errors()->has('customer_phone'));
            if ($slug === 'xpay') {
                $this->assertTrue($validator->errors()->has('customer_name'));
            }
        }
        Http::assertNothingSent();
    }

    private function fixtures(string $slug, string $currency = 'EGP'): array
    {
        Http::preventStrayRequests();
        Queue::fake([BridgeStorefrontOrderJob::class]);
        $owner = User::factory()->create();
        $store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => $slug, 'slug' => $slug, 'currency' => $currency, 'status' => 'active']);
        $setting = StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => $slug, 'enabled' => true, 'test_mode' => $slug !== 'easykash',
            'credentials' => ['api_key' => 'synthetic-key', 'api_secret' => 'synthetic-secret', 'merchant_id' => 'MID-synthetic', 'secret_key' => 'sk_test_synthetic', 'country' => 'KW', 'community_id' => 'community-test', 'variable_amount_id' => '1']]);
        $order = StoreOrder::withoutGlobalScopes()->create(['store_id' => $store->id, 'order_number' => 'TEST-'.$slug, 'status' => 'pending', 'currency' => $currency,
            'customer_name' => 'Synthetic Buyer', 'customer_email' => 'buyer@example.test', 'customer_phone' => '+201000000000',
            'payment_method' => $slug, 'payment_status' => 'pending', 'subtotal' => '100.25', 'grand_total' => '100.25']);

        return [$store, $order, $setting];
    }

    public function test_hosted_checkouts_resume_the_same_session_and_only_settle_verified_money(): void
    {
        foreach (HostedPaymentGateways::PROVIDERS as $slug) {
            Http::swap(new Factory);
            [$store, $order, $setting] = $this->fixtures($slug, $slug === 'myfatoorah' ? 'KWD' : 'EGP');
            $transaction = null;
            Http::fake(function ($request) use ($slug, &$transaction) {
                if (str_ends_with($request->url(), '/api/auth')) {
                    return Http::response(['id_token' => 'synthetic-access']);
                }
                if ($transaction === null) {
                    return Http::response(match ($slug) {
                        'kashier' => ['_id' => 'session-1', 'sessionUrl' => 'https://payments.kashier.io/session/session-1'],
                        'moyasar' => ['id' => 'invoice-1', 'url' => 'https://checkout.moyasar.com/invoices/invoice-1'],
                        'paylink' => ['transactionNo' => 'invoice-1', 'url' => 'https://payment.paylink.sa/pay/invoice-1'],
                        'ziina' => ['id' => 'intent-1', 'redirect_url' => 'https://pay.ziina.com/intent-1'],
                        'myfatoorah' => ['IsSuccess' => true, 'Data' => ['InvoiceId' => 123, 'InvoiceURL' => 'https://demo.myfatoorah.com/ie/123']],
                        'easykash' => ['redirectUrl' => 'https://www.easykash.net/DirectPayV1/TEST123'],
                        'xpay' => ['data' => ['transaction_uuid' => 'uuid-1', 'iframe_url' => 'https://dev-payment.xpay.app/pay/uuid-1']],
                        'upayments' => ['status' => true, 'data' => ['link' => 'https://sandbox.upayments.com/?session_id=session-1']],
                    });
                }

                return Http::response(match ($slug) {
                    'kashier' => ['data' => ['sessionId' => 'session-1', 'merchantOrderId' => $transaction->idempotency_key, 'amount' => '100.25', 'currency' => 'EGP', 'status' => 'SUCCESS']],
                    'moyasar' => ['id' => 'invoice-1', 'metadata' => ['transaction' => $transaction->idempotency_key], 'amount' => 10025, 'currency' => 'EGP', 'status' => 'paid'],
                    'paylink' => ['transactionNo' => 'invoice-1', 'gatewayOrderRequest' => ['orderNumber' => $transaction->idempotency_key, 'amount' => '100.25', 'currency' => 'EGP'], 'orderStatus' => 'Paid'],
                    'ziina' => ['id' => 'intent-1', 'operation_id' => $transaction->idempotency_key, 'amount' => 10025, 'currency_code' => 'EGP', 'status' => 'completed'],
                    'myfatoorah' => ['IsSuccess' => true, 'Data' => ['InvoiceId' => 123, 'CustomerReference' => $transaction->idempotency_key, 'InvoiceValue' => '100.250', 'InvoiceDisplayValue' => '100.250 KD', 'InvoiceStatus' => 'Paid']],
                    'easykash' => ['Amount' => '100.25', 'status' => 'PAID', 'easykashRef' => 'payment-1'],
                    'xpay' => ['data' => ['uuid' => 'uuid-1', 'member_id' => $transaction->idempotency_key, 'total_amount' => '100.25', 'total_amount_currency' => 'EGP', 'status' => 'SUCCESSFUL']],
                    'upayments' => ['data' => ['transaction' => ['session_id' => 'session-1', 'reference' => (string) $transaction->id, 'total_paid_non_kwd' => '100.250', 'currency_type' => 'EGP', 'result' => 'CAPTURED']]],
                });
            });
            $service = app(StorePaymentService::class);
            $first = $service->start($store, $order, $setting);
            $this->assertSame('redirect_pending', $first['status'], $slug);
            $transaction = StorePaymentTransaction::findOrFail($first['transaction_id']);
            Http::assertSent(function ($request) use ($slug, $transaction) {
                $reference = $transaction->idempotency_key;

                return match ($slug) {
                    'kashier' => $request->method() === 'POST' && $request->url() === 'https://test-api.kashier.io/v3/payment/sessions'
                        && $request['amount'] === '100.25' && $request['currency'] === 'EGP' && $request['order'] === $reference && $request->hasHeader('api-key', 'synthetic-key'),
                    'moyasar' => $request->method() === 'POST' && $request->url() === 'https://api.moyasar.com/v1/invoices'
                        && $request['amount'] === 10025 && $request['metadata']['transaction'] === $reference,
                    'paylink' => $request->method() === 'POST' && $request->url() === 'https://restpilot.paylink.sa/api/addInvoice'
                        && $request['amount'] === 100.25 && $request['orderNumber'] === $reference,
                    'ziina' => $request->method() === 'POST' && $request->url() === 'https://api-v2.ziina.com/api/payment_intent'
                        && $request['amount'] === 10025 && $request['operation_id'] === $reference && $request['test'] === true,
                    'myfatoorah' => $request->method() === 'POST' && $request->url() === 'https://apitest.myfatoorah.com/v2/SendPayment'
                        && $request['InvoiceValue'] === 100.25 && $request['CustomerReference'] === $reference && $request['NotificationOption'] === 'LNK',
                    'easykash' => $request->method() === 'POST' && $request->url() === 'https://back.easykash.net/api/directpayv1/pay'
                        && $request['amount'] === 100.25 && $request['currency'] === 'EGP' && $request['customerReference'] === $reference,
                    'xpay' => $request->method() === 'POST' && $request->url() === 'https://staging.xpay.app/api/v1/payments/pay/variable-amount'
                        && $request['amount'] === 100.25 && $request['membership_id'] === $reference && $request['variable_amount_id'] === 1,
                    'upayments' => $request->method() === 'POST' && $request->url() === 'https://sandboxapi.upayments.com/api/v1/charge'
                        && $request['order']['id'] === $reference && $request['order']['amount'] === 100.25
                        && $request->header('X-Signature')[0] === base64_encode(hash_hmac('sha256', $request->header('X-Timestamp')[0].'POSTcharge'.$request->body(), 'synthetic-secret', true)),
                };
            });
            $count = Http::recorded()->count();
            $this->assertSame($first, $service->start($store, $order, $setting));
            $this->assertSame($count, Http::recorded()->count());
            $this->assertStringNotContainsString('synthetic-secret', json_encode($transaction->metadata));
            $this->assertSame('paid', app(HostedPaymentReconciliation::class)->refresh($transaction), $slug);
            $this->assertSame('paid', $order->fresh()->payment_status);
            $count = Http::recorded()->count();
            $this->assertSame('paid', app(HostedPaymentReconciliation::class)->refresh($transaction->fresh()));
            $this->assertSame($count, Http::recorded()->count());
            Cache::flush();
        }
    }

    public function test_forged_return_cannot_mark_an_order_paid_or_trigger_a_provider_request(): void
    {
        [$store, $order, $setting] = $this->fixtures('ziina');
        Http::fake(['*' => Http::response(['id' => 'intent-1', 'redirect_url' => 'https://pay.ziina.com/intent-1'])]);
        $first = app(StorePaymentService::class)->start($store, $order, $setting);
        $count = Http::recorded()->count();
        $this->getJson('/api/v1/payment-returns/hosted/'.$first['transaction_id'].'?payment_key=forged&status=completed')->assertForbidden();
        $this->assertSame($count, Http::recorded()->count());
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_wrong_amount_currency_reference_and_provider_session_are_rejected(): void
    {
        [$store, $order, $setting] = $this->fixtures('ziina');
        Http::fake(['*' => Http::response(['id' => 'intent-1', 'redirect_url' => 'https://pay.ziina.com/intent-1'])]);
        $first = app(StorePaymentService::class)->start($store, $order, $setting);
        $transaction = StorePaymentTransaction::findOrFail($first['transaction_id']);
        $valid = ['id' => 'intent-1', 'operation_id' => $transaction->idempotency_key, 'amount' => 10025, 'currency_code' => 'EGP', 'status' => 'completed'];
        foreach ([['amount' => 1], ['currency_code' => 'USD'], ['operation_id' => 'other-order'], ['id' => 'other-session']] as $patch) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response(array_merge($valid, $patch))]);
            try {
                app(HostedPaymentGateways::class)->verify($transaction, $setting);
                $this->fail('Mismatched payment was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }
            $this->assertSame('pending', $order->fresh()->payment_status);
        }
    }

    public function test_return_query_cannot_override_server_verified_status(): void
    {
        [$store, $order, $setting] = $this->fixtures('ziina');
        $transaction = null;
        Http::fake(function () use (&$transaction) {
            return Http::response($transaction === null ? ['id' => 'intent-1', 'redirect_url' => 'https://pay.ziina.com/intent-1']
                : ['id' => 'intent-1', 'operation_id' => $transaction->idempotency_key, 'amount' => 10025, 'currency_code' => 'EGP', 'status' => 'pending']);
        });
        $first = app(StorePaymentService::class)->start($store, $order, $setting);
        $transaction = StorePaymentTransaction::findOrFail($first['transaction_id']);
        $key = app(HostedPaymentGateways::class)->returnKey($transaction);
        // Asynchronous collection and delayed provider delivery must not stop at 48 hours.
        $this->travel(3)->days();
        $this->get('/api/v1/payment-returns/hosted/'.$transaction->id.'?payment_key='.$key.'&status=completed&amount=1')->assertRedirect();
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_changed_merchant_account_or_environment_cannot_settle_an_existing_session(): void
    {
        [$store, $order, $setting] = $this->fixtures('ziina');
        Http::fake(['*' => Http::response(['id' => 'intent-1', 'redirect_url' => 'https://pay.ziina.com/intent-1'])]);
        $first = app(StorePaymentService::class)->start($store, $order, $setting);
        $transaction = StorePaymentTransaction::findOrFail($first['transaction_id']);
        $setting->update(['test_mode' => false]);
        $count = Http::recorded()->count();
        try {
            app(HostedPaymentGateways::class)->verify($transaction, $setting);
            $this->fail('Changed environment was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame($count, Http::recorded()->count());
    }
}
