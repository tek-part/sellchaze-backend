<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class StorePhoneBlock extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'scope', 'phone', 'phone_normalized', 'phone_country', 'note', 'active', 'version'];

    protected $casts = ['active' => 'boolean', 'version' => 'integer'];
}
