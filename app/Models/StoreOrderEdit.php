<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StoreOrderEdit extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['request_key', 'request_hash', 'owner_id', 'before_snapshot', 'after_snapshot'];

    protected $casts = ['before_snapshot' => 'encrypted:array', 'after_snapshot' => 'encrypted:array', 'revision' => 'integer'];
}
