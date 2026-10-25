<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Services\Commerce\DiscountEngine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A store-scoped discount: redeemed with a code, or applied automatically. Amount types act on the
 * products in scope (all, chosen products or categories); free shipping and buy X get Y are
 * evaluated by {@see DiscountEngine}.
 *
 * @property array<int, int>|null $target_ids
 * @property array<string, mixed>|null $bxgy
 */
class Coupon extends Model
{
    use BelongsToStore;

    public const TYPES = ['fixed', 'percentage', 'free_shipping', 'buy_x_get_y'];

    public const METHODS = ['code', 'automatic'];

    public const SCOPES = ['all', 'products', 'categories'];

    protected $fillable = [
        'store_id', 'method', 'code', 'title', 'type', 'value', 'applies_to', 'target_ids', 'starts_at', 'expires_at',
        'max_uses', 'max_uses_per_customer', 'minimum_order_amount', 'minimum_quantity', 'bxgy', 'is_active',
    ];

    protected $attributes = ['method' => 'code', 'applies_to' => 'all'];

    protected $casts = [
        'value' => 'decimal:2',
        'minimum_order_amount' => 'decimal:2',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'max_uses' => 'integer',
        'max_uses_per_customer' => 'integer',
        'minimum_quantity' => 'integer',
        'target_ids' => 'array',
        'bxgy' => 'array',
        'is_active' => 'boolean',
    ];

    /** The name shoppers see on the order summary. */
    public function label(): string
    {
        return (string) ($this->title ?: $this->code ?: 'Discount');
    }

    /** @return HasMany<CouponUsage, $this> */
    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }
}
