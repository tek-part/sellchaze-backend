<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A stored media asset for a product (or a specific variant). Permanent local records — the media
 * pipeline downloads/optimises the source and persists path + WebP + thumbnail + intrinsic metadata
 * (dimensions, dominant colour, size) so the storefront works offline. `type` is one of
 * cover | gallery | thumbnail | zoom | hover.
 */
class ProductMedia extends Model
{
    use BelongsToStore;

    protected $table = 'store_product_media';

    public const TYPES = ['cover', 'gallery', 'thumbnail', 'zoom', 'hover'];

    protected $fillable = [
        'store_id', 'store_product_id', 'store_product_variant_id', 'type', 'disk', 'path',
        'webp_path', 'thumbnail_path', 'alt', 'width', 'height', 'dominant_color', 'size', 'mime', 'position',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'size' => 'integer',
        'position' => 'integer',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'store_product_id');
    }

    public function url(): ?string
    {
        return $this->diskUrl($this->path);
    }

    public function webpUrl(): ?string
    {
        return $this->diskUrl($this->webp_path);
    }

    public function thumbnailUrl(): ?string
    {
        return $this->diskUrl($this->thumbnail_path);
    }

    private function diskUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http')
            ? $path
            : Storage::disk($this->disk ?: 'public')->url($path);
    }
}
