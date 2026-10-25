<?php

namespace App\Services\StoreCatalog;

use App\Models\Product;
use App\Models\StoreCollection;
use App\Support\Catalog\CollectionRules;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves the products of a collection as a single query: pivot rows (in pivot order) for manual
 * collections, compiled rules for automated ones. Shared by the merchant editor and the storefront.
 */
class StoreCollectionProducts
{
    /**
     * @param  bool  $public  storefront visibility: active and not hidden from catalog listings
     * @return Builder<Product>
     */
    public function query(StoreCollection $collection, bool $public = false): Builder
    {
        return $this->build(
            (int) $collection->store_id,
            $collection->usesRules() ? $collection->rules : null,
            $collection->rules_match ?: 'all',
            $collection->sort_order ?: 'manual',
            $public,
            $collection->usesRules() ? null : (int) $collection->id,
        );
    }

    /**
     * Products matching unsaved rules (the editor's live preview).
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return Builder<Product>
     */
    public function preview(int $storeId, array $rules, string $match, string $sort): Builder
    {
        return $this->build($storeId, $rules, $match, $sort, false, null);
    }

    public function count(StoreCollection $collection, bool $public = false): int
    {
        return $this->query($collection, $public)->reorder()->count('products.id');
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $rules
     * @return Builder<Product>
     */
    private function build(int $storeId, ?array $rules, string $match, string $sort, bool $public, ?int $pivotCollectionId): Builder
    {
        $query = Product::query()->select('products.*')->where('products.store_id', $storeId);
        if ($pivotCollectionId !== null) {
            $query->join('store_collection_product as scp', fn ($join) => $join
                ->on('scp.store_product_id', '=', 'products.id')
                ->where('scp.store_collection_id', $pivotCollectionId))
                ->addSelect('scp.position as collection_position');
        } else {
            CollectionRules::apply($query, $rules ?? [], $match);
        }
        if ($public) {
            $query->where('products.is_active', true)->listed();
        }

        match ($sort) {
            'best_selling' => $query->orderByDesc('products.sales_count'),
            'price_asc' => $query->orderBy('products.price'),
            'price_desc' => $query->orderByDesc('products.price'),
            'newest' => $query->orderByDesc('products.created_at'),
            'title_asc' => $query->orderBy('products.name'),
            'title_desc' => $query->orderByDesc('products.name'),
            default => $pivotCollectionId !== null
                ? $query->orderBy('scp.position')
                : $query->orderByDesc('products.display_priority')->orderBy('products.position'),
        };
        // Stable tie-break keeps pages from overlapping.
        in_array($sort, ['best_selling', 'price_desc', 'newest', 'title_desc'], true)
            ? $query->orderByDesc('products.id')
            : $query->orderBy('products.id');

        return $query;
    }
}
