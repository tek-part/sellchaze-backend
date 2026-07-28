<?php

namespace App\Models;

use App\Models\Concerns\HasImageUrl;
use App\Models\Concerns\HasStoreTenancy;
use App\Models\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The single canonical product model. Serves BOTH the global B2B catalog (store_id = NULL) and the
 * multi-tenant storefront catalog (store_id = the store), isolated by the leak-safe {@see ProductScope}
 * applied via {@see HasStoreTenancy}. Carries the full catalog/PIM surface plus the legacy B2B fields
 * (user_id, category, orders, inventory, bundles).
 *
 * @property int $id
 * @property string $name
 * @property string|null $slug
 * @property string|null $sku
 * @property string|null $barcode
 * @property string|null $supplier_code
 * @property string|null $description
 * @property string|null $short_description
 * @property string|null $long_description
 * @property string|null $keywords
 * @property string|null $image
 * @property int|null $user_id
 * @property int|null $category_id
 * @property int|null $store_id
 * @property int|null $store_brand_id
 * @property array|null $tags
 * @property array|null $specifications
 * @property array|null $content
 * @property array|null $dimensions
 * @property string $price
 * @property string|null $compare_price
 * @property string|null $cost
 * @property string|null $vat_rate
 * @property string|null $discount_percent
 * @property string|null $currency
 * @property string|null $weight
 * @property string|null $material
 * @property string|null $warranty
 * @property string|null $shipping_returns
 * @property string|null $care_instructions
 * @property array|null $highlights
 * @property string|null $origin_country
 * @property string|null $manufacturer
 * @property string|null $unit
 * @property int $stock_quantity
 * @property int $reserved_quantity
 * @property int $reorder_level
 * @property bool $is_active
 * @property bool $is_featured
 * @property bool $is_bestseller
 * @property bool $is_new_arrival
 * @property bool $is_trending
 * @property string $rating_avg
 * @property int $rating_count
 * @property int $sales_count
 * @property int $views_count
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property array|null $translations
 * @property Carbon|null $published_at
 * @property int $position
 */
class Product extends Model
{
    use HasImageUrl;
    use HasStoreTenancy;

    /**
     * @var list<string>
     */
    protected $fillable = [
        // identity
        'name', 'slug', 'sku', 'barcode', 'supplier_code', 'description', 'short_description',
        'long_description', 'keywords', 'image',
        // ownership / taxonomy / tenancy
        'user_id', 'category_id', 'store_id', 'store_brand_id',
        // structured content
        'tags', 'specifications', 'content', 'dimensions',
        // pricing
        'price', 'compare_price', 'cost', 'vat_rate', 'discount_percent', 'currency',
        // logistics
        'weight', 'material', 'warranty', 'origin_country', 'manufacturer', 'unit',
        // per-product storefront PDP content
        'shipping_returns', 'care_instructions', 'highlights',
        // inventory
        'stock_quantity', 'reserved_quantity', 'reorder_level',
        // visibility / flags
        'is_active', 'is_featured', 'is_bestseller', 'is_new_arrival', 'is_trending',
        // rollups
        'rating_avg', 'rating_count', 'sales_count', 'views_count',
        // seo / i18n / publishing / ordering
        'seo_title', 'seo_description', 'translations', 'published_at', 'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_price' => 'decimal:2',
            'cost' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'weight' => 'decimal:3',
            'rating_avg' => 'decimal:2',
            'rating_count' => 'integer',
            'sales_count' => 'integer',
            'views_count' => 'integer',
            'stock_quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'reorder_level' => 'integer',
            'position' => 'integer',
            'tags' => 'array',
            'specifications' => 'array',
            'content' => 'array',
            'dimensions' => 'array',
            'highlights' => 'array',
            'translations' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_bestseller' => 'boolean',
            'is_new_arrival' => 'boolean',
            'is_trending' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Public URL for the product cover image. Overrides {@see HasImageUrl} because a dashboard-uploaded
     * cover is stored as a BARE FILENAME whose file lives under `storage/uploads/products/original/`
     * (not at the disk root). Absolute URLs and already-pathed values pass through unchanged.
     */
    public function imageUrl(): ?string
    {
        if (! $this->image) {
            return null;
        }
        if (str_starts_with($this->image, 'http')) {
            return $this->image;
        }
        // Use the public disk (APP_URL host) so the URL is reachable regardless of the request host.
        $path = str_contains($this->image, '/') ? $this->image : 'uploads/products/original/'.$this->image;

        return \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    }

    // ---- Legacy B2B relationships (unchanged) --------------------------------------------------

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductAttributes, $this> */
    public function product_attributes(): HasMany
    {
        return $this->hasMany(ProductAttributes::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<ProductOrders, $this> */
    public function product_orders(): HasMany
    {
        return $this->hasMany(ProductOrders::class);
    }

    /** @return HasMany<WarehouseInventory, $this> */
    public function inventories(): HasMany
    {
        return $this->hasMany(WarehouseInventory::class);
    }

    // ---- Storefront catalog relationships ------------------------------------------------------
    // store() + scopeForStore() come from the shared InteractsWithStore trait. The satellite tables
    // (variants/media/collections/reviews/brands) reference products via their store_product_id FK.

    /** @return BelongsTo<StoreBrand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(StoreBrand::class, 'store_brand_id');
    }

    /** @return HasMany<ProductMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class, 'store_product_id')
            ->withoutGlobalScope(StoreScope::class)
            ->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<ProductReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class, 'store_product_id')
            ->withoutGlobalScope(StoreScope::class);
    }

    /** @return BelongsToMany<StoreCollection, $this> */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(StoreCollection::class, 'store_collection_product', 'store_product_id', 'store_collection_id')
            ->withPivot('position')->withTimestamps();
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'store_product_id')
            ->withoutGlobalScope(StoreScope::class)
            ->orderBy('position')->orderBy('id');
    }

    // ---- Query scopes (opt-in; no global tenant scope on this model) ----------------------------
    // forStore() comes from the shared InteractsWithStore trait.

    /** @param Builder<Product> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where($this->getTable().'.is_active', true);
    }

    /** @param Builder<Product> $query */
    public function scopeFeatured(Builder $query): void
    {
        $query->where($this->getTable().'.is_featured', true);
    }

    /** @param Builder<Product> $query */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull($this->getTable().'.published_at')
            ->where($this->getTable().'.published_at', '<=', now());
    }
}
