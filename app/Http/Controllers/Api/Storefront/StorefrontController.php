<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\StorefrontBrandResource;
use App\Http\Resources\Storefront\StorefrontCategoryResource;
use App\Http\Resources\Storefront\StorefrontCollectionResource;
use App\Http\Resources\Storefront\StorefrontCouponResource;
use App\Http\Resources\Storefront\StorefrontProductResource;
use App\Models\Store;
use App\Models\StoreContentPage;
use App\Services\Storefront\StorefrontContextBuilder;
use App\Services\Storefront\StorefrontService;
use App\Services\Storefront\StoreSeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontController extends Controller
{
    use ResolvesStorefront;

    public function __construct(
        private readonly StorefrontService $storefront,
        private readonly StoreSeoService $seo,
        private readonly StorefrontContextBuilder $builder,
    ) {}

    /** GET /storefront — homepage payload + SEO for the resolved store. */
    public function index(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);

        return response()->json([
            'store' => $this->storeSummary($store),
            'seo' => $this->seo->forStore($store),
            'homepage' => $this->storefront->homepage($store),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /storefront/context — the full, presentation-agnostic render context
     * { store, seo, theme, page, data } that the React SSR runtime consumes.
     */
    public function context(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $template = in_array($request->query('template'), ['home', 'product', 'category'], true)
            ? (string) $request->query('template')
            : 'home';
        $params = $request->filled('slug') ? ['slug' => (string) $request->query('slug')] : [];

        $context = $this->builder->build($store, $template, $params);
        abort_if($context === null, 404, 'Not found.');

        return response()->json($context, 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /storefront/home — one consolidated payload for the themed home page: catalogue rows
     * (newest + flag-driven best-sellers/new-arrivals/on-sale/trending), categories, collections,
     * brands, active coupons, and the editable home/about/faq content. Collapses what used to be a
     * dozen separate requests into one, so the home page never trips the read rate limiter.
     */
    public function home(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $row = fn (string $filter) => StorefrontProductResource::collection(
            $this->storefront->products(null, 8, $filter)->getCollection()
        );

        return response()->json([
            'data' => [
                'products' => StorefrontProductResource::collection($this->storefront->products(null, 12)->getCollection()),
                'best_sellers' => $row('best_sellers'),
                'new_arrivals' => $row('new_arrivals'),
                'on_sale' => $row('on_sale'),
                'trending' => $row('trending'),
                'categories' => StorefrontCategoryResource::collection($this->storefront->categories()),
                'collections' => StorefrontCollectionResource::collection($this->storefront->collections(12)),
                'brands' => StorefrontBrandResource::collection($this->storefront->brands()),
                'coupons' => StorefrontCouponResource::collection($this->storefront->coupons()),
                'content' => $this->contentBundle(['home', 'about', 'faq']),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Published content JSON for the given system-page keys, as `{ key: {en,ar}|null }`.
     * StoreScope isolates to the resolved store.
     *
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private function contentBundle(array $keys): array
    {
        $rows = StoreContentPage::query()
            ->whereIn('key', $keys)
            ->where('is_published', true)
            ->get()
            ->keyBy('key');

        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $rows->has($key) ? $rows->get($key)->data : null;
        }

        return $out;
    }

    private function storeSummary(Store $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'currency' => $store->currency,
            'status' => $store->status,
            'logo_url' => $store->logoUrl(),
            'banner_url' => $store->bannerUrl(),
        ];
    }
}
