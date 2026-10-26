<?php

namespace App\Http\Resources\Storefront;

use App\Models\StoreOrder;
use App\Services\Commerce\DigitalProducts;
use App\Services\Commerce\OrderDeposits;
use App\Services\Commerce\ProductPersonalization;
use App\Services\Commerce\StoreTrackingEvents;
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
            // Aliases the storefront view-models read (number/total/shipping).
            'number' => $this->order_number,
            'tracking' => $this->when($request->boolean('advertising_allowed'), fn () => app(StoreTrackingEvents::class)->purchaseForBrowser($this->resource, $request)),
            'total' => $this->grand_total,
            'shipping' => $this->shipping_total,
            'items_count' => $this->whenLoaded('items', fn () => $this->items->sum('quantity')),
            'status' => $this->status,
            'currency' => $this->currency,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'shipping_address' => $this->shipping_address,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'fulfillment' => $this->whenLoaded('items', fn () => $this->resource->fulfillment()),
            'payment_reference' => $this->payment_reference,
            'subtotal' => $this->subtotal,
            'shipping_total' => $this->shipping_total,
            'discount_total' => $this->discount_total,
            'tax_total' => $this->tax_total,
            'grand_total' => $this->grand_total,
            'gift_card_total' => $this->gift_card_total ?? '0.00',
            'gift_cards' => array_map(fn (array $card) => ['last4' => $card['last4'], 'amount' => $card['amount']], $this->gift_card_details ?? []),
            'amount_due' => $this->resource->amountDue(),
            // Deposit orders: paid online first, `remaining` is due on delivery (null without a deposit).
            'deposit' => OrderDeposits::present($this->resource),
            'payment_adjustment' => $this->payment_adjustment,
            'payment_adjustment_total' => $this->payment_adjustment['amount'] ?? '0.00',
            'manual_adjustment_total' => $this->manual_adjustment_total ?? '0.00',
            'manual_tax_included' => $this->manual_tax_included,
            'notes' => $this->notes,
            'placed_at' => $this->placed_at,
            'cancelled_at' => $this->cancelled_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'store_product_id' => $item->store_product_id,
                'variant_id' => $item->variant_id,
                'variant_name' => $item->variant_name,
                'variant_options' => $item->variant_options,
                'personalization' => ProductPersonalization::present($item->personalization ?? [], $this->store_id),
                'digital_delivery' => DigitalProducts::present($item, $this->resource),
                'sku' => $item->sku,
                'name' => $item->name,
                'unit_price' => $item->unit_price,
                'price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
            ])->values()->all()),
            'created_at' => $this->created_at,
        ];
    }
}
