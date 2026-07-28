<?php

namespace App\Http\Resources\Storefront;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-safe view of an active coupon for "current offers" strips. Deliberately omits
 * usage caps and internal counters — only what a shopper needs to see the offer.
 *
 * @mixin Coupon
 */
class StorefrontCouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'type' => $this->type, // fixed | percentage
            'value' => $this->value,
            'minimum_order_amount' => $this->minimum_order_amount,
            'expires_at' => $this->expires_at,
        ];
    }
}
