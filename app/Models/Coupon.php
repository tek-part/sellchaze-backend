<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 6D: a store-scoped discount coupon.
 */
class Coupon extends Model
{
    use BelongsToStore;

    public const TYPES = ['fixed', 'percentage'];

    protected $fillable = [
        'store_id', 'code', 'type', 'value', 'starts_at', 'expires_at',
        'max_uses', 'max_uses_per_customer', 'minimum_order_amount', 'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'minimum_order_amount' => 'decimal:2',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'max_uses' => 'integer',
        'max_uses_per_customer' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @return HasMany<CouponUsage, $this> */
    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }
}
