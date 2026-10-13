<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property array<string,mixed> $subscription */
class MerchantPushSubscription extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['subscription', 'endpoint_hash', 'vapid_fingerprint', 'auth_session_id'];

    protected $casts = ['subscription' => 'encrypted:array', 'enabled' => 'boolean', 'version' => 'integer'];
}
