<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\StorefrontCategoryResource;
use App\Http\Resources\Storefront\StorefrontProductResource;
use App\Services\Storefront\StorefrontService;
use App\Services\Storefront\StoreSeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontCategoryController extends Controller
{
    use ResolvesStorefront;

    public function __construct(
        private readonly StorefrontService $storefront,
        private readonly StoreSeoService $seo,
    ) {}

    /** GET /storefront/categories */
    public function index(Request $request): JsonResponse
    {
        $this->currentStore($request);

        return response()->json([
            'data' => StorefrontCategoryResource::collection($this->storefront->categories()),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** GET /storefront/categories/{slug} */
    public function show(Request $request, string $slug): JsonResponse
    {
        $store = $this->currentStore($request);
        $category = $this->storefront->category($slug);
        abort_unless($category !== null, 404, 'Category not found.');

        $perPage = min(max((int) $request->get('per_page', 24), 1), 60);
        $products = $this->storefront->products($slug, $perPage);

        return response()->json([
            'data' => new StorefrontCategoryResource($category),
            'products' => [
                'data' => StorefrontProductResource::collection($products->getCollection()),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                ],
            ],
            'seo' => $this->seo->forCategory($store, $category),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
