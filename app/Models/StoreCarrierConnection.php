<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property string|null $api_key */
class StoreCarrierConnection extends Model
{
    protected $fillable = ['store_id', 'carrier', 'api_key', 'enabled', 'pickup_location_id', 'pickup_locations', 'verified_at'];

    protected $hidden = ['api_key'];

    protected $casts = ['api_key' => 'encrypted', 'enabled' => 'boolean', 'pickup_locations' => 'array', 'verified_at' => 'datetime'];
}
