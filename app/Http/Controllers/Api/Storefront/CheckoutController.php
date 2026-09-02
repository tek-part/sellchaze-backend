<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\ApplyCouponRequest;
use App\Http\Requests\Storefront\CheckoutRequest;
use App\Http\Resources\Storefront\CartResource;
use App\Http\Resources\Storefront\StoreOrderResource;
use App\Models\StorePaymentGateway;
use App\Models\StoreOrder;
use App\Models\StorePaymentTransaction;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\CouponService;
use App\Services\Commerce\CustomerAuthService;
use App\Services\Commerce\PricingCalculator;
use App\Services\Commerce\PaymentRetryToken;
use App\Services\Commerce\StorePaymentService;
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
        private readonly StorePaymentService $payments,
        private readonly PaymentRetryToken $retryTokens,
    ) {}

    /** POST /storefront/checkout */
    public function store(CheckoutRequest $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $payment = StorePaymentGateway::query()
            ->where('store_id', $store->id)
            ->where('enabled', true)
            ->when($request->filled('payment_method'), fn ($query) => $query->where('gateway', $request->string('payment_method')->toString()))
            ->orderBy('sort_order')
            ->first();

        if ($payment === null && ! $request->filled('payment_method')) {
            $payment = (new StorePaymentGateway)->forceFill([
                'store_id' => $store->id,
                'gateway' => 'cod',
                'enabled' => true,
                'test_mode' => false,
                'sort_order' => 0,
                'credentials' => [],
            ]);
        }

        if ($payment === null) {
            throw ValidationException::withMessages([
                'payment_method' => 'The selected payment method is not available for this store.',
            ]);
        }

        $customer = $this->auth->resolve($request);
        $cart = $this->carts->resolve($request, $store, $customer);

        // The storefront cart lives on the client; sync the submitted line items into the server
        // cart so checkout places exactly what the shopper sees, without a stateful cart round-trip.
        $items = $request->input('items', []);
        if (! empty($items)) {
            $this->carts->clear($cart);
            foreach ($items as $line) {
                $this->carts->addItem($cart, (int) $line['product_id'], (int) ($line['quantity'] ?? 1));
            }
            $cart->load('items');
        }

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
            $payment->gateway,
        );

        try {
            $paymentResult = $this->payments->start($store, $order, $payment);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Your order was created, but the payment session could not start. Retry payment without placing another order.',
                'errors' => $exception->errors(),
                'data' => new StoreOrderResource($order),
                'payment_retry' => [
                    'token' => $this->retryTokens->make($store->id, $order->id),
                    'expires_in' => 7200,
                ],
            ], 422, [], JSON_UNESCAPED_UNICODE);
        }

        return response()->json([
            'data' => new StoreOrderResource($order),
            'payment' => $paymentResult,
        ], 201, [], JSON_UNESCAPED_UNICODE);
    }

    /** POST /storefront/checkout/payment/retry */
    public function retryPayment(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:1000']]);
        $store = $this->currentStore($request);
        $orderId = $this->retryTokens->verify($data['token'], $store->id);
        if ($orderId === null) {
            throw ValidationException::withMessages(['token' => 'The payment retry link is invalid or expired.']);
        }

        $order = StoreOrder::query()
            ->where('store_id', $store->id)
            ->whereKey($orderId)
            ->with('items')
            ->firstOrFail();
        if ($order->status === 'cancelled') {
            throw ValidationException::withMessages(['payment' => 'This order was cancelled and cannot be paid.']);
        }

        $setting = StorePaymentGateway::query()
            ->where('store_id', $store->id)
            ->where('gateway', $order->payment_method)
            ->where('enabled', true)
            ->first();
        if ($setting === null) {
            throw ValidationException::withMessages(['payment' => 'This payment method is no longer available.']);
        }

        $transaction = StorePaymentTransaction::query()
            ->where('store_id', $store->id)
            ->where('store_order_id', $order->id)
            ->where('gateway', $setting->gateway)
            ->latest('id')
            ->firstOrFail();

        $paymentResult = $this->payments->retry($store, $order, $setting, $transaction);

        return response()->json([
            'data' => new StoreOrderResource($order),
            'payment' => $paymentResult,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** GET /storefront/payment-methods */
    public function paymentMethods(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $names = [
            'cod' => 'Cash on delivery', 'bank_transfer' => 'Bank transfer', 'stripe' => 'Stripe',
            'paypal' => 'PayPal', 'tabby' => 'Tabby', 'tamara' => 'Tamara', 'paymob' => 'Paymob',
            'fawry' => 'Fawry', 'fawaterak' => 'Fawaterak', 'tap' => 'Tap Payments',
            'paytabs' => 'PayTabs', 'hyperpay' => 'HyperPay',
        ];

        $methods = StorePaymentGateway::query()
            ->where('store_id', $store->id)
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->get(['gateway', 'test_mode'])
            ->map(fn (StorePaymentGateway $setting) => [
                'slug' => $setting->gateway,
                'name' => $names[$setting->gateway] ?? str($setting->gateway)->headline()->toString(),
                'test_mode' => $setting->test_mode,
            ]);

        return response()->json(['data' => $methods]);
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
            'totals' => $this->pricing->forStore($store, $subtotal, $discount),
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
            'totals' => $this->pricing->forStore($store, $cart->subtotal()),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
