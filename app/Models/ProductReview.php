<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6: a customer's review of a store product. Store-scoped.
 */
class ProductReview extends Model
{
    use BelongsToStore;

    public const STATUSES = ['pending', 'approved', 'rejected', 'hidden'];

    protected $fillable = [
        'store_id', 'store_customer_id', 'store_product_id',
        'rating', 'title', 'body', 'status',
        'author_name', 'pros', 'cons', 'is_verified', 'helpful_count', 'reviewed_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'pros' => 'array',
        'cons' => 'array',
        'is_verified' => 'boolean',
        'helpful_count' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'store_product_id');
    }

    /** @return BelongsTo<StoreCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(StoreCustomer::class, 'store_customer_id');
    }
}
