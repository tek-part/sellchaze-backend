<?php

namespace App\Http\Resources;

use App\Models\StoreOrder;
use App\Services\Commerce\DigitalProducts;
use App\Services\Commerce\ProductPersonalization;
use App\Services\Commerce\StoreOrderService;
use App\Services\PlatformWallet;
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
        app(PlatformWallet::class)->assertOrderVisible($this->resource);
        $this->resource->loadMissing('merchantReview');
        $review = $this->merchantReview;

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'archived_at' => $this->deleted_at,
            'review' => ['classification' => $review?->classification, 'version' => $review?->version ?? 0,
                'actor_id' => $review?->actor_id, 'updated_at' => $review?->updated_at],
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
            'coupon_code' => $this->coupon_code,
            'entry_source' => $this->manual_request_key ? 'manual' : 'storefront',
            'manual_actor_id' => $this->manual_actor_id,
            'tax_total' => $this->tax_total,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'payment_reference' => $this->payment_reference,
            'fulfillment' => $this->whenLoaded('items', fn () => $this->resource->fulfillment()),
            'next_statuses' => $this->whenLoaded('items', fn () => app(StoreOrderService::class)->nextStatuses($this->resource)),
            'grand_total' => $this->grand_total,
            'manual_adjustment_total' => $this->manual_adjustment_total ?? '0.00',
            'manual_tax_included' => $this->manual_tax_included,
            'items_count' => $this->when(isset($this->items_count), fn () => (int) $this->items_count),
            'customer_notes' => $this->notes,
            'marketing_attribution' => $this->marketing_attribution,
            'referral_attribution' => $this->referral_attribution,
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
                'sku' => $item->sku,
                'variant_id' => $item->variant_id,
                'variant_name' => $item->variant_name,
                'variant_options' => $item->variant_options,
                'personalization' => ProductPersonalization::present($item->personalization ?? [], $this->store_id),
                'digital_delivery' => DigitalProducts::present($item, $this->resource),
                'name' => $item->name,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
            ])->values()->all()),
            'timeline' => $this->whenLoaded('statusChanges', fn () => $this->statusChanges->map(fn ($change) => [
                'from_status' => $change->from_status,
                'to_status' => $change->to_status,
                'notes' => $change->notes,
                'source' => $change->source,
                'actor' => $change->relationLoaded('actor') ? $change->actor?->name : null,
                'created_at' => $change->created_at,
            ])->values()),
        ];
    }
}
