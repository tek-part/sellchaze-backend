<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Models\Store;
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
