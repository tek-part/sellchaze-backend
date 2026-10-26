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
            abort_unless($order->payment_method === $locked->gateway && strtoupper($order->currency) === strtoupper($locked->currency)
                && bccomp($order->amountDue(), (string) $locked->amount, 2) === 0, 422, 'The payment no longer matches the order.');
            $locked->update($status === 'paid'
                ? ['status' => 'paid', 'provider_reference' => $reference, 'paid_at' => now(), 'failed_at' => null]
                : ['status' => 'failed', 'failed_at' => now()]);
            // A delayed failure from another attempt cannot undo a settled order.
            if ($status === 'paid') {
                $wasPaid = $order->payment_status === 'paid';
                $order->update(['payment_status' => 'paid', 'payment_reference' => $reference]);
                if (! $wasPaid) {
                    app(DigitalDelivery::class)->record($order);
                    app(StoreNotificationSettings::class)->orderPaid($order);
                }
            } elseif ($order->payment_status !== 'paid') {
                $order->update(['payment_status' => 'failed']);
            }
        });
    }
}
