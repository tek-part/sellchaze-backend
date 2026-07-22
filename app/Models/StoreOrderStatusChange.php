<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6E: a single entry in a store order's status timeline.
 */
class StoreOrderStatusChange extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id', 'store_order_id', 'from_status', 'to_status', 'actor_id', 'notes',
    ];

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
