<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    protected $fillable = ['supplier_id', 'amount', 'notes', 'recorded_by'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /** @return BelongsTo<User, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
