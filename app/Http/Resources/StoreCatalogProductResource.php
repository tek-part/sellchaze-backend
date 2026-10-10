<?php

namespace App\Http\Resources;

use App\Http\Resources\Storefront\StorefrontProductResource;
use App\Services\Commerce\DigitalProducts;
use Illuminate\Http\Request;

/** Merchant-only raw editorial values; costs and all translations never enter public resources. */
class StoreCatalogProductResource extends StorefrontProductResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'name' => $this->name, 'description' => $this->long_description ?? $this->description, 'short_description' => $this->short_description,
            'translations' => $this->translationsPayload(), 'category_id' => $this->category_id,
            'cost' => $this->cost, 'seo_title' => $this->seo_title, 'seo_description' => $this->seo_description,
            'stock_quantity' => $this->stock_quantity, 'reserved_quantity' => $this->reserved_quantity,
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($media) => ['id' => $media->id, 'url' => $media->url(), 'type' => $media->type === 'video' ? 'video' : 'image', 'mime' => $media->mime, 'alt' => $media->alt, 'position' => $media->position])),
            'variants' => StoreCatalogVariantResource::collection($this->whenLoaded('variants')),
            'option_display' => $this->option_display ?? [],
            'personalization_fields' => $this->personalization_fields ?? [],
            'digital_url' => $this->digital_url,
            'digital_codes_available' => app(DigitalProducts::class)->available($this->resource),
            'digital_sales' => $this->digital_type !== 'physical' ? app(DigitalProducts::class)->sales($this->resource) : 0,
        ]);
    }
}
