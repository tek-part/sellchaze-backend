<?php

namespace App\Services\Storefront;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreBrand;
use App\Models\StoreCollection;
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
                'featured_collections' => $this->collections(6)->map(fn (StoreCollection $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'image_url' => $c->imageUrl(),
                    'products_count' => (int) ($c->products_count ?? 0),
                ])->all(),
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

    /**
     * Paginated storefront products with optional category, merchandising filter
     * (best_sellers|new_arrivals|trending|on_sale — driven by real product flags),
     * and free-text search over name/short_description/sku.
     */
    public function products(?string $categorySlug, int $perPage, ?string $filter = null, ?string $search = null): LengthAwarePaginator
    {
        $query = Product::query()
            ->where('is_active', true)
            ->when($categorySlug, function ($q) use ($categorySlug) {
                $q->whereHas('category', fn ($c) => $c->where('slug', $categorySlug)->where('is_active', true));
            })
            ->when($filter === 'best_sellers', fn ($q) => $q->where('is_bestseller', true))
            ->when($filter === 'new_arrivals', fn ($q) => $q->where('is_new_arrival', true))
            ->when($filter === 'trending', fn ($q) => $q->where('is_trending', true))
            ->when($filter === 'on_sale', fn ($q) => $q->where(function ($w) {
                $w->whereColumn('compare_price', '>', 'price')
                    ->orWhere('discount_percent', '>', 0);
            }))
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)
                    ->orWhere('short_description', 'like', $like)
                    ->orWhere('sku', 'like', $like));
            })
            ->with('category:id,name,slug');

        // Merchandising rows sort by their signal; everything else by curated position.
        match ($filter) {
            'best_sellers' => $query->orderByDesc('sales_count')->orderByDesc('id'),
            'new_arrivals' => $query->orderByDesc('published_at')->orderByDesc('id'),
            'trending' => $query->orderByDesc('views_count')->orderByDesc('id'),
            'on_sale' => $query->orderByDesc('discount_percent')->orderByDesc('id'),
            default => $query->orderBy('position')->orderByDesc('id'),
        };

        return $query->paginate($perPage);
    }

    /** @return Collection<int, StoreCollection> */
    public function collections(int $limit = 12): Collection
    {
        return StoreCollection::query()
            ->where('is_active', true)
            ->withCount('products')
            ->orderBy('position')->orderBy('id')
            ->limit($limit)->get();
    }

    public function collection(string $slug): ?StoreCollection
    {
        return StoreCollection::query()
            ->where('is_active', true)
            ->where('slug', $slug)
            ->first();
    }

    public function collectionProducts(StoreCollection $collection, int $perPage): LengthAwarePaginator
    {
        return $collection->products()
            ->where('is_active', true)
            ->with('category:id,name,slug')
            ->paginate($perPage);
    }

    /** @return Collection<int, StoreBrand> */
    public function brands(int $limit = 24): Collection
    {
        return StoreBrand::query()
            ->where('is_active', true)
            ->withCount('products')
            ->orderByDesc('is_featured')->orderBy('position')->orderBy('name')
            ->limit($limit)->get();
    }

    /** Active, currently-valid coupons — a public-safe subset for "current offers" strips. */
    public function coupons(int $limit = 8): Collection
    {
        $now = now();

        return Coupon::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
            ->orderByDesc('id')
            ->limit($limit)->get();
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
