<?php

namespace App\Actions\Orders;

use App\Events\DashboardStatsUpdated;
use App\Models\Order;
use App\Models\OrderSuppliers;
use App\Models\User;
use App\Notifications\OrderCreated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * Placeholder Action that encapsulates order creation logic.
 * Delegates to existing OrderController flow - can be refactored to move core logic here.
 */
class CreateOrderAction
{
    /**
     * Create an order. Receives validated request data.
     * Core logic extracted from OrderController::store for future refactoring.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data): Order
    {
        $order = new Order;
        $order->code = random_alphanumeric(10);
        $order->source = Order::SOURCE_MERCHANT_DIRECT;
        $order->quantity = $data['quantity'];
        $order->user_id = $data['user_id'];
        $order->product_id = $data['product'];
        $order->attributes = serialize($data['attributes']);
        $order->ref_number = $data['ref_number'] ?? null;
        $order->notes = $data['notes'] ?? null;
        $order->image = $data['image'] ?? null;

        $order->save();

        event(new DashboardStatsUpdated);

        if (! empty($data['suppliers']) && ! empty($data['customer_id'])) {
            foreach ($data['suppliers'] as $supplierId) {
                $orderSupplier = new OrderSuppliers;
                $orderSupplier->order_id = $order->id;
                $orderSupplier->customer = $data['customer_id'];
                $orderSupplier->supplier = $supplierId;
                $orderSupplier->save();

                $user = User::find($supplierId);
                if ($user) {
                    $payload = [
                        'greeting' => 'Hi '.$user->name.', you received a new order!',
                        'body' => 'A customer made and order and chose you to supply this order',
                        'thanks' => 'Thank you',
                        'actionText' => 'Order Details',
                        'actionURL' => route('orders.show', $order->code),
                        'customer_id' => $data['customer_id'],
                        'order_id' => $order->id,
                    ];
                    Notification::send($user, new OrderCreated($payload));
                }
            }
        }

        return $order;
    }
}
