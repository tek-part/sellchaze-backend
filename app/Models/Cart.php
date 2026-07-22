<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 5: shopping cart (guest or customer), store-scoped.
 */
class Cart extends Model
{
    use BelongsToStore;

    public const STATUSES = ['active', 'converted', 'abandoned'];

    protected $fillable = [
        'store_id', 'store_customer_id', 'coupon_id', 'token', 'currency', 'status',
    ];

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /** @return BelongsTo<StoreCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(StoreCustomer::class, 'store_customer_id');
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function subtotal(): string
    {
        $sum = $this->items->reduce(
            fn (string $carry, CartItem $item) => bcadd($carry, $item->lineTotal(), 2),
            '0.00',
        );

        return $sum;
    }

    public function itemCount(): int
    {
        return (int) $this->items->sum('quantity');
    }
}
