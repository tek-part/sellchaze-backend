<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 5: a storefront order. Separate from the legacy B2B Order model.
 *
 * @property string $subtotal
 * @property string $shipping_total
 * @property string $discount_total
 * @property string $tax_total
 * @property string $grand_total
 */
class StoreOrder extends Model
{
    use BelongsToStore;

    public const STATUSES = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];

    protected $fillable = [
        'store_id', 'store_customer_id', 'order_number', 'status', 'currency',
        'customer_name', 'customer_email', 'customer_phone', 'shipping_address',
        'payment_method', 'payment_status', 'payment_reference',
        'subtotal', 'shipping_total', 'discount_total', 'tax_total', 'grand_total', 'notes',
        'placed_at', 'cancelled_at',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'subtotal' => 'decimal:2',
        'shipping_total' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'placed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** @return HasMany<StoreOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StoreOrderItem::class);
    }

    /** @return HasMany<StoreOrderStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(StoreOrderStatusChange::class)->orderBy('id');
    }

    /** @return BelongsTo<StoreCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(StoreCustomer::class, 'store_customer_id');
    }
}
