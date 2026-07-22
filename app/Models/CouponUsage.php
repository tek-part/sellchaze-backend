<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6D: a single coupon redemption on an order.
 */
class CouponUsage extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id', 'coupon_id', 'store_customer_id', 'store_order_id',
        'discount_amount', 'used_at',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'used_at' => 'datetime',
    ];

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }
}
