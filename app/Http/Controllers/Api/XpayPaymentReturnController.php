<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StorePaymentTransaction;
use App\Services\Commerce\HostedPaymentReconciliation;
use App\Services\Commerce\StorePaymentService;
use Illuminate\Http\Request;

class XpayPaymentReturnController extends Controller
{
    public static function key(Store $store): string
    {
        return hash_hmac('sha256', 'gateway-return:'.$store->id.':xpay', (string) config('app.key'));
    }

    public function __invoke(Request $request, Store $store, HostedPaymentReconciliation $reconciliation, StorePaymentService $payments)
    {
        abort_unless(hash_equals(self::key($store), (string) $request->query('payment_key')), 403);
        $data = $request->validate(['member_id' => ['required', 'uuid']]);
        $transaction = StorePaymentTransaction::query()->where('store_id', $store->id)->where('gateway', 'xpay')->where('idempotency_key', $data['member_id'])->firstOrFail();
        $state = $reconciliation->refresh($transaction);
        if ($request->isMethod('post')) {
            return response()->json(['received' => true]);
        }

        return redirect()->away($payments->paymentRedirectUrl($transaction, $state === 'paid' ? 'success' : ($state === 'failed' ? 'failed' : 'pending')))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
