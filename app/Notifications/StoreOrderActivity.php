<?php

namespace App\Notifications;

use App\Models\StoreOrder;
use Illuminate\Notifications\Notification;

/** Stored in the order transaction; no email, provider or queue side effect. */
class StoreOrderActivity extends Notification
{
    public function __construct(private readonly StoreOrder $order, private readonly string $event) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'store_order',
            'event' => $this->event,
            'store_id' => $this->order->store_id,
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'status' => $this->order->status,
            'url' => '/store/orders/'.$this->order->id,
        ];
    }
}
