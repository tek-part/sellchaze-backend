<?php

namespace App\Services;

use App\Models\PlatformWalletPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Fawaterak API v2. Only server-verified paid invoices can create USD wallet credit. */
class PlatformWalletFunding
{
    public const PRESETS = [5000, 10000, 20000, 50000, 100000, 200000, 600000];

    public function configured(): bool
    {
        return (bool) config('platform_wallet.fawaterak.enabled')
            && trim((string) config('platform_wallet.fawaterak.api_key')) !== ''
            && trim((string) config('platform_wallet.fawaterak.vendor_key')) !== '';
    }

    private function base(bool $test): string
    {
        return $test ? 'https://staging.fawaterk.com' : 'https://app.fawaterk.com';
    }

    private function publicUrl(string $url): string
    {
        $parts = parse_url($url);
        abort_unless(filter_var($url, FILTER_VALIDATE_URL) && $parts && ($parts['scheme'] ?? '') === 'https'
            && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']), 503, 'Platform payment URLs require public HTTPS configuration.');

        return rtrim($url, '/');
    }

    public function start(User $user, int $amount, string $requestKey): PlatformWalletPayment
    {
        abort_unless(in_array($amount, self::PRESETS, true), 422);
        $existing = PlatformWalletPayment::where('user_id', $user->id)->where('request_key', $requestKey)->first();
        if ($existing) {
            abort_unless($existing->amount_milli === $amount, 409, 'Payment request amount changed.');

            return $existing;
        }
        abort_unless($this->configured(), 503, 'Wallet funding is not configured.');
        $frontend = $this->publicUrl((string) config('sellchase.frontend_url'));
        $api = $this->publicUrl((string) config('platform_wallet.fawaterak.webhook_base_url'));
        $payment = DB::transaction(function () use ($user, $amount, $requestKey) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = PlatformWalletPayment::where('user_id', $user->id)->where('request_key', $requestKey)->first();
            if ($existing) {
                abort_unless($existing->amount_milli === $amount, 409);

                return $existing;
            }
            abort_if(PlatformWalletPayment::where('user_id', $user->id)->whereIn('status', ['creating', 'uncertain', 'pending'])->exists(), 409, 'Finish or reconcile the existing wallet payment first.');

            return PlatformWalletPayment::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'request_key' => $requestKey, 'amount_milli' => $amount, 'currency' => 'USD', 'status' => 'creating',
                'test_mode' => (bool) config('platform_wallet.fawaterak.test_mode'), 'credentials' => ['api_key' => (string) config('platform_wallet.fawaterak.api_key'), 'vendor_key' => (string) config('platform_wallet.fawaterak.vendor_key')]]);
        });
        if (! $payment->wasRecentlyCreated) {
            return $payment;
        }
        // No documented remote idempotency: a timeout must never resubmit an invoice.
        try {
            $price = bcdiv((string) $amount, '1000', 2);
            $return = $frontend.'/top-up?wallet_payment='.$payment->id;
            $response = Http::withToken($payment->credentials['api_key'])->acceptJson()->timeout(20)->connectTimeout(5)->withoutRedirecting()
                ->post($this->base($payment->test_mode).'/api/v2/createInvoiceLink', [
                    'cartTotal' => $price, 'currency' => 'USD', 'frequency' => 'once',
                    'customer' => ['first_name' => 'Sellchaze', 'last_name' => 'Merchant', 'email' => $user->email],
                    'cartItems' => [['name' => 'Sellchaze wallet credit', 'price' => $price, 'quantity' => 1]],
                    'payLoad' => ['wallet_payment_id' => $payment->id], 'sendEmail' => false, 'sendSMS' => false,
                    'redirectionUrls' => ['successUrl' => $return, 'failUrl' => $return, 'pendingUrl' => $return, 'webhookUrl' => $api.'/api/v1/platform-wallet/fawaterak_json'],
                ]);
            $body = $response->json();
            $url = (string) data_get($body, 'data.url');
            $parts = parse_url($url);
            $baseHost = parse_url($this->base($payment->test_mode), PHP_URL_HOST);
            abort_unless($response->successful() && data_get($body, 'status') === 'success' && $parts && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === $baseHost && ! isset($parts['user']) && ! isset($parts['pass'])
                && preg_match('/^[1-9][0-9]*$/', (string) data_get($body, 'data.invoiceId')) && preg_match('/^[A-Za-z0-9_-]{1,100}$/', (string) data_get($body, 'data.invoiceKey')), 502, 'The payment session needs reconciliation.');
            DB::transaction(function () use ($payment, $body, $url) {
                $current = PlatformWalletPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($current->invoice_id !== null) {
                    abort_unless((string) $current->invoice_id === (string) data_get($body, 'data.invoiceId') && $current->invoice_key === data_get($body, 'data.invoiceKey'), 409);
                }
                $current->update(['invoice_id' => data_get($body, 'data.invoiceId'), 'invoice_key' => data_get($body, 'data.invoiceKey'), 'redirect_url' => $url, 'status' => $current->status === 'paid' ? 'paid' : 'pending']);
            });
        } catch (\Throwable) {
            PlatformWalletPayment::whereKey($payment->id)->where('status', 'creating')->update(['status' => 'uncertain']);
            abort(502, 'Payment creation could not be confirmed. Do not pay again; this request is retained for reconciliation.');
        }

        return $payment->fresh();
    }

    public function refresh(PlatformWalletPayment $payment): PlatformWalletPayment
    {
        if ($payment->status === 'paid' || $payment->invoice_id === null) {
            return $payment;
        }

        return $this->verify($payment, (string) $payment->invoice_id, (string) $payment->invoice_key);
    }

    /** Signed callback identifiers plus an authenticated API read; callback amounts/status cannot mint credit. */
    public function callback(array $event): void
    {
        $payment = PlatformWalletPayment::where('invoice_id', $event['invoice_id'])->where('invoice_key', $event['invoice_key'])->first();
        // Lost creation responses recover only the attempted UUID; API read validates that same payload.
        if (! $payment && is_array($event['pay_load'] ?? null)) {
            $payment = PlatformWalletPayment::whereKey($event['pay_load']['wallet_payment_id'] ?? '')->first();
        }
        abort_unless($payment, 404);
        $message = 'InvoiceId='.$event['invoice_id'].'&InvoiceKey='.$event['invoice_key'].'&PaymentMethod='.$event['payment_method'];
        abort_unless(hash_equals(hash_hmac('sha256', $message, $payment->credentials['vendor_key']), $event['hashKey']), 401);
        if ($payment->invoice_id !== null) {
            abort_unless((string) $payment->invoice_id === (string) $event['invoice_id'] && $payment->invoice_key === $event['invoice_key'], 409);
        }
        if ($payment->status !== 'paid') {
            $this->verify($payment, (string) $event['invoice_id'], $event['invoice_key']);
        }
    }

    private function verify(PlatformWalletPayment $payment, string $invoiceId, string $invoiceKey): PlatformWalletPayment
    {
        $verifiedId = filter_var($invoiceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($verifiedId === false) {
            abort(409, 'Invalid provider invoice identifier.');
        }
        $response = Http::withToken($payment->credentials['api_key'])->acceptJson()->timeout(20)->connectTimeout(5)->withoutRedirecting()
            ->get($this->base($payment->test_mode).'/api/v2/getInvoiceData/'.$invoiceId);
        $body = $response->json();
        $data = data_get($body, 'data', []);
        $total = (string) data_get($data, 'total');
        abort_unless($response->successful() && data_get($body, 'status') === 'success' && (string) data_get($data, 'invoice_id') === $invoiceId && data_get($data, 'invoice_key') === $invoiceKey
            && data_get($data, 'currency') === 'USD' && preg_match('/^[0-9]+(?:\.[0-9]{1,3})?$/', $total) && bccomp($total, bcdiv((string) $payment->amount_milli, '1000', 3), 3) === 0
            && data_get($data, 'pay_load.wallet_payment_id') === $payment->id, 409, 'Provider invoice does not match the wallet payment.');

        return DB::transaction(function () use ($payment, $invoiceId, $verifiedId, $invoiceKey, $data) {
            $current = PlatformWalletPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($current->invoice_id !== null) {
                abort_unless((string) $current->invoice_id === $invoiceId && $current->invoice_key === $invoiceKey, 409);
            }
            $current->invoice_id = $verifiedId;
            $current->invoice_key = $invoiceKey;
            if ($current->status !== 'paid' && in_array(data_get($data, 'paid'), [1, '1', true], true)) {
                // Sandbox credits never enter spendable USD or accrue cash commissions.
                if (! $current->test_mode) {
                    $user = User::whereKey($current->user_id)->firstOrFail();
                    $entry = app(PlatformWallet::class)->funding($user, $current->amount_milli, 'fawaterak:'.$invoiceId);
                    $current->wallet_entry_id = $entry->id;
                }
                $current->status = 'paid';
            } elseif ($current->status !== 'paid') {
                $due = data_get($data, 'due_date');
                $current->status = is_string($due) && strtotime($due) !== false && strtotime($due) < time() ? 'expired' : 'pending';
            }
            $current->save();

            return $current;
        });
    }

    public function present(PlatformWalletPayment $payment): array
    {
        return ['id' => $payment->id, 'status' => $payment->status, 'amount_milli' => $payment->amount_milli, 'currency' => $payment->currency, 'test_mode' => $payment->test_mode, 'redirect_url' => $payment->redirect_url];
    }
}
