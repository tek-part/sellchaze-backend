<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** Hosted checkouts: no card details pass through Sellchaze. */
class HostedPaymentGateways
{
    public const PROVIDERS = ['kashier', 'moyasar', 'paylink', 'ziina', 'myfatoorah', 'easykash', 'upayments', 'xpay'];

    public function start(Store $store, StoreOrder $order, StorePaymentGateway $setting, StorePaymentTransaction $transaction): array
    {
        $credentials = $setting->credentials ?? [];
        foreach (PaymentGatewayCatalog::required($setting->gateway) as $key) {
            if (trim((string) ($credentials[$key] ?? '')) === '') {
                throw ValidationException::withMessages(['payment_method' => 'Complete the payment connection settings before accepting payments.']);
            }
        }
        app(RegionalHostedPayments::class)->validate($setting, $transaction->currency);
        if ($setting->gateway === 'moyasar') {
            $this->moyasar($setting);
        }
        // An uncertain invoice creation must not be retried as a second invoice.
        // Ziina supports the same operation_id across retries.
        if ($setting->gateway !== 'ziina' && data_get($transaction->metadata, 'hosted_request_sent')) {
            throw ValidationException::withMessages(['payment_method' => 'The provider request may already exist. Contact the store to reconcile it before starting another payment.']);
        }
        $transaction->update(['metadata' => array_merge($transaction->metadata ?? [], ['hosted_request_sent' => true,
            'environment' => (bool) $setting->test_mode, 'connection_fingerprint' => $this->fingerprint($setting)])]);
        $return = route('payments.hosted.return', ['transaction' => $transaction->id, 'payment_key' => $this->returnKey($transaction)]);
        $notify = route('payments.hosted.notify', ['transaction' => $transaction->id, 'payment_key' => $this->returnKey($transaction)]);
        $reference = (string) $transaction->idempotency_key;
        $result = match ($setting->gateway) {
            'myfatoorah', 'easykash', 'upayments', 'xpay' => app(RegionalHostedPayments::class)->start($order, $setting, $transaction, $return, $notify),
            'kashier' => $this->request()->withHeaders(['Authorization' => $credentials['api_secret'], 'api-key' => $credentials['api_key']])
                ->post($this->kashierBase($setting).'/v3/payment/sessions', [
                    'expireAt' => now()->addDay()->toIso8601String(), 'maxFailureAttempts' => 3,
                    'amount' => (string) $transaction->amount, 'currency' => $transaction->currency, 'order' => $reference,
                    'merchantId' => $credentials['merchant_id'], 'merchantRedirect' => $return, 'serverWebhook' => $notify,
                    'display' => 'ar', 'type' => 'one-time', 'customer' => ['email' => $order->customer_email, 'reference' => $reference],
                ])->throw()->json(),
            'moyasar' => $this->moyasar($setting)->post('https://api.moyasar.com/v1/invoices', [
                'amount' => $this->minor((string) $transaction->amount, $transaction->currency), 'currency' => $transaction->currency,
                'description' => 'Order '.$order->order_number, 'success_url' => $return, 'back_url' => $return,
                'callback_url' => $notify, 'expired_at' => now()->addDay()->toIso8601String(), 'metadata' => ['transaction' => $reference],
            ])->throw()->json(),
            'paylink' => $this->paylink($setting)->post($this->paylinkBase($setting).'/api/addInvoice', [
                'orderNumber' => $reference, 'amount' => (float) $transaction->amount, 'currency' => $transaction->currency,
                'callBackUrl' => $return, 'cancelUrl' => $return, 'clientName' => $order->customer_name,
                'clientEmail' => $order->customer_email, 'clientMobile' => $order->customer_phone,
                'products' => [['title' => 'Order '.$order->order_number, 'price' => (float) $transaction->amount, 'qty' => 1]],
            ])->throw()->json(),
            'ziina' => $this->request()->withToken($credentials['api_key'])->post('https://api-v2.ziina.com/api/payment_intent', [
                'amount' => $this->minor((string) $transaction->amount, $transaction->currency), 'currency_code' => $transaction->currency,
                'operation_id' => $reference, 'message' => 'Order '.$order->order_number, 'test' => (bool) $setting->test_mode,
                'success_url' => $return, 'cancel_url' => $return, 'failure_url' => $return, 'allow_tips' => false,
            ])->throw()->json(),
        };
        [$id, $link] = match ($setting->gateway) {
            'xpay' => [$result['transaction_uuid'] ?? '', $result['iframe_url'] ?? ''],
            'myfatoorah' => [$result['InvoiceId'] ?? '', $result['InvoiceURL'] ?? ''],
            'easykash' => [basename(parse_url((string) ($result['redirectUrl'] ?? ''), PHP_URL_PATH) ?: ''), $result['redirectUrl'] ?? ''],
            'upayments' => [$this->upaymentsSession((string) data_get($result, 'data.link', '')), data_get($result, 'data.link', '')],
            'kashier' => [$result['_id'] ?? $result['sessionId'] ?? '', $result['sessionUrl'] ?? ''],
            'moyasar' => [$result['id'] ?? '', $result['url'] ?? ''],
            'paylink' => [$result['transactionNo'] ?? '', $result['url'] ?? ''],
            'ziina' => [$result['id'] ?? '', $result['redirect_url'] ?? ''],
        };
        if ($setting->gateway === 'kashier' && $id === '' && is_string($link)) {
            $id = basename(parse_url($link, PHP_URL_PATH) ?: '');
        }
        abort_unless(is_scalar($id) && (string) $id !== '' && is_string($link) && $this->safeLink($link, $setting->gateway), 422, 'The payment provider returned an invalid checkout session.');

        return ['provider_reference' => (string) $id, 'redirect_url' => $link];
    }

