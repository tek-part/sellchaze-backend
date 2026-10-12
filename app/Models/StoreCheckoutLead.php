<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class StoreCheckoutLead extends Model
{
    use BelongsToStore;

    protected $guarded = ['id'];

    protected $hidden = ['key_hash', 'capture_hash', 'capture_sequence', 'phone_normalized'];

    protected $casts = [
        'shipping_address' => 'encrypted:array', 'notes' => 'encrypted', 'items' => 'array', 'shipping_selection' => 'array',
        'last_activity_at' => 'datetime', 'converted_at' => 'datetime', 'reviewed_at' => 'datetime',
        'capture_sequence' => 'integer', 'edit_version' => 'integer', 'subtotal' => 'decimal:2',
    ];
}
