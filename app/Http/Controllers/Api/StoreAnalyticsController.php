<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\StoreAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 6F: merchant analytics. Bound under `store.scope` (ownership asserted by
 * the middleware/StorePolicy). Thin — all aggregation lives in
 * StoreAnalyticsService.
 */
class StoreAnalyticsController extends Controller
{
    public function __construct(private readonly StoreAnalyticsService $analytics) {}

    /** GET /stores/{store}/analytics/overview */
    public function overview(Request $request, Store $store): JsonResponse
    {
        return response()->json(['data' => $this->analytics->overview($store)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** GET /stores/{store}/analytics/products */
    public function products(Request $request, Store $store): JsonResponse
    {
        return response()->json(['data' => $this->analytics->topProducts($store, $this->limit($request))], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** GET /stores/{store}/analytics/categories */
    public function categories(Request $request, Store $store): JsonResponse
    {
        return response()->json(['data' => $this->analytics->topCategories($store, $this->limit($request))], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** GET /stores/{store}/analytics/customers */
    public function customers(Request $request, Store $store): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        $paginator = $this->analytics->customers($store, $perPage);

        return response()->json([
            'summary' => $this->analytics->customerSummary($store),
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function limit(Request $request): int
    {
        return min(max((int) $request->get('limit', 10), 1), 50);
    }
}