    /** Retrieve only the stored session ID, never an ID, URL or amount from the browser/webhook. */
    public function verify(StorePaymentTransaction $transaction, StorePaymentGateway $setting): string
    {
        abort_unless(in_array($transaction->gateway, self::PROVIDERS, true) && $setting->gateway === $transaction->gateway
            && (int) $setting->store_id === (int) $transaction->store_id, 422, 'Payment scope mismatch.');
        abort_unless(hash_equals((string) data_get($transaction->metadata, 'connection_fingerprint'), $this->fingerprint($setting))
            && data_get($transaction->metadata, 'environment') === (bool) $setting->test_mode, 422, 'The payment connection changed; reconcile the original provider account.');
        $id = rawurlencode((string) $transaction->provider_reference);
        abort_if($id === '', 422, 'No provider session to verify.');
        $result = match ($setting->gateway) {
            'myfatoorah', 'easykash', 'upayments', 'xpay' => [],
            'kashier' => $this->request()->withHeaders(['Authorization' => $setting->credentials['api_secret']])
                ->get($this->kashierBase($setting).'/v3/payment/sessions/'.$id.'/payment')->throw()->json('data'),
            'moyasar' => $this->moyasar($setting)->get('https://api.moyasar.com/v1/invoices/'.$id)->throw()->json(),
            'paylink' => $this->paylink($setting)->get($this->paylinkBase($setting).'/api/getInvoice/'.$id)->throw()->json(),
            'ziina' => $this->request()->withToken($setting->credentials['api_key'])->get('https://api-v2.ziina.com/api/payment_intent/'.$id)->throw()->json(),
        };
        abort_unless(is_array($result), 422, 'Invalid provider response.');
        [$remoteId, $reference, $amount, $currency, $state] = match ($setting->gateway) {
            'myfatoorah', 'easykash', 'upayments', 'xpay' => app(RegionalHostedPayments::class)->verify($transaction, $setting),
            'kashier' => [$result['sessionId'] ?? '', $result['merchantOrderId'] ?? '', $result['amount'] ?? null, $result['currency'] ?? '', $result['status'] ?? ''],
            'moyasar' => [$result['id'] ?? '', data_get($result, 'metadata.transaction', ''), isset($result['amount']) ? $this->major($result['amount'], $transaction->currency) : null, $result['currency'] ?? '', $result['status'] ?? ''],
            'paylink' => [$result['transactionNo'] ?? '', data_get($result, 'gatewayOrderRequest.orderNumber', ''), data_get($result, 'gatewayOrderRequest.amount'), data_get($result, 'gatewayOrderRequest.currency', ''), $result['orderStatus'] ?? ''],
            'ziina' => [$result['id'] ?? '', $result['operation_id'] ?? '', isset($result['amount']) ? $this->major($result['amount'], $transaction->currency) : null, $result['currency_code'] ?? '', $result['status'] ?? ''],
        };
        abort_unless((string) $remoteId === (string) $transaction->provider_reference
            && (string) $reference === (string) $transaction->idempotency_key
            && is_numeric($amount) && bccomp((string) $amount, (string) $transaction->amount, 3) === 0
            && strtoupper((string) $currency) === strtoupper($transaction->currency), 422, 'Provider payment identity, amount or currency mismatch.');
        $state = strtolower((string) $state);

        return match ($setting->gateway) {
            'kashier' => $state === 'success' ? 'paid' : (in_array($state, ['failed', 'expired', 'abandoned'], true) ? 'failed' : 'pending'),
            'moyasar', 'paylink', 'myfatoorah', 'easykash' => $state === 'paid' ? 'paid' : (in_array($state, ['failed', 'expired', 'canceled', 'cancelled', 'voided'], true) ? 'failed' : 'pending'),
            'xpay' => $state === 'successful' ? 'paid' : (in_array($state, ['failed', 'cancelled', 'expired'], true) ? 'failed' : 'pending'),
            'upayments' => $state === 'captured' ? 'paid' : (in_array($state, ['failed', 'canceled', 'cancelled', 'not captured'], true) ? 'failed' : 'pending'),
            'ziina' => $state === 'completed' ? 'paid' : (in_array($state, ['failed', 'canceled'], true) ? 'failed' : 'pending'),
        };
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->asJson()->connectTimeout(10)->timeout(25)->withoutRedirecting();
    }

