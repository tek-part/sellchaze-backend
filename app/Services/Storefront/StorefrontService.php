<?php

namespace App\Services\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/**
 * Read-side storefront data. Assumes the CurrentStore tenant is already set
 * (StoreScope isolates every query), so queries never span stores. Homepage
 * data is cached; catalog changes invalidate it (see StoreCatalog services).
 */
class StorefrontService
{
    public static function homepageCacheKey(int $storeId): string
    {
        return "storefront:{$storeId}:homepage";
    }

    public static function forgetHomepage(int $storeId): void
    {
        Cache::forget(self::homepageCacheKey($storeId));
    }

    public function homepage(Store $store): array
    {
        $ttl = (int) config('sellchase.storefront.homepage_cache_ttl', 60);

        return Cache::remember(self::homepageCacheKey($store->id), $ttl, function () use ($store) {
            $featured = Product::query()
                ->where('is_active', true)
                ->where('is_featured', true)
                ->with('category:id,name,slug')
                ->orderBy('position')->orderByDesc('id')
                ->limit(8)->get();

            // Fall back to newest active products when nothing is featured yet.
            if ($featured->isEmpty()) {
                $featured = Product::query()
                    ->where('is_active', true)
                    ->with('category:id,name,slug')
                    ->orderByDesc('id')->limit(8)->get();
            }

            $categories = Category::query()
                ->where('is_active', true)
                ->withCount(['products' => fn ($q) => $q->where('is_active', true)])
                ->orderBy('position')->orderBy('name')
                ->limit(12)->get();

            return [
                'hero' => [
                    'title' => $store->name,
                    'tagline' => $store->description,
                    'logo_url' => $store->logoUrl(),
                    'banner_url' => $store->bannerUrl(),
                ],
                'featured_products' => $featured->map(fn (Product $p) => $this->productArray($p))->all(),
                'categories' => $categories->map(fn (Category $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'image_url' => $c->imageUrl(),
                    'products_count' => (int) $c->products_count,
                ])->all(),
                'featured_collections' => [], // placeholder (Phase 4+)
                'store_info' => [
                    'name' => $store->name,
                    'email' => $store->email,
                    'phone' => $store->phone,
                    'currency' => $store->currency,
                    'status' => $store->status,
                ],
            ];
        });
    }

    public function products(?string $categorySlug, int $perPage): LengthAwarePaginator
    {
        return Product::query()
            ->where('is_active', true)
            ->when($categorySlug, function ($q) use ($categorySlug) {
                $q->whereHas('category', fn ($c) => $c->where('slug', $categorySlug)->where('is_active', true));
            })
            ->with('category:id,name,slug')
            ->orderBy('position')->orderByDesc('id')
            ->paginate($perPage);
    }

    public function product(string $slug): ?Product
    {
        return Product::query()
            ->where('is_active', true)
            ->where('slug', $slug)
            ->with(['category:id,name,slug', 'brand:id,name', 'variants', 'media'])
            ->first();
    }

    /** @return Collection<int, Category> */
    public function categories(): Collection
    {
        return Category::query()
            ->where('is_active', true)
            ->withCount(['products' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('position')->orderBy('name')
            ->get();
    }

    public function category(string $slug): ?Category
    {
        return Category::query()
            ->where('is_active', true)
            ->where('slug', $slug)
            ->first();
    }

    public function productArray(Product $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'price' => $p->price,
            'compare_price' => $p->compare_price,
            'short_description' => $p->short_description,
            'image_url' => $p->imageUrl(),
            'is_featured' => $p->is_featured,
            'category' => $p->relationLoaded('category') && $p->category ? [
                'id' => $p->category->id,
                'name' => $p->category->name,
                'slug' => $p->category->slug,
            ] : null,
        ];
    }
}
