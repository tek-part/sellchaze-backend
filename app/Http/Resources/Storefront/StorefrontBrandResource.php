<?php

namespace App\Http\Resources\Storefront;

use App\Models\StoreBrand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoreBrand
 */
class StorefrontBrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->translated('name'),
            'slug' => $this->slug,
            'description' => $this->translated('description'),
            'logo_url' => $this->logoUrl(),
            'website' => $this->website,
            'origin_country' => $this->origin_country,
            'is_featured' => (bool) $this->is_featured,
            'products_count' => (int) ($this->products_count ?? 0),
        ];
    }
}
