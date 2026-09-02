<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Store $store
 * @property-read StoreOrder $order
 */
class StorePaymentTransaction extends Model
{
    protected $fillable = [
        'store_id', 'store_order_id', 'gateway', 'idempotency_key', 'provider_reference',
        'status', 'amount', 'currency', 'redirect_url', 'metadata', 'paid_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'paid_at' => 'datetime', 'failed_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
