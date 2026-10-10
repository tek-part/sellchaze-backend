<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 7 prep — a purchasable variant of a Product. Store-scoped.
 * `price_override` null means the variant inherits the product's price.
 *
 * @property array|null $translations
 * @property int $stock_quantity
 * @property int $reserved_quantity
 * @property string|null $compare_price
 * @property string|null $cost
 * @property int|null $image_media_id
 * @property ProductMedia|null $imageMedia
 * @property string|null $image
 */
class ProductVariant extends Model
{
    use BelongsToStore;
    use HasTranslations;

    /** Attributes carried per-locale in the `translations` json (see HasTranslations). */
    protected array $translatable = ['name'];

    protected $table = 'store_product_variants';

    protected $with = ['imageMedia'];

    protected $fillable = [
        'store_id', 'store_product_id', 'name', 'sku', 'barcode',
        'price_override', 'compare_price', 'cost', 'weight', 'options', 'image', 'image_media_id',
        'stock_quantity', 'reserved_quantity', 'track_inventory', 'translations', 'is_active', 'position',
    ];

    protected $casts = [
        'price_override' => 'decimal:2',
        'compare_price' => 'decimal:2',
        'cost' => 'decimal:2',
        'weight' => 'decimal:3',
        'options' => 'array',
        'translations' => 'array',
        'stock_quantity' => 'integer',
        'track_inventory' => 'boolean',
        'reserved_quantity' => 'integer',
        'is_active' => 'boolean',
        'position' => 'integer',
        'image_media_id' => 'integer',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'store_product_id');
    }

    /** @return BelongsTo<ProductMedia, $this> */
    public function imageMedia(): BelongsTo
    {
        return $this->belongsTo(ProductMedia::class, 'image_media_id');
    }

    public function imageUrl(): ?string
    {
        $media = $this->relationLoaded('imageMedia') ? $this->imageMedia : null;
        if ($media && $media->store_product_id === $this->store_product_id && in_array($media->type, ['cover', 'gallery'], true)) {
            return $media->url();
        }

        return $this->image ? (str_starts_with($this->image, 'http') ? $this->image : Storage::disk('public')->url($this->image)) : null;
    }

    /** Editorial concurrency token intentionally excludes independently managed inventory. */
    public function editVersion(): string
    {
        return hash('sha256', json_encode($this->versionValue($this->only(['name', 'sku', 'barcode', 'price_override', 'compare_price', 'cost', 'weight', 'options', 'image', 'image_media_id', 'translations', 'is_active', 'position'])), JSON_THROW_ON_ERROR));
    }

    private function versionValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        ksort($value);

        return array_map(fn ($item) => $this->versionValue($item), $value);
    }

    /**
     * The variant's effective price — its override, or the parent product's price
     * when that relation is already loaded (never lazy-loads, so it is N+1-safe).
     */
    public function effectivePrice(): ?string
    {
        if ($this->price_override !== null) {
            return (string) $this->price_override;
        }

        return $this->relationLoaded('product') && $this->product ? (string) $this->product->price : null;
    }
}
