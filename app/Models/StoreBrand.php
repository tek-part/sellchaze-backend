<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Concerns\HasTranslations;
use App\Models\Scopes\ProductScope;
use App\Models\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A brand/manufacturer carried by a store. Store-scoped (tenant-isolated) via BelongsToStore.
 * Additive to the catalog — products optionally reference a brand (products.store_brand_id).
 */
class StoreBrand extends Model
{
    use BelongsToStore;
    use HasTranslations;

    /** Attributes carried per-locale in the `translations` json (see HasTranslations). */
    protected array $translatable = ['name', 'description'];

    protected $fillable = [
        'store_id', 'name', 'slug', 'description', 'logo', 'website', 'origin_country',
        'is_active', 'is_featured', 'position', 'seo_title', 'seo_description', 'translations',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'position' => 'integer',
        'translations' => 'array',
    ];

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'store_brand_id')
            ->withoutGlobalScope(StoreScope::class)
            ->withoutGlobalScope(ProductScope::class);
    }

    public function logoUrl(): ?string
    {
        if (! $this->logo) {
            return null;
        }

        return str_starts_with($this->logo, 'http')
            ? $this->logo
            : Storage::disk('public')->url($this->logo);
    }
}
