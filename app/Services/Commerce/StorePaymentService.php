<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use App\Services\Storefront\StorefrontUrlGenerator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StorePaymentService
{
    public function __construct(private readonly StorefrontUrlGenerator $urls) {}

    /** @return array{transaction_id:int,status:string,redirect_url:?string} */
    public function start(Store $store, StoreOrder $order, StorePaymentGateway $setting): array
    {
        $transaction = StorePaymentTransaction::query()->create([
            'store_id' => $store->id,
            'store_order_id' => $order->id,
            'gateway' => $setting->gateway,
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'created',
            'amount' => $order->grand_total,
            'currency' => strtoupper($order->currency),
        ]);

        if (in_array($setting->gateway, ['cod', 'bank_transfer'], true)) {
            $transaction->update(['status' => 'pending']);
            return $this->result($transaction->fresh());
        }

        return $this->process($store, $order, $setting, $transaction);
    }

    /** Retry the same provider request with the original idempotency key. */
    public function retry(
        Store $store,
        StoreOrder $order,
        StorePaymentGateway $setting,
        StorePaymentTransaction $transaction,
    ): array {
        if ((int) $transaction->store_id !== (int) $store->id
            || (int) $transaction->store_order_id !== (int) $order->id
            || $transaction->gateway !== $setting->gateway
            || bccomp((string) $transaction->amount, (string) $order->grand_total, 2) !== 0
            || strtoupper($transaction->currency) !== strtoupper($order->currency)) {
            throw ValidationException::withMessages(['payment' => 'The payment retry does not match this order.']);
        }

        if (in_array($transaction->status, ['paid', 'pending', 'redirect_pending'], true)) {
            return $this->result($transaction);
        }

        if ($transaction->status !== 'failed') {
            throw ValidationException::withMessages(['payment' => 'This payment is already being processed.']);
        }

        $claimed = StorePaymentTransaction::query()
            ->whereKey($transaction->id)
            ->where('status', 'failed')
            ->update(['status' => 'processing', 'failed_at' => null, 'updated_at' => now()]);
        if ($claimed !== 1) {
            throw ValidationException::withMessages(['payment' => 'This payment is already being retried.']);
        }

        return $this->process($store, $order, $setting, $transaction->fresh());
    }

    /** @return array{transaction_id:int,status:string,redirect_url:?string} */
    private function process(Store $store, StoreOrder $order, StorePaymentGateway $setting, StorePaymentTransaction $transaction): array
    {
        if ($transaction->status !== 'processing') {
            $transaction->update(['status' => 'processing', 'failed_at' => null]);
        }

        try {
            $payload = match ($setting->gateway) {
                'stripe' => $this->stripe($store, $order, $setting, $transaction),
                'paypal' => $this->paypal($store, $order, $setting, $transaction),
                'tabby' => $this->tabby($store, $order, $setting, $transaction),
                'tamara' => $this->tamara($store, $order, $setting, $transaction),
                'paymob' => $this->paymob($store, $order, $setting, $transaction),
                'paytabs' => $this->paytabs($store, $order, $setting, $transaction),
                'hyperpay' => $this->hyperpay($store, $order, $setting, $transaction),
                'fawry' => $this->fawry($store, $order, $setting, $transaction),
                'tap' => $this->tap($store, $order, $setting, $transaction),
                'fawaterak' => $this->fawaterak($store, $order, $setting, $transaction),
                default => throw ValidationException::withMessages([
                    'payment_method' => "{$setting->gateway} is enabled but its processor connection is not configured yet.",
                ]),
            };
            $transaction->update($payload + ['status' => 'redirect_pending']);
        } catch (ValidationException $exception) {
            $transaction->update(['status' => 'failed', 'failed_at' => now()]);
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $transaction->update(['status' => 'failed', 'failed_at' => now()]);
            throw ValidationException::withMessages([
                'payment_method' => 'The payment provider could not start the transaction. Please try again.',
            ]);
        }

        return $this->result($transaction->fresh());
    }

    /** @return array{provider_reference:string,redirect_url:string} */
    private function stripe(Store $store, StoreOrder $order, StorePaymentGateway $setting, StorePaymentTransaction $transaction): array
    {
        $secret = trim((string) data_get($setting->credentials, 'secret_key'));
        $this->requireCredential($secret, 'Stripe secret key');
        $success = $this->successUrl($store, $order);
        $response = Http::asForm()->withToken($secret)
            ->withHeaders(['Idempotency-Key' => $transaction->idempotency_key])
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'client_reference_id' => (string) $transaction->id,
                'customer_email' => $order->customer_email,
                'success_url' => $success.'&provider_session={CHECKOUT_SESSION_ID}',
                'cancel_url' => $this->urls->publicUrl($store, '/checkout?payment=cancelled'),
                'line_items' => [[
                    'price_data' => ['currency' => strtolower($order->currency),
                        'unit_amount' => $this->minorAmount($order->grand_total, $order->currency),
                        'product_data' => ['name' => 'Order '.$order->order_number]],
                    'quantity' => 1,
                ]],
                'metadata' => ['transaction_id' => (string) $transaction->id, 'store_order_id' => (string) $order->id],
            ])->throw()->json();

        return ['provider_reference' => (string) data_get($response, 'id'), 'redirect_url' => (string) data_get($response, 'url')];
    }

    /** @return array{provider_reference:string,redirect_url:string} */
    private function paypal(Store $store, StoreOrder $order, StorePaymentGateway $setting, StorePaymentTransaction $transaction): array
    {
        $response = Http::withToken($this->paypalToken($setting))->acceptJson()
            ->withHeaders(['PayPal-Request-Id' => $transaction->idempotency_key, 'Prefer' => 'return=representation'])
            ->post($this->paypalBase($setting).'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => (string) $transaction->id, 'custom_id' => (string) $transaction->id,
                    'invoice_id' => $order->order_number,
                    'amount' => ['currency_code' => strtoupper($order->currency), 'value' => (string) $order->grand_total],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'return_url' => $this->successUrl($store, $order),
                    'cancel_url' => $this->urls->publicUrl($store, '/checkout?payment=cancelled'),
                    'user_action' => 'PAY_NOW',
                ]]],
            ])->throw()->json();
        $approval = collect(data_get($response, 'links', []))->firstWhere('rel', 'payer-action')
            ?? collect(data_get($response, 'links', []))->firstWhere('rel', 'approve');

        return ['provider_reference' => (string) data_get($response, 'id'), 'redirect_url' => (string) data_get($approval, 'href')];
    }

    /** @return array{provider_reference:string,redirect_url:string} */
    private function tabby(Store $store, StoreOrder $order, StorePaymentGateway $setting, StorePaymentTransaction $transaction): array
    {
        $secret = trim((string) data_get($setting->credentials, 'secret_key'));
        $merchantCode = trim((string) data_get($setting->credentials, 'merchant_code'));
        $this->requireCredential($secret, 'Tabby secret key');
        $this->requireCredential($merchantCode, 'Tabby merchant code');
        $shipping = $order->shipping_address ?? [];
        $base = strtoupper($order->currency) === 'SAR' ? 'https://api.tabby.sa' : 'https://api.tabby.ai';
        $response = Http::withToken($secret)->acceptJson()->post($base.'/api/v2/checkout', [
            'payment' => [
                'amount' => (string) $order->grand_total,
                'currency' => strtoupper($order->currency),
                'description' => 'Order '.$order->order_number,
                'buyer' => ['name' => $order->customer_name, 'email' => $order->customer_email, 'phone' => $order->customer_phone],
                'shipping_address' => ['city' => data_get($shipping, 'city', ''), 'address' => data_get($shipping, 'line1', ''), 'zip' => data_get($shipping, 'postal_code', '')],
                'order' => [
                    'reference_id' => $order->order_number,
                    'items' => $order->items->map(fn ($item) => [
                        'title' => $item->name, 'quantity' => $item->quantity, 'unit_price' => (string) $item->unit_price,
                        'category' => 'General', 'reference_id' => (string) $item->store_product_id,
                    ])->values()->all(),
                    'tax_amount' => (string) $order->tax_total,
                    'shipping_amount' => (string) $order->shipping_total,
                    'discount_amount' => (string) $order->discount_total,
                ],
                'meta' => ['transaction_id' => (string) $transaction->id, 'order_id' => (string) $order->id],
            ],
            'lang' => 'en',
            'merchant_code' => $merchantCode,
            'merchant_urls' => [
                'success' => $this->successUrl($store, $order),
                'cancel' => $this->urls->publicUrl($store, '/checkout?payment=cancelled'),
                'failure' => $this->urls->publicUrl($store, '/checkout?payment=failed'),
            ],
        ])->throw()->json();
        abort_unless(data_get($response, 'status') === 'created', 422, 'Tabby did not approve this checkout session.');
        $redirect = data_get($response, 'configuration.available_products.installments.0.web_url');

        return ['provider_reference' => (string) data_get($response, 'payment.id'), 'redirect_url' => (string) $redirect];
    }

    /** @return array{provider_reference:string,redirect_url:string} */
    private function tamara(Store $store, StoreOrder $order, StorePaymentGateway $setting, StorePaymentTransaction $transaction): array
    {
        $token = trim((string) data_get($setting->credentials, 'api_token'));
        $this->requireCredential($token, 'Tamara API token');
        $name = preg_split('/\s+/', trim($order->customer_name), 2) ?: [];
        $shipping = $order->shipping_address ?? [];
        $currency = strtoupper($order->currency);
        $country = strtoupper((string) data_get($shipping, 'country', $currency === 'AED' ? 'AE' : 'SA'));
        $money = fn ($amount) => ['amount' => (float) $amount, 'currency' => $currency];
        $base = $setting->test_mode ? 'https://api-sandbox.tamara.co' : 'https://api.tamara.co';
        $response = Http::withToken($token)->acceptJson()->post($base.'/checkout', [
            'total_amount' => $money($order->grand_total),
            'shipping_amount' => $money($order->shipping_total),
            'tax_amount' => $money($order->tax_total),
            'order_reference_id' => (string) $transaction->id,
            'order_number' => $order->order_number,
            'discount' => ['name' => 'Store discount', 'amount' => $money($order->discount_total)],
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->name, 'quantity' => $item->quantity, 'reference_id' => (string) $item->store_product_id,
                'type' => 'Physical', 'sku' => (string) $item->store_product_id,
                'unit_price' => $money($item->unit_price), 'tax_amount' => $money(0), 'discount_amount' => $money(0),
                'total_amount' => $money($item->line_total),
            ])->values()->all(),
            'consumer' => ['first_name' => $name[0] ?? $order->customer_name, 'last_name' => $name[1] ?? '-',
                'phone_number' => $order->customer_phone, 'email' => $order->customer_email],
            'country_code' => $country,
            'description' => 'Order '.$order->order_number,
            'merchant_url' => [
                'cancel' => $this->urls->publicUrl($store, '/checkout?payment=cancelled'),
                'failure' => $this->urls->publicUrl($store, '/checkout?payment=failed'),
                'success' => $this->successUrl($store, $order),
            ],
            'shipping_address' => [
                'country_code' => $country, 'first_name' => $name[0] ?? $order->customer_name, 'last_name' => $name[1] ?? '-',
                'line1' => data_get($shipping, 'line1', ''), 'line2' => data_get($shipping, 'line2', ''),
                'region' => data_get($shipping, 'state', ''), 'postal_code' => data_get($shipping, 'postal_code', ''),
                'city' => data_get($shipping, 'city', ''), 'phone_number' => $order->customer_phone,
            ],
            'locale' => 'en_US', 'platform' => 'Sellchaze', 'is_mobile' => false,
        ])->throw()->json();

        return ['provider_reference' => (string) data_get($response, 'order_id'), 'redirect_url' => (string) data_get($response, 'checkout_url')];
    }

    public function authoriseTamara(StorePaymentGateway $setting, string $orderId): array
    {
        $base = $setting->test_mode ? 'https://api-sandbox.tamara.co' : 'https://api.tamara.co';
        return Http::withToken((string) data_get($setting->credentials, 'api_token'))->acceptJson()
            ->post($base.'/orders/'.$orderId.'/authorise')->throw()->json();
    }

    /** @return array{provider_reference:string,redirect_url:string} */
    private function paymob(Store $store, StoreOrder $order, StorePaymentGateway $setting, StorePaymentTransaction $transaction): array
    {
        $secret = trim((string) (data_get($setting->credentials, 'secret_key') ?: data_get($setting->credentials, 'api_key')));
        $public = trim((string) data_get($setting->credentials, 'public_key'));
        $integrationId = trim((string) data_get($setting->credentials, 'integration_id'));
        $this->requireCredential($secret, 'Paymob secret key');
        $this->requireCredential($public, 'Paymob public key');
        $this->requireCredential($integrationId, 'Paymob integration ID');
        $base = $this->paymobBase((string) data_get($setting->credentials, 'region'));
        $name = preg_split('/\s+/', trim($order->customer_name), 2) ?: [];
        $shipping = $order->shipping_address ?? [];
        $amount = $this->minorAmount($order->grand_total, $order->currency);
        $response = Http::withHeaders(['Authorization' => 'Token '.$secret])->acceptJson()
            ->post($base.'/v1/intention/', [
                'amount' => $amount,
                'currency' => strtoupper($order->currency),
                'payment_methods' => [(int) $integrationId],
                'items' => [[
                    'name' => 'Order '.$order->order_number, 'amount' => $amount,
                    'description' => 'Sellchaze storefront order', 'quantity' => 1,
                ]],
                'billing_data' => [
                    'first_name' => $name[0] ?? $order->customer_name, 'last_name' => $name[1] ?? '-',
                    'email' => $order->customer_email, 'phone_number' => $order->customer_phone,
                    'street' => data_get($shipping, 'line1', 'NA'), 'building' => 'NA', 'floor' => 'NA', 'apartment' => 'NA',
                    'city' => data_get($shipping, 'city', 'NA'), 'country' => data_get($shipping, 'country', 'NA'),
                    'state' => data_get($shipping, 'state', 'NA'), 'postal_code' => data_get($shipping, 'postal_code', 'NA'),
                ],
                'extras' => ['transaction_id' => (string) $transaction->id, 'store_order_id' => (string) $order->id],
                'special_reference' => (string) $transaction->id,
                'expiration' => 3600,
                'notification_url' => $this->webhookUrl($store, 'paymob'),
                'redirection_url' => $this->successUrl($store, $order),
            ])->throw()->json();
        $clientSecret = (string) data_get($response, 'client_secret');
        $redirect = $base.'/unifiedcheckout/?publicKey='.rawurlencode($public).'&clientSecret='.rawurlencode($clientSecret);

        return ['provider_reference' => (string) data_get($response, 'intention_order_id'), 'redirect_url' => $redirect];
    }

    /** @return array{provider_reference:string,redirect_url:string} */
    private function paytabs(Store $store, StoreOrder $order, StorePaymentGateway $setting, StorePaymentTransaction $transaction): array
    {
        $credentials = $setting->credentials ?? [];
        $serverKey = trim((string) data_get($credentials, 'server_key'));
        $profileId = trim((string) data_get($credentials, 'profile_id'));
        $this->requireCredential($serverKey, 'PayTabs server key');
        $this->requireCredential($profileId, 'PayTabs profile ID');

        $region = strtolower(trim((string) data_get($credentials, 'region', 'global')));
        $domain = match ($region) {
            'ksa', 'saudi', 'sa' => 'https://secure.paytabs.sa',
            'uae', 'ae' => 'https://secure.paytabs.com',
            'egypt', 'eg' => 'https://secure-egypt.paytabs.com',
            'oman', 'om' => 'https://secure-oman.paytabs.com',
            'jordan', 'jo' => 'https://secure-jordan.paytabs.com',
            'kuwait', 'kw' => 'https://secure-kuwait.paytabs.com',
            'iraq', 'iq' => 'https://secure-iraq.paytabs.com',
            'morocco', 'ma' => 'https://secure-morocco.paytabs.com',
            'qatar', 'qa' => 'https://secure-doha.paytabs.com',
            default => 'https://secure-global.paytabs.com',
        };

        $response = Http::withHeaders(['authorization' => $serverKey])
            ->post($domain.'/payment/request', [
                'profile_id' => (int) $profileId,
                'tran_type' => 'sale',
                'tran_class' => 'ecom',
                'cart_id' => (string) $transaction->id,
                'cart_description' => 'Order '.$order->order_number,
                'cart_currency' => strtoupper($order->currency),
                'cart_amount' => (float) $order->grand_total,
                'callback' => $this->webhookUrl($store, 'paytabs'),
                'return' => $this->successUrl($store, $order),
            ])->throw()->json();

        $redirectUrl = (string) data_get($response, 'redirect_url');
        abort_if($redirectUrl === '', 422, 'PayTabs did not return a payment page URL.');

        return [
            'provider_reference' => (string) data_get($response, 'tran_ref'),
            'redirect_url' => $redirectUrl,
        ];
    }

    /** @return array{provider_reference:string,redirect_url:string} */
    private function hyperpay(Store $store, StoreOrder $order, StorePaymentGateway $setting, StorePaymentTransaction $transaction): array
    {
        $credentials = $setting->credentials ?? [];
        $entityId = trim((string) data_get($credentials, 'entity_id'));
        $accessToken = trim((string) data_get($credentials, 'access_token'));
        $this->requireCredential($entityId, 'HyperPay entity ID');
        $this->requireCredential($accessToken, 'HyperPay access token');

        $response = Http::asForm()->withToken($accessToken)
            ->post($this->hyperpayBase($setting).'/v1/checkouts', [
                'entityId' => $entityId,
                'amount' => number_format((float) $order->grand_total, 2, '.', ''),
                'currency' => strtoupper($order->currency),
                'paymentType' => 'DB',
                'merchantTransactionId' => (string) $transaction->id,
                'customer.email' => $order->customer_email,
                'customer.givenName' => $order->customer_name,
                'customer.surname' => '-',
            ])->throw()->json();

        $checkoutId = (string) data_get($response, 'id');
        abort_if($checkoutId === '', 422, 'HyperPay did not return a checkout ID.');
        $returnUrl = url('/api/v1/payment-returns/hyperpay/'.$transaction->id);
        $brands = trim((string) data_get($credentials, 'brands', 'VISA MASTER MADA'));
        $query = http_build_query([
            'checkout_id' => $checkoutId,
            'return_url' => $returnUrl,
            'mode' => $setting->test_mode ? 'test' : 'live',
            'brands' => $brands,
            'order' => $order->order_number,
        ]);

        return [
            'provider_reference' => $checkoutId,
            'redirect_url' => $this->urls->publicUrl($store, '/payment/hyperpay?'.$query),
        ];
    }

    public function verifyHyperpayResult(StorePaymentTransaction $transaction, StorePaymentGateway $setting, string $resourcePath): array
    {
        $expectedPath = '/v1/checkouts/'.$transaction->provider_reference.'/payment';
        abort_unless($resourcePath === $expectedPath, 422, 'Invalid HyperPay resource path.');
        $entityId = trim((string) data_get($setting->credentials, 'entity_id'));
        $accessToken = trim((string) data_get($setting->credentials, 'access_token'));
        $this->requireCredential($entityId, 'HyperPay entity ID');
        $this->requireCredential($accessToken, 'HyperPay access token');

        $response = Http::withToken($accessToken)
            ->get($this->hyperpayBase($setting).$resourcePath, ['entityId' => $entityId])
            ->throw()->json();
        abort_unless((string) data_get($response, 'merchantTransactionId') === (string) $transaction->id, 422, 'HyperPay transaction mismatch.');

        return $response;
    }

    public function paymentRedirectUrl(StorePaymentTransaction $transaction, string $status): string
    {
        $transaction->loadMissing(['store', 'order']);
        if ($status === 'success') {
            return $this->urls->publicUrl($transaction->store, '/order/success?number='.rawurlencode($transaction->order->order_number).'&payment=success');
        }
        if ($status === 'pending') {
            return $this->urls->publicUrl($transaction->store, '/order/success?number='.rawurlencode($transaction->order->order_number).'&payment=pending');
        }

        return $this->urls->publicUrl($transaction->store, '/checkout?payment=failed');
    }

    /** @return array{provider_reference:string,redirect_url:string} */
    private function fawry(Store $store, StoreOrder $order, StorePaymentGateway $setting, StorePaymentTransaction $transaction): array
    {
        $credentials = $setting->credentials ?? [];
        $this->requireCredential(trim((string) data_get($credentials, 'merchant_code')), 'Fawry merchant code');
        $this->requireCredential(trim((string) data_get($credentials, 'security_key')), 'Fawry security key');
        $query = http_build_query([
            'session_url' => url('/api/v1/payment-sessions/fawry/'.$transaction->id),
            'mode' => $setting->test_mode ? 'test' : 'live',
            'order' => $order->order_number,
        ]);

        return [
            'provider_reference' => (string) $transaction->id,
            'redirect_url' => $this->urls->publicUrl($store, '/payment/fawry?'.$query),
        ];
    }

    public function fawrySession(StorePaymentTransaction $transaction, StorePaymentGateway $setting): array
    {
        $transaction->loadMissing(['order.items', 'store']);
        $order = $transaction->order;
        $merchantCode = trim((string) data_get($setting->credentials, 'merchant_code'));
        $securityKey = trim((string) data_get($setting->credentials, 'security_key'));
        $this->requireCredential($merchantCode, 'Fawry merchant code');
        $this->requireCredential($securityKey, 'Fawry security key');
        $returnUrl = url('/api/v1/payment-returns/fawry/'.$transaction->id);
        $items = $order->items->map(fn ($item) => [
            'itemId' => (string) $item->id,
            'description' => (string) $item->name,
            'price' => (float) number_format((float) $item->unit_price, 2, '.', ''),
            'quantity' => (float) $item->quantity,
        ])->sortBy('itemId')->values();
        $signatureItems = $items->map(fn (array $item) => $item['itemId'].$item['quantity'].number_format($item['price'], 2, '.', ''))->implode('');
        $signature = hash('sha256', $merchantCode.$transaction->id.''.$returnUrl.$signatureItems.$securityKey);

        return [
            'merchantCode' => $merchantCode,
            'merchantRefNum' => (string) $transaction->id,
            'customerMobile' => $order->customer_phone,
            'customerEmail' => $order->customer_email,
            'customerName' => $order->customer_name,
            'paymentExpiry' => (string) now()->addDay()->getTimestampMs(),
            'chargeItems' => $items->all(),
            'returnUrl' => $returnUrl,
            'orderWebHookUrl' => $this->webhookUrl($transaction->store, 'fawry'),
            'authCaptureModePayment' => false,
            'signature' => $signature,
        ];
    }

    private function hyperpayBase(StorePaymentGateway $setting): string
    {
        return $setting->test_mode ? 'https://eu-test.oppwa.com' : 'https://eu-prod.oppwa.com';
    }

    private function paymobBase(string $region): string
    {
        return match (strtolower(trim($region))) {
            'uae', 'ae' => 'https://uae.paymob.com',
            'ksa', 'saudi', 'sa' => 'https://ksa.paymob.com',
            'oman', 'om' => 'https://oman.paymob.com',
            default => 'https://accept.paymob.com',
        };
    }

    /** @return array{provider_reference:string,redirect_url:string} */
    private function tap(Store $store, StoreOrder $order, StorePaymentGateway $setting, StorePaymentTransaction $transaction): array
    {
        $secret = trim((string) data_get($setting->credentials, 'secret_key'));
        $this->requireCredential($secret, 'Tap secret key');
        $name = preg_split('/\s+/', trim($order->customer_name), 2) ?: [];
        $response = Http::withToken($secret)->acceptJson()->post('https://api.tap.company/v2/charges/', [
            'amount' => (float) $order->grand_total,
            'currency' => strtoupper($order->currency),
            'customer_initiated' => true,
            'threeDSecure' => true,
            'save_card' => false,
            'description' => 'Order '.$order->order_number,
            'metadata' => ['transaction_id' => (string) $transaction->id],
            'reference' => ['transaction' => (string) $transaction->id, 'order' => $order->order_number],
            'customer' => ['first_name' => $name[0] ?? $order->customer_name, 'last_name' => $name[1] ?? '', 'email' => $order->customer_email],
            'source' => ['id' => 'src_all'],
            'post' => ['url' => $this->webhookUrl($store, 'tap')],
            'redirect' => ['url' => $this->successUrl($store, $order)],
        ])->throw()->json();

        return ['provider_reference' => (string) data_get($response, 'id'), 'redirect_url' => (string) data_get($response, 'transaction.url')];
    }

    /** @return array{provider_reference:string,redirect_url:string} */
    private function fawaterak(Store $store, StoreOrder $order, StorePaymentGateway $setting, StorePaymentTransaction $transaction): array
    {
        $apiKey = trim((string) data_get($setting->credentials, 'api_key'));
        $this->requireCredential($apiKey, 'Fawaterak API key');
        $name = preg_split('/\s+/', trim($order->customer_name), 2) ?: [];
        $shipping = $order->shipping_address ?? [];
        $base = $setting->test_mode ? 'https://staging.fawaterk.com' : 'https://app.fawaterk.com';
        $response = Http::withToken($apiKey)->acceptJson()->post($base.'/api/v2/createInvoiceLink', [
            'cartTotal' => (string) $order->grand_total,
            'currency' => strtoupper($order->currency),
            'customer' => [
                'first_name' => $name[0] ?? $order->customer_name, 'last_name' => $name[1] ?? '-',
                'email' => $order->customer_email, 'phone' => $order->customer_phone,
                'address' => data_get($shipping, 'line1', ''),
            ],
            'redirectionUrls' => [
                'successUrl' => $this->successUrl($store, $order),
                'failUrl' => $this->urls->publicUrl($store, '/checkout?payment=failed'),
                'pendingUrl' => $this->urls->publicUrl($store, '/order/success?number='.rawurlencode($order->order_number).'&payment=pending'),
                'webhookUrl' => $this->webhookUrl($store, 'fawaterak').'_json',
            ],
            'cartItems' => $order->items->map(fn ($item) => ['name' => $item->name, 'price' => (string) $item->unit_price, 'quantity' => (string) $item->quantity])->values()->all(),
            'payLoad' => ['transaction_id' => (string) $transaction->id, 'order_id' => (string) $order->id],
            'sendEmail' => true,
            'sendSMS' => false,
        ])->throw()->json();
        abort_unless(data_get($response, 'status') === 'success', 422, 'Fawaterak did not create the invoice.');

        return ['provider_reference' => (string) data_get($response, 'data.invoiceKey'), 'redirect_url' => (string) data_get($response, 'data.url')];
    }

    public function retrieveTabby(StorePaymentGateway $setting, string $paymentId, string $currency): array
    {
        $base = strtoupper($currency) === 'SAR' ? 'https://api.tabby.sa' : 'https://api.tabby.ai';
        return Http::withToken((string) data_get($setting->credentials, 'secret_key'))->acceptJson()
            ->get($base.'/api/v2/payments/'.$paymentId)->throw()->json();
    }

    public function captureTabby(StorePaymentGateway $setting, StorePaymentTransaction $transaction): array
    {
        $base = strtoupper($transaction->currency) === 'SAR' ? 'https://api.tabby.sa' : 'https://api.tabby.ai';
        return Http::withToken((string) data_get($setting->credentials, 'secret_key'))->acceptJson()
            ->post($base.'/api/v2/payments/'.$transaction->provider_reference.'/captures', [
                'amount' => (string) $transaction->amount,
                'reference_id' => $transaction->idempotency_key.'-capture',
            ])->throw()->json();
    }

    public function retrieveTap(StorePaymentGateway $setting, string $chargeId): array
    {
        return Http::withToken((string) data_get($setting->credentials, 'secret_key'))->acceptJson()
            ->get('https://api.tap.company/v2/charges/'.$chargeId)->throw()->json();
    }

    public function paypalToken(StorePaymentGateway $setting): string
    {
        $clientId = trim((string) data_get($setting->credentials, 'client_id'));
        $clientSecret = trim((string) data_get($setting->credentials, 'client_secret'));
        $this->requireCredential($clientId, 'PayPal client ID');
        $this->requireCredential($clientSecret, 'PayPal client secret');
        return (string) Http::asForm()->withBasicAuth($clientId, $clientSecret)
            ->post($this->paypalBase($setting).'/v1/oauth2/token', ['grant_type' => 'client_credentials'])
            ->throw()->json('access_token');
    }

    public function capturePayPal(StorePaymentGateway $setting, string $providerReference, string $idempotencyKey): array
    {
        return Http::withToken($this->paypalToken($setting))->acceptJson()
            ->withHeaders(['PayPal-Request-Id' => $idempotencyKey, 'Prefer' => 'return=representation'])
            ->withBody('{}', 'application/json')
            ->post($this->paypalBase($setting).'/v2/checkout/orders/'.$providerReference.'/capture')
            ->throw()->json();
    }

    public function paypalRequest(StorePaymentGateway $setting): PendingRequest
    {
        return Http::withToken($this->paypalToken($setting))->acceptJson();
    }

    public function paypalBase(StorePaymentGateway $setting): string
    {
        return $setting->test_mode ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    private function successUrl(Store $store, StoreOrder $order): string
    {
        return $this->urls->publicUrl($store, '/order/success?number='.rawurlencode($order->order_number).'&payment=success');
    }

    private function webhookUrl(Store $store, string $gateway): string
    {
        return url('/api/v1/payment-webhooks/'.$store->id.'/'.$gateway);
    }

    private function minorAmount(string $amount, string $currency): int
    {
        $zeroDecimal = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
        return in_array(strtoupper($currency), $zeroDecimal, true) ? (int) round((float) $amount) : (int) round(((float) $amount) * 100);
    }

    private function requireCredential(string $value, string $label): void
    {
        if ($value === '') throw ValidationException::withMessages(['payment_method' => $label.' is missing in store payment settings.']);
    }

    /** @return array{transaction_id:int,status:string,redirect_url:?string} */
    private function result(StorePaymentTransaction $transaction): array
    {
        return ['transaction_id' => $transaction->id, 'status' => $transaction->status, 'redirect_url' => $transaction->redirect_url];
    }
}
