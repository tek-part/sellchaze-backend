<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class StorePriceChangeItem extends Model
{
    use BelongsToStore;

    public $timestamps = false;

    protected $fillable = ['store_price_change_id', 'store_id', 'product_id', 'variant_id', 'old_price', 'new_price', 'old_compare_price', 'new_compare_price'];

    protected $casts = ['old_price' => 'decimal:2', 'new_price' => 'decimal:2', 'old_compare_price' => 'decimal:2', 'new_compare_price' => 'decimal:2'];
}
