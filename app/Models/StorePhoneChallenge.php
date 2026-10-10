<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class StorePhoneChallenge extends Model
{
    use BelongsToStore;

    protected $guarded = ['id'];

    protected $hidden = ['phone_normalized', 'token_hash', 'code_hash', 'ip_hash', 'generation'];

    protected $casts = ['expires_at' => 'datetime', 'resend_at' => 'datetime', 'verified_at' => 'datetime', 'proof_expires_at' => 'datetime', 'attempts' => 'integer'];
}
