<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StorePaymentTransaction;
use App\Services\Commerce\HostedPaymentGateways;
use App\Services\Commerce\HostedPaymentReconciliation;
use App\Services\Commerce\OrderReceipt;
use App\Services\Commerce\StorePaymentService;
use Illuminate\Http\Request;

class HostedPaymentReturnController extends Controller
{
    public function __invoke(Request $request, StorePaymentTransaction $transaction, HostedPaymentReconciliation $reconciliation, StorePaymentService $payments)
    {
        // Provider-added query parameters are ignored; the stored session is re-fetched.
        abort_unless(hash_equals(app(HostedPaymentGateways::class)->returnKey($transaction), (string) $request->query('payment_key'))
            && $transaction->created_at->greaterThan(now()->subSeconds(OrderReceipt::TTL)), 403);
        abort_unless(in_array($transaction->gateway, HostedPaymentGateways::PROVIDERS, true), 404);
        $state = $reconciliation->refresh($transaction);
        if ($request->isMethod('post')) {
            return response()->json(['received' => true])->header('Cache-Control', 'private, no-store');
        }

        return redirect()->away($payments->paymentRedirectUrl($transaction, $state === 'paid' ? 'success' : ($state === 'failed' ? 'failed' : 'pending')))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
