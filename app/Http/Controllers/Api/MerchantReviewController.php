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
use Illuminate\Support\Carbon;

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
        $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'rating' => ['nullable', 'string', 'regex:/^[1-5](,[1-5]){0,4}$/'],
            'created_from' => ['nullable', 'date'], 'created_to' => ['nullable', 'date'],
            'sort_by' => ['nullable', 'in:created_at,rating,helpful_count'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ]);
        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);

        $paginator = ProductReview::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))
            ->when($request->filled('product_id'), fn ($q) => $q->where('store_product_id', $request->integer('product_id')))
            ->when($request->filled('rating'), fn ($q) => $q->whereIn('rating', array_map('intval', explode(',', (string) $request->get('rating')))))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.trim((string) $request->get('search')).'%';
                $q->where(fn ($w) => $w->where('title', 'like', $term)->orWhere('body', 'like', $term)->orWhere('author_name', 'like', $term)
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $term)));
            })
            ->when($request->filled('created_from'), fn ($q) => $q->where('created_at', '>=', Carbon::parse($request->get('created_from'))->startOfDay()))
            ->when($request->filled('created_to'), fn ($q) => $q->where('created_at', '<=', Carbon::parse($request->get('created_to'))->endOfDay()))
            ->with(['product:id,name,slug', 'customer:id,name'])
            ->orderBy($request->input('sort_by') ?: 'id', $request->input('sort_direction') ?: 'desc')
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

    /** POST /stores/{store}/reviews/bulk-status — moderate up to 100 reviews of this store at once. */
    public function bulkStatus(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['integer', 'distinct'],
            'status' => ['required', 'in:'.implode(',', ProductReview::STATUSES)],
        ]);
        $updated = ProductReview::query()->whereIn('id', $data['ids'])->update(['status' => $data['status']]);
        StoreAnalyticsService::forget($store->id);

        return response()->json(['meta' => ['updated' => $updated]]);
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
