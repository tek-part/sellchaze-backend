<?php

namespace App\Services\Commerce;

use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class RegionalHostedPayments
{
    public function start(StoreOrder $order, StorePaymentGateway $setting, StorePaymentTransaction $transaction, string $return, string $notify): array
    {
        $creds = $setting->credentials;
        $reference = $transaction->idempotency_key;

        return match ($setting->gateway) {
            'xpay' => $this->request()->withHeaders(['x-api-key' => $creds['api_key']])->post($this->xpayBase($setting).'/api/v1/payments/pay/variable-amount', [
                'community_id' => $creds['community_id'], 'variable_amount_id' => (int) $creds['variable_amount_id'],
                'amount' => (float) $transaction->amount, 'original_amount' => (float) $transaction->amount, 'currency' => $transaction->currency,
                'pay_using' => 'card', 'language' => 'ar', 'membership_id' => $reference,
                'billing_data' => ['name' => $order->customer_name, 'email' => $order->customer_email, 'phone_number' => $order->customer_phone],
            ])->throw()->json('data') ?? [],
            'myfatoorah' => $this->request()->withToken($creds['api_key'])->post($this->myfatoorahBase($setting).'/v2/SendPayment', [
                'CustomerName' => $order->customer_name, 'NotificationOption' => 'LNK', 'CustomerEmail' => $order->customer_email,
                'InvoiceValue' => (float) $transaction->amount, 'DisplayCurrencyIso' => $transaction->currency,
                'CustomerReference' => $reference, 'CallBackUrl' => $return, 'ErrorUrl' => $return, 'WebhookUrl' => $notify, 'Language' => 'AR',
            ])->throw()->json('Data') ?? [],
            'easykash' => $this->request()->withHeaders(['Authorization' => $creds['api_key']])->post('https://back.easykash.net/api/directpayv1/pay', array_filter([
                'amount' => (float) $transaction->amount, 'currency' => 'EGP', 'name' => $order->customer_name,
                'email' => $order->customer_email, 'mobile' => $order->customer_phone, 'customerReference' => $reference,
                'redirectUrl' => $return, 'paymentOptions' => $this->easykashOptions((string) ($creds['payment_options'] ?? '')),
            ], fn ($value) => $value !== null))->throw()->json(),
            'upayments' => $this->upayments($setting, 'POST', 'charge', [
                'order' => ['id' => $reference, 'reference' => $reference, 'description' => 'Order '.$order->order_number,
                    'amount' => (float) $transaction->amount, 'currency' => $transaction->currency],
                'language' => 'ar', 'reference' => ['id' => (string) $transaction->id],
                'customer' => ['name' => $order->customer_name, 'email' => $order->customer_email, 'mobile' => $order->customer_phone],
                'returnUrl' => $return, 'cancelUrl' => $return, 'notificationUrl' => $notify,
            ]),
        };
    }

    /** Returns normalized identity and money for the shared verifier. */
    public function verify(StorePaymentTransaction $transaction, StorePaymentGateway $setting): array
    {
        $reference = $transaction->idempotency_key;
        if ($setting->gateway === 'xpay') {
            $result = $this->request()->withHeaders(['x-api-key' => $setting->credentials['api_key']])->get($this->xpayBase($setting).'/api/v1/communities/'
                .rawurlencode($setting->credentials['community_id']).'/transactions/'.rawurlencode((string) $transaction->provider_reference).'/')->throw()->json('data') ?? [];

            return [$result['uuid'] ?? '', $result['member_id'] ?? '', $result['total_amount'] ?? null, $result['total_amount_currency'] ?? '', strtolower((string) ($result['status'] ?? ''))];
        }
        if ($setting->gateway === 'myfatoorah') {
            $result = $this->request()->withToken($setting->credentials['api_key'])->post($this->myfatoorahBase($setting).'/v2/GetPaymentStatus',
                ['Key' => (string) $transaction->provider_reference, 'KeyType' => 'InvoiceId'])->throw()->json('Data') ?? [];

            // InvoiceValue is denominated in the configured country's base currency.
            return [$result['InvoiceId'] ?? '', $result['CustomerReference'] ?? '', $result['InvoiceValue'] ?? null,
                $this->myfatoorahResponseCurrency($result, $transaction), strtolower((string) ($result['InvoiceStatus'] ?? ''))];
        }
        if ($setting->gateway === 'easykash') {
            $result = $this->request()->withHeaders(['Authorization' => $setting->credentials['api_key']])->post('https://back.easykash.net/api/cash-api/inquire',
                ['customerReference' => $reference])->throw()->json();

            // Inquiry is keyed by our unique reference. This API returns EGP paid amounts;
            // foreign-currency automatic conversion is intentionally not offered.
            return [$transaction->provider_reference, $reference, $result['Amount'] ?? null, 'EGP', strtolower((string) ($result['status'] ?? ''))];
        }
        $result = $this->upayments($setting, 'GET', 'get-payment-status?session_id='.rawurlencode((string) $transaction->provider_reference));
        $payment = data_get($result, 'data.transaction', []);

        return [$payment['session_id'] ?? '', (string) ($payment['reference'] ?? '') === (string) $transaction->id ? $reference : '',
            strtoupper((string) ($payment['currency_type'] ?? '')) === 'KWD' ? ($payment['total_price'] ?? null) : ($payment['total_paid_non_kwd'] ?? null),
            $payment['currency_type'] ?? '', strtolower((string) ($payment['result'] ?? ''))];
    }

    public function validate(StorePaymentGateway $setting, string $currency): void
    {
        if ($setting->gateway === 'xpay') {
            abort_unless(ctype_digit((string) ($setting->credentials['variable_amount_id'] ?? '')) && (int) $setting->credentials['variable_amount_id'] > 0, 422, 'Enter the XPay API Payment template ID.');
            abort_unless(in_array($currency, ['EGP', 'USD', 'EUR', 'SAR', 'AED', 'GBP'], true), 422, 'This currency is not supported by XPay.');
        }
        if ($setting->gateway === 'myfatoorah') {
            abort_unless($currency === $this->myfatoorahCurrency($setting), 422, 'MyFatoorah requires the store currency to match the merchant account base currency.');
        }
        if ($setting->gateway === 'easykash') {
            abort_unless($currency === 'EGP', 422, 'EasyKash inquiry supports this connection in EGP only.');
            abort_if($setting->test_mode, 422, 'EasyKash does not provide a sandbox through this API.');
            $this->easykashOptions((string) ($setting->credentials['payment_options'] ?? ''));
        }
    }

    private function myfatoorahBase(StorePaymentGateway $setting): string
    {
        if ($setting->test_mode) {
            return 'https://apitest.myfatoorah.com';
        }

        return match (strtoupper((string) ($setting->credentials['country'] ?? ''))) {
            'KW', 'BH', 'JO', 'OM' => 'https://api.myfatoorah.com', 'AE' => 'https://api-ae.myfatoorah.com',
            'SA' => 'https://api-sa.myfatoorah.com', 'QA' => 'https://api-qa.myfatoorah.com', 'EG' => 'https://api-eg.myfatoorah.com',
            default => abort(422, 'Choose the MyFatoorah account country.'),
        };
    }

    private function xpayBase(StorePaymentGateway $setting): string
    {
        return $setting->test_mode ? 'https://staging.xpay.app' : 'https://community.xpay.app';
    }

    public function myfatoorahCurrency(StorePaymentGateway $setting): string
    {
        if ($setting->test_mode) {
            return 'KWD';
        }

        return ['KW' => 'KWD', 'BH' => 'BHD', 'JO' => 'JOD', 'OM' => 'OMR', 'AE' => 'AED', 'SA' => 'SAR', 'QA' => 'QAR', 'EG' => 'EGP'][strtoupper((string) ($setting->credentials['country'] ?? ''))] ?? '';
    }

    private function myfatoorahResponseCurrency(array $result, StorePaymentTransaction $transaction): string
    {
        $display = trim((string) ($result['InvoiceDisplayValue'] ?? ''));
        abort_unless(preg_match('/^([0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]+)?|[0-9]+(?:\.[0-9]+)?)\s+([A-Za-z]+)$/', $display, $parts) === 1, 422, 'Missing MyFatoorah invoice currency.');
        abort_unless(bccomp(str_replace(',', '', $parts[1]), (string) $transaction->amount, 3) === 0, 422, 'MyFatoorah displayed amount mismatch.');
        $label = strtoupper($parts[2]);
        $currency = ['KD' => 'KWD', 'BD' => 'BHD', 'JD' => 'JOD', 'RO' => 'OMR', 'SR' => 'SAR', 'QR' => 'QAR', 'LE' => 'EGP'][$label] ?? $label;

        return $currency;
    }

    private function easykashOptions(string $input): ?array
    {
        if (trim($input) === '') {
            return null;
        }
        $parts = preg_split('/[\s,]+/', trim($input)) ?: [];
        foreach ($parts as $part) {
            abort_unless(ctype_digit($part) && in_array((int) $part, [1, 2, 3, 4, 5, 6, 8, 9, 10, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 31, 32, 33, 34, 35], true), 422, 'Use valid comma-separated EasyKash payment option IDs.');
        }

        return array_values(array_unique(array_map('intval', $parts)));
    }

    private function upayments(StorePaymentGateway $setting, string $method, string $path, ?array $payload = null): array
    {
        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signaturePath = parse_url($path, PHP_URL_PATH) ?: $path;
        $signature = base64_encode(hash_hmac('sha256', $timestamp.$method.$signaturePath.$body, $setting->credentials['api_secret'], true));
        $client = $this->request()->withToken($setting->credentials['api_key'])->withHeaders(['X-Timestamp' => $timestamp, 'X-Signature' => $signature]);
        $base = $setting->test_mode ? 'https://sandboxapi.upayments.com/api/v1/' : 'https://uapi.upayments.com/api/v1/';

        return ($payload === null ? $client->get($base.$path) : $client->withBody($body, 'application/json')->post($base.$path))->throw()->json();
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->asJson()->connectTimeout(10)->timeout(25)->withoutRedirecting();
    }
}
