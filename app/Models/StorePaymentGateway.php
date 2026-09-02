<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorePaymentGateway extends Model
{
    protected $fillable = ['store_id', 'gateway', 'enabled', 'test_mode', 'credentials', 'sort_order', 'notes'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'test_mode' => 'boolean', 'credentials' => 'encrypted:array', 'sort_order' => 'integer'];
    }
}
