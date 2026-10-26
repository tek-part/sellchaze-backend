<?php

namespace App\Services\Commerce;

use App\Models\StoreOrder;
use App\Models\StorePaymentTransaction;
use App\Services\Notifications\StoreNotificationSettings;
use Illuminate\Support\Facades\DB;

class PaymentSettlement
{
    public function transition(StorePaymentTransaction $transaction, string $status, ?string $reference = null): void
    {
        DB::transaction(function () use ($transaction, $status, $reference) {
            $locked = StorePaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'paid') {
                return;
            }
            // Webhooks run without a host-resolved CurrentStore. Bind explicitly.
            $order = StoreOrder::forStore($locked->store_id)->whereKey($locked->store_order_id)->lockForUpdate()->firstOrFail();
            // The merchant moved the order to another method after this attempt started. A late provider result is
            // recorded on the attempt, never applied to the order: captured money is kept visible as needing a refund.
            if ($order->payment_method !== $locked->gateway || isset($locked->metadata['superseded'])) {
                $this->unapplied($order, $locked, $status, $reference);

                return;
            }
            abort_unless($order->payment_method === $locked->gateway && strtoupper($order->currency) === strtoupper($locked->currency)
                && bccomp($order->amountDue(), (string) $locked->amount, 2) === 0, 422, 'The payment no longer matches the order.');
            $locked->update($status === 'paid'
                ? ['status' => 'paid', 'provider_reference' => $reference, 'paid_at' => now(), 'failed_at' => null]
                : ['status' => 'failed', 'failed_at' => now()]);
            // A delayed failure from another attempt cannot undo a settled order.
            if ($status === 'paid') {
                $wasPaid = $order->payment_status === 'paid';
                // A deposit order's method collects the deposit; the rest stays due on delivery.
                if (app(OrderDeposits::class)->markPaid($order, $reference)) {
                    if ($order->payment_status === 'paid') {
                        app(DigitalDelivery::class)->record($order);
                    }
                    app(StoreNotificationSettings::class)->orderPaid($order);

                    return;
                }
                $order->update(['payment_status' => 'paid', 'payment_reference' => $reference]);
                if (! $wasPaid) {
                    app(DigitalDelivery::class)->record($order);
                    app(StoreNotificationSettings::class)->orderPaid($order);
                }
            } elseif (! in_array($order->payment_status, ['paid', 'deposit_paid'], true)) {
                $order->update(['payment_status' => 'failed']);
            }
        });
    }

    private function unapplied(StoreOrder $order, StorePaymentTransaction $transaction, string $status, ?string $reference): void
    {
        if ($status !== 'paid') {
            $transaction->update(['status' => 'failed', 'failed_at' => $transaction->failed_at ?? now()]);

            return;
        }
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $metadata['unapplied'] = ['reason' => 'payment_method_changed', 'order_method' => $order->payment_method, 'needs_refund' => true, 'recorded_at' => now()->toIso8601String()];
        $transaction->update(['status' => 'paid', 'provider_reference' => $reference, 'paid_at' => now(), 'failed_at' => null, 'metadata' => $metadata]);
        $order->statusChanges()->create(['store_id' => $order->store_id, 'from_status' => $order->status, 'to_status' => $order->status, 'actor_id' => null, 'source' => 'unapplied_payment',
            'notes' => 'Payment received through '.$transaction->gateway.' after the payment method changed to '.$order->payment_method.': '.$transaction->currency.' '.$transaction->amount
                .($reference ? ' — '.$reference : '').'. Not applied to the order; refund it with the provider or change the order back.']);
    }
}
