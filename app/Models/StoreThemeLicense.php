<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreThemeLicense extends Model
{
    protected $fillable = [
        'store_id', 'theme_id', 'acquired_by_user_id', 'status', 'source', 'price_paid',
        'currency', 'order_reference', 'starts_at', 'expires_at',
    ];

    protected $casts = [
        'price_paid' => 'decimal:2', 'starts_at' => 'datetime', 'expires_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->status === 'active'
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
