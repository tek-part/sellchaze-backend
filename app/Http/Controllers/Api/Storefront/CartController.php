<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\AddCartItemRequest;
use App\Http\Requests\Storefront\UpdateCartItemRequest;
use App\Http\Resources\Storefront\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CustomerAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 5: storefront cart. Host-resolved (store-scoped) and guest-friendly —
 * the caller carries an X-Cart-Token header; logged-in customers get their own
 * cart (and any guest cart is merged in). Every query is StoreScope-isolated.
 */
class CartController extends Controller
{
    use ResolvesStorefront;

    public function __construct(
        private readonly CartService $carts,
        private readonly CustomerAuthService $auth,
    ) {}

    /** GET /storefront/cart */
    public function show(Request $request): JsonResponse
    {
        return $this->respond($this->cart($request));
    }

    /** POST /storefront/cart/items */
    public function addItem(AddCartItemRequest $request): JsonResponse
    {
        $cart = $this->cart($request);
        $this->carts->addItem($cart, (int) $request->input('store_product_id'), (int) $request->input('quantity', 1));

        return $this->respond($cart->fresh('items'), 201);
    }

    /** PATCH /storefront/cart/items/{item} */
    public function updateItem(UpdateCartItemRequest $request, int $item): JsonResponse
    {
        $cart = $this->cart($request);
        $this->carts->updateItem($cart, $this->item($item), (int) $request->input('quantity'));

        return $this->respond($cart->fresh('items'));
    }

    /** DELETE /storefront/cart/items/{item} */
    public function removeItem(Request $request, int $item): JsonResponse
    {
        $cart = $this->cart($request);
        $this->carts->removeItem($cart, $this->item($item));

        return $this->respond($cart->fresh('items'));
    }

    /** DELETE /storefront/cart */
    public function clear(Request $request): JsonResponse
    {
        $cart = $this->cart($request);
        $this->carts->clear($cart);

        return $this->respond($cart->fresh('items'));
    }

    private function cart(Request $request): Cart
    {
        return $this->carts->resolve($request, $this->currentStore($request), $this->auth->resolve($request));
    }

    private function item(int $id): CartItem
    {
        $item = CartItem::query()->find($id); // StoreScope-isolated to the current store
        abort_if($item === null, 404, 'Item not found.');

        return $item;
    }

    private function respond(Cart $cart, int $status = 200): JsonResponse
    {
        return response()->json(['data' => new CartResource($cart)], $status, [], JSON_UNESCAPED_UNICODE);
    }
}
