<?php

namespace App\Http\Resources;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Coupon
 */
class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'code' => $this->code,
            'type' => $this->type,
            'value' => $this->value,
            'starts_at' => $this->starts_at,
            'expires_at' => $this->expires_at,
            'max_uses' => $this->max_uses,
            'max_uses_per_customer' => $this->max_uses_per_customer,
            'minimum_order_amount' => $this->minimum_order_amount,
            'is_active' => $this->is_active,
            'used_count' => $this->when(isset($this->usages_count), fn () => (int) $this->usages_count),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
