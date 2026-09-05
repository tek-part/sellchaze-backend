<?php

namespace App\Http\Resources\Storefront;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductVariant
 */
class StorefrontProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_product_id' => $this->store_product_id,
            'name' => $this->translated('name'),
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'price_override' => $this->price_override,
            'effective_price' => $this->effectivePrice(),
            // Additive keys the frozen frontend variant mapper reads (price, stock).
            'price' => $this->effectivePrice(),
            'compare_price' => $this->compare_price,
            'stock' => (int) ($this->stock_quantity ?? 0),
            'image' => $this->image,
            'weight' => $this->weight,
            'options' => $this->options,
            'is_active' => $this->is_active,
            'position' => $this->position,
        ];
    }
}
