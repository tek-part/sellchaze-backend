<?php

namespace App\Listeners;

use App\Events\DomainEventPublished;
use App\Mail\DigitalOrderPaidMail;
use App\Mail\DigitalOrderReceiptMail;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StorePaymentTransaction;
use Illuminate\Support\Facades\Mail;

class SendDigitalDelivery
{
    public function handle(DomainEventPublished $event): void
    {
        if (! in_array($event->message->event_type, ['StorefrontDigitalPaid', 'StorefrontDigitalReceipt'], true)) {
            return;
        }
        $storeId = (int) ($event->message->payload['store_id'] ?? 0);
        $order = StoreOrder::forStore($storeId)->whereKey($event->message->payload['store_order_id'] ?? 0)
            ->with(['items' => fn ($query) => $query->withoutGlobalScopes()->where('store_id', $storeId)])->lockForUpdate()->first();
        $paidMail = $event->message->event_type === 'StorefrontDigitalPaid';
        if (! $order || $order->status === 'cancelled' || ($paidMail ? $order->payment_status !== 'paid' : $order->payment_status === 'paid')) {
            $event->message->update(['metadata' => array_merge($event->message->metadata ?? [], ['mail_outcome' => 'skipped'])]);

            return;
        }
        // Checkout persists before starting its payment transaction. A publisher
        // racing that step must retry rather than send incomplete bank instructions.
        if (! $paidMail && $order->payment_method === 'bank_transfer' && ! StorePaymentTransaction::query()->where('store_id', $storeId)->where('store_order_id', $order->id)->where('gateway', 'bank_transfer')->exists()) {
            throw new \RuntimeException('Bank transfer receipt is waiting for its payment instructions.');
        }
        $store = Store::findOrFail($storeId);
        // Log/provider errors must never contain private delivery values or receipt tokens.
        try {
            Mail::to($order->customer_email)->send($paidMail ? new DigitalOrderPaidMail($store, $order, $event->message->id) : new DigitalOrderReceiptMail($store, $order, $event->message->id));
            $event->message->update(['metadata' => array_merge($event->message->metadata ?? [], ['mail_outcome' => 'sent', 'mail_sent_at' => now()->toIso8601String()])]);
        } catch (\Throwable) {
            throw new \RuntimeException('Digital delivery email could not be sent; retry is pending.');
        }
    }
}
