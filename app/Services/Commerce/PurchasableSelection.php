<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Validation\ValidationException;

/** Resolve a live, tenant-owned product selection for cart and order pricing. */
class PurchasableSelection
{
    /** @return array{product:Product,variant:?ProductVariant,price:string,name:string} */
    public function resolve(Store $store, int $productId, ?int $variantId = null, bool $lock = false): array
    {
        $product = Product::query()->where('store_id', $store->id)->where('is_active', true)->when($lock, fn ($query) => $query->lockForUpdate())->find($productId);
        if ($product === null) {
            throw ValidationException::withMessages(['items' => 'This product is not available.']);
        }
        $variant = null;
        if ($variantId !== null) {
            $variant = ProductVariant::query()->where('store_id', $store->id)
                ->where('store_product_id', $product->id)->where('is_active', true)->when($lock, fn ($query) => $query->lockForUpdate())->find($variantId);
            if ($variant === null) {
                throw ValidationException::withMessages(['items' => 'This product option is not available.']);
            }
        } elseif (ProductVariant::query()->where('store_id', $store->id)->where('store_product_id', $product->id)->exists()) {
            throw ValidationException::withMessages(['items' => 'Choose a product option before ordering.']);
        }

        return [
            'product' => $product,
            'variant' => $variant,
            'price' => (string) ($variant?->price_override ?? $product->price),
            'name' => $variant ? $product->name.' — '.$variant->name : $product->name,
        ];
    }
}
