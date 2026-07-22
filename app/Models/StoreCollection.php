<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

/**
 * A merchandising collection (Featured, New Arrivals, Best Sellers, Trending, Weekly Deals, Luxury,
 * Budget, Staff Picks, Seasonal, or manual). Store-scoped. Products are attached via the
 * store_collection_product pivot; `is_automated` marks collections derived from product flags.
 */
class StoreCollection extends Model
{
    use BelongsToStore;

    public const TYPES = [
        'featured', 'new-arrivals', 'best-sellers', 'trending', 'weekly-deals',
        'luxury', 'budget', 'staff-picks', 'seasonal', 'manual',
    ];

    protected $fillable = [
        'store_id', 'name', 'slug', 'type', 'description', 'image',
        'is_active', 'is_automated', 'position', 'seo_title', 'seo_description', 'translations',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_automated' => 'boolean',
        'position' => 'integer',
        'translations' => 'array',
    ];

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'store_collection_product', 'store_collection_id', 'store_product_id')
            ->withPivot('position')->withTimestamps()->orderBy('store_collection_product.position');
    }

    public function imageUrl(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return str_starts_with($this->image, 'http')
            ? $this->image
            : Storage::disk('public')->url($this->image);
    }
}
