<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorePriceChange extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'actor_id', 'idempotency_key', 'status', 'scope', 'operation', 'product_count', 'variant_count', 'reverted_by', 'reverted_at', 'revert_result'];

    protected $casts = ['scope' => 'array', 'operation' => 'array', 'revert_result' => 'array', 'reverted_at' => 'datetime',
        'product_count' => 'integer', 'variant_count' => 'integer'];

    /** @return HasMany<StorePriceChangeItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StorePriceChangeItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
