<?php

namespace App\Http\Resources;

use App\Http\Resources\Storefront\StorefrontProductVariantResource;
use Illuminate\Http\Request;

class StoreCatalogVariantResource extends StorefrontProductVariantResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'name' => $this->name, 'translations' => $this->translationsPayload(), 'cost' => $this->cost,
            'stock_quantity' => $this->stock_quantity, 'reserved_quantity' => $this->reserved_quantity,
            'image_media_id' => $this->image_media_id, 'edit_version' => $this->editVersion(),
        ]);
    }
}
