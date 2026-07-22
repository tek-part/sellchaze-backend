<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\StoreReviewRequest;
use App\Http\Resources\Storefront\ProductReviewResource;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\StoreCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6: product reviews. Listing is public (approved only, with an average
 * rating summary); create/update/delete require an authenticated store customer
 * and are limited to that customer's own review (one per product).
 */
class ProductReviewController extends Controller
{
    use ResolvesStorefront;

    /** GET /storefront/products/{slug}/reviews (public) */
    public function index(Request $request, string $slug): JsonResponse
    {
        $product = $this->product($slug);
        $perPage = min(max((int) $request->get('per_page', 10), 1), 50);

        $reviews = ProductReview::query()
            ->where('store_product_id', $product->id)
            ->where('status', 'approved')
            ->with('customer:id,name')
            ->orderByDesc('id')
            ->paginate($perPage);

        $approved = ProductReview::query()
            ->where('store_product_id', $product->id)
            ->where('status', 'approved');

        $count = (clone $approved)->count();
        $average = $count > 0 ? round((float) (clone $approved)->avg('rating'), 2) : null;

        return response()->json([
            'data' => ProductReviewResource::collection($reviews->getCollection()),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
            ],
            'summary' => [
                'count' => $count,
                'average' => $average,
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** POST /storefront/products/{slug}/reviews (store.customer) */
    public function store(StoreReviewRequest $request, string $slug): JsonResponse
    {
        $customer = $this->currentCustomer($request);
        $product = $this->product($slug);

        $exists = ProductReview::query()
            ->where('store_customer_id', $customer->id)
            ->where('store_product_id', $product->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'rating' => 'You have already reviewed this product.',
            ]);
        }

        $review = new ProductReview($request->validated());
        $review->store_customer_id = $customer->id;
        $review->store_product_id = $product->id;
        $review->status = 'approved'; // moderation column supports pending/rejected
        $review->save();

        return response()->json(['data' => new ProductReviewResource($review)], 201);
    }

    /** PUT /storefront/products/{slug}/reviews/{review} (store.customer) */
    public function update(StoreReviewRequest $request, string $slug, int $review): JsonResponse
    {
        $customer = $this->currentCustomer($request);
        $model = $this->ownedReview($customer, $review);
        $model->fill($request->validated())->save();

        return response()->json(['data' => new ProductReviewResource($model)]);
    }

    /** DELETE /storefront/products/{slug}/reviews/{review} (store.customer) */
    public function destroy(Request $request, string $slug, int $review): JsonResponse
    {
        $customer = $this->currentCustomer($request);
        $this->ownedReview($customer, $review)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    private function product(string $slug): Product
    {
        $product = Product::query()->where('is_active', true)->where('slug', $slug)->first();
        abort_if($product === null, 404, 'Product not found.');

        return $product;
    }

    private function ownedReview(StoreCustomer $customer, int $id): ProductReview
    {
        $review = ProductReview::query()->where('store_customer_id', $customer->id)->find($id);
        abort_if($review === null, 404, 'Review not found.');

        return $review;
    }
}
