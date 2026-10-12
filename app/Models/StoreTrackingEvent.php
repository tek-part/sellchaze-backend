<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreTrackingEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['payload'];

    protected $casts = ['payload' => 'encrypted:array', 'occurred_at' => 'datetime'];
}
