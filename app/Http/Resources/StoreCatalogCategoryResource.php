<?php

namespace App\Http\Resources;

use App\Http\Resources\Storefront\StorefrontCategoryResource;
use App\Support\StoreCategoryVersion;
use Illuminate\Http\Request;

class StoreCatalogCategoryResource extends StorefrontCategoryResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'name' => $this->name,
            'description' => $this->description,
            'edit_version' => StoreCategoryVersion::for($this->resource),
        ]);
    }
}
