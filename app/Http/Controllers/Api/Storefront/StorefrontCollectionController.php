<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\StorefrontCollectionResource;
use App\Http\Resources\Storefront\StorefrontProductResource;
use App\Services\Storefront\StorefrontService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontCollectionController extends Controller
{
    use ResolvesStorefront;

    public function __construct(private readonly StorefrontService $storefront) {}

    /** GET /storefront/collections — the store's merchandising collections. */
    public function index(Request $request): JsonResponse
    {
        $this->currentStore($request);
        $limit = min(max((int) $request->get('limit', 12), 1), 24);

        return response()->json([
            'data' => StorefrontCollectionResource::collection($this->storefront->collections($limit)),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** GET /storefront/collections/{slug} — a collection + its paginated products. */
    public function show(Request $request, string $slug): JsonResponse
    {
        $this->currentStore($request);
        $collection = $this->storefront->collection($slug);
        abort_unless($collection !== null, 404, 'Collection not found.');

        $perPage = min(max((int) $request->get('per_page', 24), 1), 60);
        $products = $this->storefront->collectionProducts($collection, $perPage);

        return response()->json([
            'data' => new StorefrontCollectionResource($collection),
            'products' => [
                'data' => StorefrontProductResource::collection($products->getCollection()),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                ],
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
