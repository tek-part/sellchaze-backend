<?php

namespace App\Services\Commerce;

use App\Models\StoreOrder;
use App\Models\StorePaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualBankPayment
{
    public function confirm(StoreOrder $order, string $reference, int $actorId, ?string $note = null, ?string $reviewedProofId = null): StoreOrder
    {
        return DB::transaction(function () use ($order, $reference, $actorId, $note, $reviewedProofId) {
            $transaction = StorePaymentTransaction::query()->where('store_id', $order->store_id)->where('store_order_id', $order->id)
                ->where('gateway', $order->payment_method)->latest('id')->lockForUpdate()->first();
            $current = StoreOrder::forStore($order->store_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (in_array($current->status, ['cancelled', ...StoreOrder::RETURN_STATUSES], true) || ! in_array($current->payment_method, ['bank_transfer', 'transfer_receipt'], true) || $transaction === null
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
            if ($current->payment_method === 'transfer_receipt') {
                $proof = DB::table('store_payment_proofs')->where('store_id', $current->store_id)->where('store_order_id', $current->id)->where('status', 'pending')
                    ->when($reviewedProofId !== null, fn ($query) => $query->where('id', $reviewedProofId))->orderByDesc('created_at')->first();
                if (! $proof) {
                    throw ValidationException::withMessages(['payment' => 'Review an uploaded receipt before confirming this transfer.']);
                }
                DB::table('store_payment_proofs')->where('id', $proof->id)->update(['status' => 'accepted', 'reviewed_by' => $actorId, 'review_note' => null, 'reviewed_at' => now(), 'updated_at' => now()]);
            }
            $transaction->update(['status' => 'paid', 'provider_reference' => $reference, 'paid_at' => now(), 'failed_at' => null]);
            $current->update(['payment_status' => 'paid', 'payment_reference' => $reference]);
            $current->statusChanges()->create(['from_status' => $current->status, 'to_status' => $current->status, 'actor_id' => $actorId,
                'source' => 'bank_payment', 'notes' => 'Bank transfer confirmed: '.$reference.($note ? "\n".$note : '')]);
            app(DigitalDelivery::class)->record($current);
            DB::afterCommit(fn () => StoreAnalyticsService::forget($current->store_id));

            return $current;
        });
    }
}
