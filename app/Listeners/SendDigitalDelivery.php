<?php

namespace App\Listeners;

use App\Events\DomainEventPublished;
use App\Mail\DigitalOrderPaidMail;
use App\Models\Store;
use App\Models\StoreOrder;
use Illuminate\Support\Facades\Mail;

class SendDigitalDelivery
{
    public function handle(DomainEventPublished $event): void
    {
        if ($event->message->event_type !== 'StorefrontDigitalPaid') {
            return;
        }
        $storeId = (int) ($event->message->payload['store_id'] ?? 0);
        $order = StoreOrder::forStore($storeId)->whereKey($event->message->payload['store_order_id'] ?? 0)
            ->with(['items' => fn ($query) => $query->withoutGlobalScopes()->where('store_id', $storeId)])->lockForUpdate()->first();
        if (! $order || $order->payment_status !== 'paid' || $order->status === 'cancelled') {
            return;
        }
        $store = Store::findOrFail($storeId);
        // Log/provider errors must never contain private delivery values or receipt tokens.
        try {
            Mail::to($order->customer_email)->send(new DigitalOrderPaidMail($store, $order, $event->message->id));
        } catch (\Throwable) {
            throw new \RuntimeException('Digital delivery email could not be sent; retry is pending.');
        }
    }
}
