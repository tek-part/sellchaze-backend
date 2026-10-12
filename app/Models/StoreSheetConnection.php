<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreSheetConnection extends Model
{
    use BelongsToStore, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['credentials', 'creation_hash'];

    protected $casts = ['credentials' => 'encrypted:array', 'product_ids' => 'array', 'enabled' => 'boolean', 'all_products' => 'boolean', 'version' => 'integer', 'starts_at' => 'datetime'];
}
