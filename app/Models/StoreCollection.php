<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

/**
 * A merchandising collection (Featured, New Arrivals, Best Sellers, Trending, Weekly Deals, Luxury,
 * Budget, Staff Picks, Seasonal, or manual). Store-scoped. Products are attached via the
 * store_collection_product pivot; `is_automated` marks collections derived from product flags.
 *
 * Merchant collections: a manual collection lists its pivot products in pivot order; an automated
 * collection with `rules` resolves products with a query (see StoreCollectionProducts). Legacy
 * automated collections without rules keep their seeded pivot products.
 *
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property string $slug
 * @property string $type
 * @property string|null $description
 * @property string|null $image
 * @property bool $is_active
 * @property bool $is_automated
 * @property array<int, array{field: string, operator: string, value: mixed}>|null $rules
 * @property string $rules_match
 * @property string $sort_order
 * @property int $position
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property array|null $translations
 * @property int|null $products_count
 */
class StoreCollection extends Model
{
    use BelongsToStore;
    use HasTranslations;

    /** Attributes carried per-locale in the `translations` json (see HasTranslations). */
    protected array $translatable = ['name', 'description'];

    public const TYPES = [
        'featured', 'new-arrivals', 'best-sellers', 'trending', 'weekly-deals',
        'luxury', 'budget', 'staff-picks', 'seasonal', 'manual',
    ];

    protected $fillable = [
        'store_id', 'name', 'slug', 'type', 'description', 'image',
        'is_active', 'is_automated', 'position', 'seo_title', 'seo_description', 'translations',
        'rules', 'rules_match', 'sort_order',
    ];

    /** Product orderings a merchant can pick for a collection. */
    public const SORT_ORDERS = ['manual', 'best_selling', 'price_asc', 'price_desc', 'newest', 'title_asc', 'title_desc'];

    protected $attributes = ['rules_match' => 'all', 'sort_order' => 'manual'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_automated' => 'boolean',
        'rules' => 'array',
        'position' => 'integer',
        'translations' => 'array',
    ];

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'store_collection_product', 'store_collection_id', 'store_product_id')
            ->withPivot('position')->withTimestamps()->orderBy('store_collection_product.position');
    }

    /** Automated collections with rules resolve products by query; everything else uses the pivot. */
    public function usesRules(): bool
    {
        return $this->is_automated && is_array($this->rules) && $this->rules !== [];
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
