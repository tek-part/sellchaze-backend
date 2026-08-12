<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property bool $is_visible */
class StorePageSection extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_page_id', 'store_id', 'type', 'settings', 'reusable_section_id', 'position', 'is_visible',
    ];

    protected $casts = [
        'settings' => 'array',
        'position' => 'integer',
        'is_visible' => 'boolean',
    ];

    /** @return BelongsTo<StorePage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(StorePage::class, 'store_page_id');
    }

    /** @return BelongsTo<StoreReusableSection, $this> */
    public function reusable(): BelongsTo
    {
        return $this->belongsTo(StoreReusableSection::class, 'reusable_section_id');
    }
}
