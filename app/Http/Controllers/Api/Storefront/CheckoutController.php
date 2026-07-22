<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\ApplyCouponRequest;
use App\Http\Requests\Storefront\CheckoutRequest;
use App\Http\Resources\Storefront\CartResource;
use App\Http\Resources\Storefront\StoreOrderResource;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\CouponService;
use App\Services\Commerce\CustomerAuthService;
use App\Services\Commerce\PricingCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5: converts the current cart into an order. Works for guests and for
 * logged-in customers; the CheckoutService re-validates every line fail-closed.
 */
class CheckoutController extends Controller
{
    use ResolvesStorefront;

    public function __construct(
        private readonly CartService $carts,
        private readonly CheckoutService $checkout,
        private readonly CustomerAuthService $auth,
        private readonly CouponService $coupons,
        private readonly PricingCalculator $pricing,
    ) {}

    /** POST /storefront/checkout */
    public function store(CheckoutRequest $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $customer = $this->auth->resolve($request);
        $cart = $this->carts->resolve($request, $store, $customer);

        $order = $this->checkout->place(
            $store,
            $cart,
            $customer,
            [
                'name' => $request->input('customer_name'),
                'email' => $request->input('customer_email'),
                'phone' => $request->input('customer_phone'),
                'notes' => $request->input('notes'),
            ],
            $request->input('shipping_address'),
        );

        return response()->json(['data' => new StoreOrderResource($order)], 201, [], JSON_UNESCAPED_UNICODE);
    }

    /** POST /storefront/checkout/coupon/apply — attach a coupon to the cart. */
    public function applyCoupon(ApplyCouponRequest $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $customer = $this->auth->resolve($request);
        $cart = $this->carts->resolve($request, $store, $customer);

        $coupon = $this->coupons->resolveActive((string) $request->input('code'));
        if ($coupon === null) {
            throw ValidationException::withMessages(['code' => 'Invalid coupon code.']);
        }

        $subtotal = $cart->subtotal();
        $this->coupons->validate($coupon, $customer, $subtotal);

        $cart->update(['coupon_id' => $coupon->id]);
        $discount = $this->coupons->computeDiscount($coupon, $subtotal);

        return response()->json([
            'data' => new CartResource($cart->fresh('items')),
            'coupon' => ['code' => $coupon->code, 'type' => $coupon->type, 'value' => $coupon->value],
            'totals' => $this->pricing->totals($subtotal, $discount),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** DELETE /storefront/checkout/coupon — remove the applied coupon. */
    public function removeCoupon(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $customer = $this->auth->resolve($request);
        $cart = $this->carts->resolve($request, $store, $customer);

        $cart->update(['coupon_id' => null]);

        return response()->json([
            'data' => new CartResource($cart->fresh('items')),
            'totals' => $this->pricing->totals($cart->subtotal()),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
