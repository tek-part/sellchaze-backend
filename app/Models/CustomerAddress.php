<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5: an entry in a customer's address book.
 */
class CustomerAddress extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id', 'store_customer_id', 'label', 'name', 'phone',
        'line1', 'line2', 'city', 'state', 'country', 'postal_code', 'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /** @return BelongsTo<StoreCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(StoreCustomer::class, 'store_customer_id');
    }
}
