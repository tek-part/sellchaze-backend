<?php

namespace App\Services\Commerce;

use App\Models\StoreOrder;
use App\Models\StorePaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualCashPayment
{
    /** Record full cash collection independently of physical delivery progress. */
    public function confirm(StoreOrder $order, string $reference, string $amount, string $currency, int $actorId, ?string $note = null): StoreOrder
    {
        $reference = trim($reference);

        return DB::transaction(function () use ($order, $reference, $amount, $currency, $actorId, $note) {
            // Payment callbacks use transaction -> order; preserve that lock order.
            $transaction = StorePaymentTransaction::query()->where('store_id', $order->store_id)->where('store_order_id', $order->id)
                ->where('gateway', 'cod')->latest('id')->lockForUpdate()->first();
            $current = StoreOrder::forStore($order->store_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($reference === '' || $current->status === 'cancelled' || $current->payment_method !== 'cod' || $transaction === null
                || bccomp((string) $transaction->amount, (string) $current->grand_total, 2) !== 0 || strtoupper($transaction->currency) !== strtoupper($current->currency)
                || bccomp($amount, (string) $current->grand_total, 2) !== 0 || strtoupper($currency) !== strtoupper($current->currency)) {
                throw ValidationException::withMessages(['payment' => 'Confirm the full collected amount and currency for this cash-on-delivery order.']);
            }
            $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
            $collection = is_array($metadata['cash_collection'] ?? null) ? $metadata['cash_collection'] : [];
            if ($current->payment_status === 'paid' || $transaction->status === 'paid') {
                if ($current->payment_status !== 'paid' || $transaction->status !== 'paid' || $current->payment_reference !== $reference
                    || ($collection['reference'] ?? null) !== $reference) {
                    throw ValidationException::withMessages(['reference' => 'This payment has already been confirmed with another reference or method.']);
                }

                return $current;
            }
            if (! in_array($current->payment_status, ['pending', 'unpaid'], true) || $transaction->status !== 'pending') {
                throw ValidationException::withMessages(['payment' => 'This cash payment is not awaiting collection.']);
            }
            $metadata['cash_collection'] = ['reference' => $reference, 'amount' => (string) $current->grand_total, 'currency' => strtoupper($current->currency),
                'actor_id' => $actorId, 'confirmed_at' => now()->toIso8601String(), 'note' => $note];
            $transaction->update(['status' => 'paid', 'provider_reference' => $reference, 'paid_at' => now(), 'failed_at' => null, 'metadata' => $metadata]);
            $current->update(['payment_status' => 'paid', 'payment_reference' => $reference]);
            $current->statusChanges()->create(['from_status' => $current->status, 'to_status' => $current->status, 'actor_id' => $actorId,
                'source' => 'cash_payment', 'notes' => 'Full cash collection confirmed: '.$reference.' — '.$current->currency.' '.$current->grand_total.($note ? "\n".$note : '')]);
            app(DigitalDelivery::class)->record($current);
            app(StorefrontOrderBridge::class)->syncPayment($current);
            DB::afterCommit(fn () => StoreAnalyticsService::forget($current->store_id));

            return $current;
        });
    }
}
