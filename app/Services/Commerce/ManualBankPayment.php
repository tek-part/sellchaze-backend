<?php

namespace App\Services\Commerce;

use App\Models\StoreOrder;
use App\Models\StorePaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualBankPayment
{
    public function confirm(StoreOrder $order, string $reference, int $actorId, ?string $note = null): StoreOrder
    {
        return DB::transaction(function () use ($order, $reference, $actorId, $note) {
            $transaction = StorePaymentTransaction::query()->where('store_id', $order->store_id)->where('store_order_id', $order->id)
                ->where('gateway', 'bank_transfer')->latest('id')->lockForUpdate()->first();
            $current = StoreOrder::forStore($order->store_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'cancelled' || $current->payment_method !== 'bank_transfer' || $transaction === null
                || bccomp((string) $transaction->amount, (string) $current->grand_total, 2) !== 0 || strtoupper($transaction->currency) !== strtoupper($current->currency)) {
                throw ValidationException::withMessages(['payment' => 'This bank payment cannot be confirmed.']);
            }
            if ($current->payment_status === 'paid' || $transaction->status === 'paid') {
                if ($current->payment_status !== 'paid' || $transaction->status !== 'paid' || $current->payment_reference !== $reference) {
                    throw ValidationException::withMessages(['reference' => 'This payment has already been confirmed with a different reference.']);
                }

                return $current;
            }
            if ($transaction->status !== 'pending') {
                throw ValidationException::withMessages(['payment' => 'This bank payment is not awaiting confirmation.']);
            }
            $transaction->update(['status' => 'paid', 'provider_reference' => $reference, 'paid_at' => now(), 'failed_at' => null]);
            $current->update(['payment_status' => 'paid', 'payment_reference' => $reference]);
            $current->statusChanges()->create(['from_status' => $current->status, 'to_status' => $current->status, 'actor_id' => $actorId,
                'source' => 'bank_payment', 'notes' => 'Bank transfer confirmed: '.$reference.($note ? "\n".$note : '')]);
            app(DigitalDelivery::class)->record($current);
            app(StorefrontOrderBridge::class)->syncPayment($current);
            DB::afterCommit(fn () => StoreAnalyticsService::forget($current->store_id));

            return $current;
        });
    }
}