    public function returnKey(StorePaymentTransaction $transaction): string
    {
        return hash_hmac('sha256', $transaction->id.':'.$transaction->idempotency_key, (string) config('app.key'));
    }

    private function moyasar(StorePaymentGateway $setting): PendingRequest
    {
        $key = (string) ($setting->credentials['secret_key'] ?? '');
        abort_unless(str_starts_with($key, $setting->test_mode ? 'sk_test_' : 'sk_live_'), 422, 'Use a Moyasar key matching the selected environment.');

        return $this->request()->withBasicAuth($key, '');
    }

    private function paylink(StorePaymentGateway $setting): PendingRequest
    {
        $token = $this->request()->post($this->paylinkBase($setting).'/api/auth', [
            'apiId' => $setting->credentials['api_key'], 'secretKey' => $setting->credentials['api_secret'], 'persistToken' => false,
        ])->throw()->json('id_token');
        abort_unless(is_string($token) && $token !== '', 422, 'Paylink authentication failed.');

        return $this->request()->withToken($token);
    }

    private function paylinkBase(StorePaymentGateway $setting): string
    {
        return $setting->test_mode ? 'https://restpilot.paylink.sa' : 'https://restapi.paylink.sa';
    }

    private function kashierBase(StorePaymentGateway $setting): string
    {
        return $setting->test_mode ? 'https://test-api.kashier.io' : 'https://api.kashier.io';
    }

    private function fingerprint(StorePaymentGateway $setting): string
    {
        $credentials = $setting->credentials ?? [];
        ksort($credentials);

        return hash_hmac('sha256', json_encode($credentials, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function decimals(string $currency): int
    {
        return PaymentAmounts::decimals($currency);
    }

    private function minor(string $amount, string $currency): int
    {
        return PaymentAmounts::minor($amount, $currency);
    }

    private function major(mixed $amount, string $currency): string
    {
        abort_unless(is_numeric($amount) && (string) (int) $amount === (string) $amount, 422, 'Invalid payment amount.');

        return bcdiv((string) $amount, (string) (10 ** $this->decimals($currency)), 3);
    }

    private function upaymentsSession(string $url): string
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

        return is_string($query['session_id'] ?? null) ? $query['session_id'] : '';
    }

    private function safeLink(string $url, string $gateway): bool
    {
        $parts = parse_url($url);
        $domain = ['kashier' => 'kashier.io', 'moyasar' => 'moyasar.com', 'paylink' => 'paylink.sa', 'ziina' => 'ziina.com', 'myfatoorah' => 'myfatoorah.com', 'easykash' => 'easykash.net', 'upayments' => 'upayments.com', 'xpay' => 'xpay.app'][$gateway];
        $host = strtolower($parts['host'] ?? '');

        return ($parts['scheme'] ?? '') === 'https' && ! isset($parts['user']) && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443) && ($host === $domain || str_ends_with($host, '.'.$domain));
    }
}
