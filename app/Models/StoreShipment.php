<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreShipment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['request_snapshot'];

    protected $casts = ['request_snapshot' => 'encrypted:array', 'carrier_state' => 'integer', 'submitted_at' => 'datetime', 'synced_at' => 'datetime'];
}
