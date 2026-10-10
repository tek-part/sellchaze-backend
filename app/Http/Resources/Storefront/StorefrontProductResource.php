<?php

namespace App\Http\Resources\Storefront;

use App\Models\Product;
use App\Services\Commerce\DigitalProducts;
use App\Services\Commerce\OrderLimits;
use App\Services\Commerce\ProductPersonalization;
use App\Services\Storefront\ResponsiveImageUrl;
use App\Support\ProductOptionDisplay;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class StorefrontProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $imageUrl = $this->imageUrl();
        $digitalStock = app(DigitalProducts::class)->available($this->resource);
        $store = app(CurrentStore::class)->get();

        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'name' => $this->translated('name'),
            'slug' => $this->slug,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'description' => $this->translated('description'),
            'short_description' => $this->translated('short_description'),
            'long_description' => $this->long_description,
            'price' => $this->price,
            'compare_price' => $this->compare_price,
            'image' => $this->image,
            'image_url' => $imageUrl,
            'image_responsive' => app(ResponsiveImageUrl::class)->for($imageUrl),
            'is_active' => $this->is_active,
            'digital_type' => $this->digital_type,
            'digital_pool_stock' => $digitalStock,
            'order_quantity_limit' => $store && (int) $store->id === (int) $this->store_id ? app(OrderLimits::class)->productLimit($store) : null,
            'is_featured' => $this->is_featured,
            'stock' => $digitalStock === null ? ($this->track_inventory ? max(0, $this->stock_quantity - $this->reserved_quantity) : null) : ($this->track_inventory ? min($digitalStock, max(0, $this->stock_quantity - $this->reserved_quantity)) : $digitalStock),
            'track_inventory' => (bool) $this->track_inventory,
            'position' => $this->position,
            // Additive enrichment (backward-compatible: existing keys unchanged, new keys the frozen
            // frontend mapper already understands — rating, reviews_count, images gallery).
            'rating' => $this->rating_avg !== null ? (float) $this->rating_avg : null,
            'reviews_count' => (int) ($this->rating_count ?? 0),
            'is_bestseller' => (bool) ($this->is_bestseller ?? false),
            'is_new_arrival' => (bool) ($this->is_new_arrival ?? false),
            'is_trending' => (bool) ($this->is_trending ?? false),
            // Rich PDP surface — the theme product pages render these tabs/sections when present.
            'specifications' => $this->specifications ?: null,   // key/value map or list
            'dimensions' => $this->dimensions ?: null,
            'highlights' => $this->highlights ?: null,           // string[] of key features
            'weight' => $this->weight,
            'material' => $this->material,
            'warranty' => $this->warranty,
            'shipping_returns' => $this->shipping_returns,
            'care_instructions' => $this->care_instructions,
            'origin_country' => $this->origin_country,
            'manufacturer' => $this->manufacturer,
            'unit' => $this->unit,
            'tags' => $this->tags ?: null,
            'brand' => $this->whenLoaded('brand', fn () => $this->brand?->translated('name')),
            'media' => $this->whenLoaded('media', fn () => $this->media
                ->filter(fn ($m) => in_array($m->type, ['cover', 'gallery', 'video'], true) && $m->url())
                ->map(fn ($m) => ['id' => $m->id, 'type' => $m->type === 'video' ? 'video' : 'image',
                    'url' => $m->url(), 'alt' => $m->alt, 'mime' => $m->mime, 'position' => $m->position])->values()->all()),
            'images' => $this->whenLoaded('media', fn () => $this->media
                ->filter(fn ($m) => in_array($m->type, ['cover', 'gallery'], true))
                ->map(fn ($m) => $m->url())
                ->filter()
                ->values()
                ->all()),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->translated('name'),
                'slug' => $this->category->slug,
            ] : null),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($variant) => new StorefrontProductVariantResource($variant, $digitalStock))->values()->all()),
            'option_display' => ProductOptionDisplay::publicPayload($this->resource),
            'personalization_fields' => ProductPersonalization::fields($this->resource),
            'has_personalization' => ! empty($this->personalization_fields),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
