<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5: hashed bearer token for a store-customer session.
 */
class StoreCustomerToken extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id', 'store_customer_id', 'name', 'token_hash', 'last_used_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    /** @return BelongsTo<StoreCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(StoreCustomer::class, 'store_customer_id');
    }
}
