<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'ref_number' => $this->ref_number,
            'status' => $this->status,
            'quantity' => $this->quantity,
            'created_at' => $this->created_at?->toIso8601String(),
            'product' => $this->whenLoaded('product', fn () => [
                'name' => $this->product->name ?? null,
            ]),
        ];
    }
}
