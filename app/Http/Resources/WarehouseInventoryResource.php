<?php

namespace App\Http\Resources;

use App\Models\WarehouseInventory;
use App\Support\ProductImageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WarehouseInventory */
class WarehouseInventoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->product;
        $thumb = $product ? ProductImageUrl::thumbUrl($product->image) : null;

        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'product_id' => $this->product_id,
            'qty' => (int) $this->qty,
            'reserved_qty' => (int) $this->reserved_qty,
            'available_qty' => $this->availableQty(),
            'warehouse' => $this->relationLoaded('warehouse') && $this->warehouse
                ? (new WarehouseResource($this->warehouse))->toArray($request)
                : null,
            'product' => $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'image_thumb_url' => $thumb,
                'category_id' => $product->category_id,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
