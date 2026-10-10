<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreShipmentEvent extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['event_key'];

    protected $casts = ['carrier_time_ms' => 'integer', 'carrier_state' => 'integer', 'applied' => 'boolean'];
}
