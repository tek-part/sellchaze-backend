<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Editable content for one fixed storefront "system" page of a store
 * (about/contact/faq/shipping/returns/blog). The {@see $data} payload matches
 * the key's field schema in {@see \App\Support\StoreContent\ContentPageSchema}.
 */
class StoreContentPage extends Model
{
    protected $fillable = ['store_id', 'key', 'data', 'is_published'];

    protected $casts = [
        'data' => 'array',
        'is_published' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
