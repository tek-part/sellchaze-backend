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
            'name' => $this->translated('name'),
            'slug' => $this->slug,
            'description' => $this->translated('description'),
            'image' => $this->image,
            'image_url' => $this->imageUrl(),
            'is_active' => $this->is_active,
            'parent_id' => $this->parent_id,
            'is_hidden' => (bool) $this->is_hidden,
            'show_in_header' => (bool) $this->show_in_header,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'children_count' => $this->when(isset($this->children_count), fn () => (int) $this->children_count),
            'position' => $this->position,
            'products_count' => $this->when(isset($this->products_count), fn () => (int) $this->products_count),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
