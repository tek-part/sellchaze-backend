<?php

namespace App\Http\Resources;

use App\Models\OrderQuotations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderQuotations
 */
class OrderQuotationApiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'price' => $this->price,
            'currency' => $this->currency,
            'delivery_date' => $this->delivery_date,
            'notes' => $this->notes,
            'status' => $this->status,
            'shipping_company' => $this->shipping_company,
            'price_includes_shipping' => (bool) $this->price_includes_shipping,
            'tracking_number' => $this->tracking_number,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'order' => $this->whenLoaded('order', fn () => [
                'id' => $this->order->id,
                'code' => $this->order->code,
                'ref_number' => $this->order->ref_number,
                'status' => $this->order->status,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
            ]),
            'supplier_user' => $this->whenLoaded('supplierUser', fn () => [
                'id' => $this->supplierUser->id,
                'name' => $this->supplierUser->name,
                'email' => $this->supplierUser->email,
            ]),
        ];
    }
}
