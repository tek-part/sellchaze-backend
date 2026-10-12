<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property list<string>|null $images */
class MarketplaceService extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['images' => 'array', 'published' => 'boolean', 'version' => 'integer', 'price_minor' => 'integer', 'ratings_count' => 'integer', 'ratings_sum' => 'integer', 'rating' => 'decimal:2'];

    /** @return BelongsTo<MarketplaceServiceProvider,$this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(MarketplaceServiceProvider::class, 'provider_id');
    }
}
