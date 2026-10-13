<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreOrderWebhook extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['secret', 'request_key'];

    protected $attributes = ['version' => 1];

    protected $casts = ['secret' => 'encrypted', 'enabled' => 'boolean', 'version' => 'integer'];
}
