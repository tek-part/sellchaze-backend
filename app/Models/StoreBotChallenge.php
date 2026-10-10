<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class StoreBotChallenge extends Model
{
    use BelongsToStore;

    protected $guarded = ['id'];

    protected $hidden = ['nonce_hash', 'provider_token_hash', 'ip_hash', 'generation'];

    protected $casts = ['expires_at' => 'datetime'];
}
