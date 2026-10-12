<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class StoreSheetDelivery extends Model
{
    use BelongsToStore;

    protected $guarded = [];

    protected $hidden = ['payload', 'lease'];

    protected $casts = ['payload' => 'encrypted:array', 'claimed_until' => 'datetime', 'available_at' => 'datetime', 'delivered_at' => 'datetime', 'attempts' => 'integer'];
}
