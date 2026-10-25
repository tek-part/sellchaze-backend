<?php

namespace App\Http\Resources;

use App\Models\StoreCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Merchant view of a collection. `type` is manual|automated; the legacy merchandising preset
 * (featured, best-sellers, …) is exposed as `merchandising_type`.
 *
 * @mixin StoreCollection
 */
class StoreCatalogCollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->name,
            'handle' => $this->slug,
            'description' => $this->description,
            'image' => $this->image,
            'image_url' => $this->imageUrl(),
            'type' => $this->is_automated ? 'automated' : 'manual',
            'merchandising_type' => $this->type,
            'rules' => $this->rules ?? [],
            'match' => $this->rules_match ?: 'all',
            'uses_rules' => $this->usesRules(),
            'sort_order' => $this->sort_order ?: 'manual',
            'published' => (bool) $this->is_active,
            'position' => (int) $this->position,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'translations' => $this->translationsPayload(),
            'products_count' => $this->products_count === null ? null : (int) $this->products_count,
            'products' => $this->when($this->resource->relationLoaded('editorProducts'), fn () => $this->resource->getRelation('editorProducts')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
