<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StoreOrderWebhookDelivery extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['payload', 'lease_token'];

    protected $casts = ['payload' => 'encrypted:array', 'webhook_version' => 'integer', 'attempts' => 'integer',
        'next_attempt_at' => 'datetime', 'expires_at' => 'datetime', 'lease_until' => 'datetime', 'delivered_at' => 'datetime'];
}
