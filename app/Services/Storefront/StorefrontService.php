<?php

namespace App\Services\Storefront;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreBrand;
use App\Models\StoreCollection;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocalizedValue;
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
    /** Homepage payloads are cached per store *and* locale (names/labels are translated). */
    public static function homepageCacheKey(int $storeId, ?string $locale = null): string
    {
        $locale = strtolower(trim((string) $locale));

        return $locale === '' ? "storefront:{$storeId}:homepage" : "storefront:{$storeId}:homepage:{$locale}";
    }

    /** Forget every locale variant (the store's supported locales + platform locales + the legacy key). */
    public static function forgetHomepage(int $storeId): void
    {
        $locales = LocalizedValue::PLATFORM_LOCALES;
        $store = Store::query()->whereKey($storeId)->first(['id', 'default_locale', 'supported_locales']);
        if ($store) {
            $locales = array_merge($locales, LocaleContext::storeSupported($store));
        }
        Cache::forget(self::homepageCacheKey($storeId));
        foreach (array_unique($locales) as $locale) {
            Cache::forget(self::homepageCacheKey($storeId, $locale));
        }
    }

    /** The locale storefront reads resolve against: the request locale, else the store default. */
    private function locale(Store $store): string
    {
        $context = app(LocaleContext::class);

        return $context->has() ? $context->current() : LocaleContext::storeDefault($store);
    }

    public function homepage(Store $store): array
    {
        $ttl = (int) config('sellchase.storefront.homepage_cache_ttl', 60);
        $locale = $this->locale($store);

        return Cache::remember(self::homepageCacheKey($store->id, $locale), $ttl, function () use ($store, $locale) {
            $featured = Product::query()
                ->where('is_active', true)
                ->where('is_featured', true)
                ->with('category:id,name,name_en,name_ar,slug,translations')
                ->orderBy('position')->orderByDesc('id')
                ->limit(8)->get();

            // Fall back to newest active products when nothing is featured yet.
            if ($featured->isEmpty()) {
                $featured = Product::query()
                    ->where('is_active', true)
                    ->with('category:id,name,name_en,name_ar,slug,translations')
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
                'featured_products' => $featured->map(fn (Product $p) => $this->productArray($p, $locale))->all(),
                'categories' => $categories->map(fn (Category $c) => [
                    'id' => $c->id,
                    'name' => $c->translated('name', $locale),
                    'slug' => $c->slug,
                    'image_url' => $c->imageUrl(),
                    'products_count' => (int) $c->products_count,
                ])->all(),
                'featured_collections' => $this->collections(6)->map(fn (StoreCollection $c) => [
                    'id' => $c->id,
                    'name' => $c->translated('name', $locale),
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
            ->with('category:id,name,name_en,name_ar,slug,translations');

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
            ->with('category:id,name,name_en,name_ar,slug,translations')
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
            ->with(['category:id,name,name_en,name_ar,slug,translations', 'brand:id,name,translations', 'variants', 'media'])
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

    /** Card-sized product payload with names resolved for `$locale` (request locale by default). */
    public function productArray(Product $p, ?string $locale = null): array
    {
        return [
            'id' => $p->id,
            'name' => $p->translated('name', $locale),
            'slug' => $p->slug,
            'price' => $p->price,
            'compare_price' => $p->compare_price,
            'short_description' => $p->translated('short_description', $locale),
            'image_url' => $p->imageUrl(),
            'is_featured' => $p->is_featured,
            'category' => $p->relationLoaded('category') && $p->category ? [
                'id' => $p->category->id,
                'name' => $p->category->translated('name', $locale),
                'slug' => $p->category->slug,
            ] : null,
        ];
    }
}
