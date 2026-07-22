<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5: a cart line. name + unit_price are snapshots taken at add time.
 */
class CartItem extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id', 'cart_id', 'store_product_id', 'name', 'unit_price', 'quantity',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity' => 'integer',
    ];

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'store_product_id');
    }

    public function lineTotal(): string
    {
        return bcmul((string) $this->unit_price, (string) $this->quantity, 2);
    }
}
