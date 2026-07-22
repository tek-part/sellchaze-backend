<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\AddWishlistItemRequest;
use App\Http\Resources\Storefront\WishlistItemResource;
use App\Models\Product;
use App\Models\WishlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6: customer wishlist (store.customer). Store + customer scoped; adding
 * is idempotent (unique per customer+product).
 */
class WishlistController extends Controller
{
    use ResolvesStorefront;

    /** GET /storefront/wishlist */
    public function index(Request $request): JsonResponse
    {
        $customer = $this->currentCustomer($request);

        $items = WishlistItem::query()
            ->where('store_customer_id', $customer->id)
            ->with('product')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => WishlistItemResource::collection($items)]);
    }

    /** POST /storefront/wishlist */
    public function store(AddWishlistItemRequest $request): JsonResponse
    {
        $customer = $this->currentCustomer($request);

        $product = Product::query()
            ->where('is_active', true)
            ->find((int) $request->input('store_product_id'));

        if ($product === null) {
            throw ValidationException::withMessages([
                'store_product_id' => 'This product is not available.',
            ]);
        }

        $item = WishlistItem::query()->firstOrCreate([
            'store_customer_id' => $customer->id,
            'store_product_id' => $product->id,
        ]);

        return response()->json(['data' => new WishlistItemResource($item->load('product'))], 201);
    }

    /** DELETE /storefront/wishlist/{product} */
    public function destroy(Request $request, int $product): JsonResponse
    {
        $customer = $this->currentCustomer($request);

        WishlistItem::query()
            ->where('store_customer_id', $customer->id)
            ->where('store_product_id', $product)
            ->delete();

        return response()->json(['message' => 'Removed.']);
    }
}
