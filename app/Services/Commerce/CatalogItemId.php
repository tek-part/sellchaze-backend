<?php

namespace App\Services\Commerce;

/** Shared catalog/advertising identifier. Parent IDs remain stable for unvarianted products. */
final class CatalogItemId
{
    public static function selection(int $productId, ?int $variantId = null): string
    {
        return $variantId === null ? (string) $productId : 'p'.$productId.'-v'.$variantId;
    }
}
