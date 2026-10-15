<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class StoreCatalogOperation extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'actor_id', 'idempotency_key', 'operation', 'payload_hash', 'source_payload', 'result'];

    protected $hidden = ['source_payload'];

    protected $casts = ['source_payload' => 'encrypted:array', 'result' => 'array'];
}
