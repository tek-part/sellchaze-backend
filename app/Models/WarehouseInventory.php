<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseInventory extends Model
{
    protected $fillable = [
        'warehouse_id',
        'product_id',
        'qty',
        'reserved_qty',
    ];

    protected $casts = [
        'qty' => 'integer',
        'reserved_qty' => 'integer',
    ];

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function availableQty(): int
    {
        return max(0, (int) $this->qty - (int) $this->reserved_qty);
    }
}
