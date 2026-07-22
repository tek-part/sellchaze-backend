<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class StoreReusableSection extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'key', 'name', 'type', 'settings'];

    protected $casts = [
        'settings' => 'array',
    ];
}
