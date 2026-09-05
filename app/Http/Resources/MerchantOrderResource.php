<?php

namespace App\Http\Resources;

use App\Models\StoreOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 6E: the merchant-facing view of a store order. Distinct from the
 * storefront StoreOrderResource — this one exposes the internal status timeline
 * and per-change internal notes, which must NEVER reach the customer API.
 *
 * @mixin StoreOrder
 */
class MerchantOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'currency' => $this->currency,
            'customer' => [
                'store_customer_id' => $this->store_customer_id,
                'name' => $this->customer_name,
                'email' => $this->customer_email,
                'phone' => $this->customer_phone,
            ],
            'shipping_address' => $this->shipping_address,
            'subtotal' => $this->subtotal,
            'shipping_total' => $this->shipping_total,
            'discount_total' => $this->discount_total, // coupon discount
            'grand_total' => $this->grand_total,
            'items_count' => $this->when(isset($this->items_count), fn () => (int) $this->items_count),
            'customer_notes' => $this->notes,
            'placed_at' => $this->placed_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // The B2B order bridged from this storefront order (null until bridged / when unbridgeable).
            'b2b_order' => $this->whenLoaded('b2bOrder', fn () => $this->b2bOrder ? [
                'id' => $this->b2bOrder->id,
                'code' => $this->b2bOrder->code,
                'status' => $this->b2bOrder->status,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'store_product_id' => $item->store_product_id,
                'name' => $item->name,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
            ])->values()),
            'timeline' => $this->whenLoaded('statusChanges', fn () => $this->statusChanges->map(fn ($change) => [
                'from_status' => $change->from_status,
                'to_status' => $change->to_status,
                'notes' => $change->notes,
                'actor' => $change->relationLoaded('actor') ? $change->actor?->name : null,
                'created_at' => $change->created_at,
            ])->values()),
        ];
    }
}
