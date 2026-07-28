<?php

namespace App\Http\Resources\Storefront;

use App\Models\StoreCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoreCollection
 */
class StorefrontCollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'description' => $this->description,
            'image_url' => $this->imageUrl(),
            'products_count' => (int) ($this->products_count ?? 0),
            // Products included only when eager-loaded (collection detail / homepage rails).
            'products' => StorefrontProductResource::collection($this->whenLoaded('products')),
        ];
    }
}
