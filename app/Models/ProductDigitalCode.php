<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property string $value */
class ProductDigitalCode extends Model
{
    protected $fillable = ['store_id', 'product_id', 'value', 'digest', 'store_order_item_id'];

    protected $hidden = ['value', 'digest'];

    protected $casts = ['value' => 'encrypted'];
}
