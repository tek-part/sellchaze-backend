<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7 prep — a purchasable variant of a Product. Store-scoped.
 * `price_override` null means the variant inherits the product's price.
 *
 * @property array|null $translations
 */
class ProductVariant extends Model
{
    use BelongsToStore;
    use HasTranslations;

    /** Attributes carried per-locale in the `translations` json (see HasTranslations). */
    protected array $translatable = ['name'];

    protected $table = 'store_product_variants';

    protected $fillable = [
        'store_id', 'store_product_id', 'name', 'sku', 'barcode',
        'price_override', 'compare_price', 'cost', 'weight', 'options', 'image',
        'stock_quantity', 'reserved_quantity', 'translations', 'is_active', 'position',
    ];

    protected $casts = [
        'price_override' => 'decimal:2',
        'compare_price' => 'decimal:2',
        'cost' => 'decimal:2',
        'weight' => 'decimal:3',
        'options' => 'array',
        'translations' => 'array',
        'stock_quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'store_product_id');
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
