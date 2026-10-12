<?php

namespace App\Support;

use App\Models\Product;

/** Bulk editorial version excludes stock, reservations and variant overrides. */
final class CatalogProductVersion
{
    public static function for(Product $product): string
    {
        return hash('sha256', json_encode([
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'price' => $product->price,
            'is_active' => (bool) $product->is_active,
            'category_id' => $product->category_id === null ? null : (int) $product->category_id,
        ], JSON_THROW_ON_ERROR));
    }
}
