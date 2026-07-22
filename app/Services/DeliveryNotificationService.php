<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\User;
use App\Notifications\DeliveryUpdatedNotification;
use Illuminate\Support\Collection;

class DeliveryNotificationService
{
    /**
     * Notify order customer, suppliers on the order, and admins about a delivery change.
     *
     * @param  'created'|'updated'  $event
     */
    public static function notify(OrderDelivery $delivery, string $event): void
    {
        $delivery->loadMissing(['order.user', 'order.suppliers', 'shippingCompany']);
        $order = $delivery->order;
        if (! $order) {
            return;
        }

        $company = $delivery->shippingCompany;
        $trackingUrl = null;
        if ($company && $company->tracking_url_template && $delivery->tracking_number) {
            $trackingUrl = str_replace('{tracking}', (string) $delivery->tracking_number, (string) $company->tracking_url_template);
        }

        $payload = [
            'type' => $event === 'created' ? 'tracking_added' : 'shipping_updated',
            'order_id' => $order->id,
            'order_code' => $order->code,
            'delivery_id' => $delivery->id,
            'tracking' => $delivery->tracking_number,
            'status' => $delivery->status,
            'tracking_url' => $trackingUrl,
        ];

        $users = self::recipientsForOrder($order);
        foreach ($users as $user) {
            $user->notify(new DeliveryUpdatedNotification($payload));
        }
    }

    /**
     * @return Collection<int, User>
     */
    private static function recipientsForOrder(Order $order): Collection
    {
        $ids = collect();
        if ($order->user_id) {
            $ids->push((int) $order->user_id);
        }
        $order->loadMissing('suppliers');
        foreach ($order->suppliers as $os) {
            if ($os->supplier) {
                $ids->push((int) $os->supplier);
            }
        }
        foreach (User::role('Admin')->get() as $admin) {
            $ids->push((int) $admin->id);
        }

        $uniqueIds = $ids->unique()->filter()->values();
        if ($uniqueIds->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('id', $uniqueIds)->get();
    }
}
