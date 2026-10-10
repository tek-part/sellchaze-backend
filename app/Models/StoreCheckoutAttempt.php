<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreCheckoutAttempt extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['key_hash', 'owner_hash', 'request_hash', 'lease', 'response_body'];

    protected $casts = ['claimed_at' => 'datetime', 'response_body' => 'encrypted:array', 'response_status' => 'integer'];
}
