<?php

namespace App\Http\Resources\Storefront;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
class StorefrontCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image' => $this->image,
            'image_url' => $this->imageUrl(),
            'is_active' => $this->is_active,
            'position' => $this->position,
            'products_count' => $this->when(isset($this->products_count), fn () => (int) $this->products_count),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
