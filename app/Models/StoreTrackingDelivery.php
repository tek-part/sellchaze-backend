<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreTrackingDelivery extends Model
{
    protected $guarded = [];

    protected $casts = ['claimed_until' => 'datetime', 'available_at' => 'datetime', 'delivered_at' => 'datetime', 'attempts' => 'integer'];
}
