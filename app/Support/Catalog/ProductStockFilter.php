<?php

namespace App\Support\Catalog;

use Illuminate\Database\Eloquent\Builder;

/** Shared by the products and inventory lists so both answer "low stock" the same way. */
class ProductStockFilter
{
    public const VALUES = ['in_stock', 'out_of_stock', 'low', 'untracked'];

    /**
     * Shopify-style inventory filter. A product with variants is judged by its variants; "available"
     * is on-hand minus reserved, and "low" is at or under the reorder level (5 when none is set).
     */
    public static function apply(Builder $query, string $stock): void
    {
        $available = '(stock_quantity - reserved_quantity)';
        $sellable = fn ($q) => $q->where('track_inventory', false)->orWhereRaw($available.' > 0');
        $variants = fn ($q) => $q->where('is_active', true);
        match ($stock) {
            'untracked' => $query->where('track_inventory', false)->whereDoesntHave('variants', fn ($v) => $variants($v)->where('track_inventory', true)),
            'in_stock' => $query->where(fn ($q) => $q
                ->where(fn ($simple) => $simple->whereDoesntHave('variants', $variants)->where($sellable))
                ->orWhereHas('variants', fn ($v) => $variants($v)->where($sellable))),
            'out_of_stock' => $query->where(fn ($q) => $q
                ->where(fn ($simple) => $simple->whereDoesntHave('variants', $variants)->where('track_inventory', true)->whereRaw($available.' <= 0'))
                ->orWhere(fn ($withVariants) => $withVariants->whereHas('variants', $variants)->whereDoesntHave('variants', fn ($v) => $variants($v)->where($sellable)))),
            'low' => $query->where(fn ($q) => $q
                ->where(fn ($simple) => $simple->whereDoesntHave('variants', $variants)->where('track_inventory', true)
                    ->whereRaw($available.' > 0')->whereRaw($available.' <= COALESCE(NULLIF(reorder_level, 0), 5)'))
                ->orWhereHas('variants', fn ($v) => $variants($v)->where('track_inventory', true)->whereRaw($available.' > 0')->whereRaw($available.' <= 5'))),
            default => null,
        };
    }
}
