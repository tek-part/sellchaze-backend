<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Export lookups require both actor and store; CLI claims are explicit cross-tenant operations. */
class StoreOrderExport extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['filters', 'order_ids', 'artifact_path', 'lease_token', 'request_key'];

    protected $casts = ['filters' => 'encrypted:array', 'order_ids' => 'encrypted:array', 'order_boundary' => 'integer', 'row_count' => 'integer', 'attempts' => 'integer', 'lease_until' => 'datetime', 'completed_at' => 'datetime', 'expires_at' => 'datetime'];
}
