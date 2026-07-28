<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\StorefrontProductResource;
use App\Services\Storefront\StorefrontService;
use App\Services\Storefront\StoreSeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontProductController extends Controller
{
    use ResolvesStorefront;

    public function __construct(
        private readonly StorefrontService $storefront,
        private readonly StoreSeoService $seo,
    ) {}

    /** GET /storefront/products */
    public function index(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $perPage = min(max((int) $request->get('per_page', 24), 1), 60);
        $allowedFilters = ['best_sellers', 'new_arrivals', 'trending', 'on_sale'];
        $filter = in_array($request->query('filter'), $allowedFilters, true) ? $request->query('filter') : null;
        $search = trim((string) $request->query('q')) ?: null;
        $paginator = $this->storefront->products($request->query('category'), $perPage, $filter, $search);

        return response()->json([
            'data' => StorefrontProductResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'seo' => $this->seo->forStore($store),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** GET /storefront/products/{slug} */
    public function show(Request $request, string $slug): JsonResponse
    {
        $store = $this->currentStore($request);
        $product = $this->storefront->product($slug);
        abort_unless($product !== null, 404, 'Product not found.');

        return response()->json([
            'data' => new StorefrontProductResource($product),
            'seo' => $this->seo->forProduct($store, $product),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

}
