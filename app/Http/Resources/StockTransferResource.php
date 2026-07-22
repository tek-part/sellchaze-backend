<?php

namespace App\Http\Resources;

use App\Models\StockTransfer;
use App\Support\ProductImageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockTransfer */
class StockTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_by_id' => $this->created_by_id,
            'from_warehouse' => $this->whenLoaded('fromWarehouse', fn () => (new WarehouseResource($this->fromWarehouse))->toArray($request)),
            'to_warehouse' => $this->whenLoaded('toWarehouse', fn () => (new WarehouseResource($this->toWarehouse))->toArray($request)),
            'lines' => $this->whenLoaded('lines', function () {
                return $this->lines->map(function ($line) {
                    $p = $line->product;
                    $thumb = $p ? ProductImageUrl::thumbUrl($p->image) : null;

                    return [
                        'id' => $line->id,
                        'product_id' => $line->product_id,
                        'qty' => (int) $line->qty,
                        'product' => $p ? [
                            'id' => $p->id,
                            'name' => $p->name,
                            'image_thumb_url' => $thumb,
                        ] : null,
                    ];
                })->values()->all();
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
