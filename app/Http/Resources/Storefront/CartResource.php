<?php

namespace App\Http\Resources\Storefront;

use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Cart
 */
class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->items;

        return [
            'id' => $this->id,
            'token' => $this->token,
            'store_id' => $this->store_id,
            'store_customer_id' => $this->store_customer_id,
            'currency' => $this->currency,
            'status' => $this->status,
            'items' => $items->map(fn ($item) => [
                'id' => $item->id,
                'store_product_id' => $item->store_product_id,
                'name' => $item->name,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->lineTotal(),
            ])->values(),
            'item_count' => $this->itemCount(),
            'subtotal' => $this->subtotal(),
        ];
    }
}
