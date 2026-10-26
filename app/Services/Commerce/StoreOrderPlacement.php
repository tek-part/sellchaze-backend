<?php

namespace App\Services\Commerce;

use App\Models\StoreOrder;
use App\Notifications\StoreOrderActivity;
use App\Services\Notifications\StoreNotificationSettings;
use App\Services\Outbox\OutboxRecorder;
use App\Services\PlatformWallet;

/** Record both customer and authorized merchant placements inside their order transaction. */
class StoreOrderPlacement
{
    public function record(StoreOrder $order): void
    {
        StoreAnalyticsService::forget($order->store_id);
        app(OutboxRecorder::class)->record('StorefrontOrderPlaced', 'store_order', $order->id, [
            'store_id' => $order->store_id, 'store_order_id' => $order->id,
            'order_number' => $order->order_number, 'grand_total' => $order->grand_total,
            'gift_card_total' => (string) ($order->gift_card_total ?? '0.00'), 'amount_due' => $order->amountDue(),
            'currency' => $order->currency, 'payment_method' => $order->payment_method,
        ]);
        app(PlatformWallet::class)->orderFee($order);
        app(StoreSheets::class)->record($order);
        app(MerchantOrderPush::class)->record($order);
        app(StoreOrderWebhooks::class)->created($order);
        app(DigitalDelivery::class)->recordReceipt($order);
        $order->store?->owner?->notify(new StoreOrderActivity($order, 'created'));
        app(StoreNotificationSettings::class)->orderPlaced($order);
    }
}
