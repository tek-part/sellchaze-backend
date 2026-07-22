<?php

namespace App\Http\Resources;

use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentGateway
 */
class PaymentGatewayApiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'wallet_balance' => $this->wallet_balance ?? $this->whenLoaded('wallet', fn () => $this->wallet?->balance),
            'paid_orders_count' => (int) ($this->paid_orders_count ?? 0),
            'paid_orders_total_amount' => (float) ($this->paid_orders_total_amount ?? 0),
        ];
    }
}
