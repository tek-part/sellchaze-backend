<?php

namespace App\Services\Commerce;

use App\Models\StoreOrder;
use App\Services\Outbox\OutboxRecorder;

class DigitalDelivery
{
    /** Called only inside the transaction changing an unpaid order to paid. */
    public function record(StoreOrder $order): void
    {
        if ($order->status !== 'cancelled' && $order->items()->withoutGlobalScopes()->where('store_id', $order->store_id)->whereNotNull('digital_delivery')->exists()) {
            app(OutboxRecorder::class)->record('StorefrontDigitalPaid', 'store_order', $order->id,
                ['store_id' => $order->store_id, 'store_order_id' => $order->id]);
        }
    }
}
