<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** G2: one-time code a Google sign-in hands back to the storefront, exchanged once for a customer token. */
class StoreCustomerLoginCode extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'store_customer_id', 'code_hash', 'verifier_hash', 'expires_at', 'used_at'];

    protected $hidden = ['code_hash', 'verifier_hash'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime'];
}
