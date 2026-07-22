<?php

namespace App\Http\Resources\Storefront;

use App\Models\StoreOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoreOrder
 */
class StoreOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'currency' => $this->currency,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'shipping_address' => $this->shipping_address,
            'subtotal' => $this->subtotal,
            'shipping_total' => $this->shipping_total,
            'discount_total' => $this->discount_total,
            'grand_total' => $this->grand_total,
            'notes' => $this->notes,
            'placed_at' => $this->placed_at,
            'cancelled_at' => $this->cancelled_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'store_product_id' => $item->store_product_id,
                'name' => $item->name,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
            ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}
