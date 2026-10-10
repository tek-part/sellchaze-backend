<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreShipment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['request_snapshot', 'webhook_secret'];

    protected $casts = ['request_snapshot' => 'encrypted:array', 'webhook_secret' => 'encrypted', 'last_event_at_ms' => 'integer', 'carrier_revision' => 'integer', 'carrier_state' => 'integer', 'submitted_at' => 'datetime', 'synced_at' => 'datetime'];
}
