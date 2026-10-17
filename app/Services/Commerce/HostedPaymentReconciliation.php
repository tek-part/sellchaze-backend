<?php

namespace App\Services\Commerce;

use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class HostedPaymentReconciliation
{
    public function refresh(StorePaymentTransaction $transaction): string
    {
        if (! in_array($transaction->gateway, HostedPaymentGateways::PROVIDERS, true) || $transaction->status === 'paid') {
            return $transaction->status;
        }
        if (! $transaction->provider_reference) {
            return $transaction->status;
        }

        return Cache::lock('hosted-payment-verify:'.$transaction->id, 35)->get(function () use ($transaction) {
            if (Cache::has('hosted-payment-checked:'.$transaction->id)) {
                return $transaction->fresh()->status;
            }
            if ($transaction->gateway === 'upayments') {
                $key = 'upayments-status:'.$transaction->store_id;
                if (RateLimiter::tooManyAttempts($key, 25)) {
                    return $transaction->fresh()->status;
                }
                RateLimiter::hit($key, 60);
            }
            $setting = StorePaymentGateway::query()->where('store_id', $transaction->store_id)->where('gateway', $transaction->gateway)->firstOrFail();
            $status = app(HostedPaymentGateways::class)->verify($transaction, $setting);
            if (in_array($status, ['paid', 'failed'], true)) {
                app(PaymentSettlement::class)->transition($transaction, $status, $transaction->provider_reference);
            }
            Cache::put('hosted-payment-checked:'.$transaction->id, true, 15);

            return $transaction->fresh()->status;
        }) ?: $transaction->status;
    }
}
