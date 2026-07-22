<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateReviewStatusRequest;
use App\Http\Resources\MerchantReviewResource;
use App\Models\ProductReview;
use App\Models\Store;
use App\Services\Commerce\StoreAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 6H: merchant review moderation. Bound under `store.scope`; every query
 * is StoreScope-isolated to the owner's store. Moderation flips the review
 * status (only `approved` is public / counted) and invalidates the store's
 * cached analytics.
 */
class MerchantReviewController extends Controller
{
    /** GET /stores/{store}/reviews */
    public function index(Request $request, Store $store): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);

        $paginator = ProductReview::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))
            ->with(['product:id,name,slug', 'customer:id,name'])
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => MerchantReviewResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /** PATCH /stores/{store}/reviews/{review}/status */
    public function updateStatus(UpdateReviewStatusRequest $request, Store $store, int $review): JsonResponse
    {
        $model = $this->find($review);
        $model->update(['status' => $request->input('status')]);
        StoreAnalyticsService::forget($store->id);

        return response()->json(['data' => new MerchantReviewResource($model->load(['product:id,name,slug', 'customer:id,name']))]);
    }

    /** DELETE /stores/{store}/reviews/{review} */
    public function destroy(Request $request, Store $store, int $review): JsonResponse
    {
        $this->find($review)->delete();
        StoreAnalyticsService::forget($store->id);

        return response()->json(['message' => 'Deleted.']);
    }

    private function find(int $id): ProductReview
    {
        $review = ProductReview::query()->find($id); // StoreScope-isolated
        abort_if($review === null, 404, 'Review not found.');

        return $review;
    }
}
