<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5: an immutable snapshot line on a storefront order.
 *
 * @property array|null $personalization
 * @property array|null $digital_delivery
 * @property array|null $quantity_offer
 * @property string|null $unit_cost
 */
class StoreOrderItem extends Model
{
    protected $hidden = ['digital_delivery', 'unit_cost'];

    use BelongsToStore;

    protected static function booted(): void
    {
        static::addGlobalScope('current_items', fn ($query) => $query->whereNull($query->getModel()->qualifyColumn('superseded_at')));
    }

    protected $fillable = [
        'store_id', 'store_order_id', 'store_product_id', 'name',
        'unit_price', 'quantity', 'line_total', 'variant_id', 'variant_name', 'variant_options', 'sku', 'inventory_status', 'personalization', 'digital_delivery', 'quantity_offer', 'unit_cost', 'superseded_at',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'line_total' => 'decimal:2',
        'quantity' => 'integer',
        'variant_options' => 'array',
        'personalization' => 'array',
        'quantity_offer' => 'array',
        'digital_delivery' => 'encrypted:array',
        'superseded_at' => 'datetime',
    ];

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'store_product_id');
    }
}
