<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\ApplyCouponRequest;
use App\Http\Requests\Storefront\CheckoutQuoteRequest;
use App\Http\Requests\Storefront\CheckoutRequest;
use App\Http\Resources\Storefront\CartResource;
use App\Http\Resources\Storefront\StoreOrderResource;
use App\Models\Cart;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use App\Services\Commerce\BankTransferInstructions;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CheckoutAttempts;
use App\Services\Commerce\CheckoutBasket;
use App\Services\Commerce\CheckoutFields;
use App\Services\Commerce\CheckoutQuote;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\CouponService;
use App\Services\Commerce\CustomerAuthService;
use App\Services\Commerce\OrderReceipt;
use App\Services\Commerce\PaymentRetryToken;
use App\Services\Commerce\PricingCalculator;
use App\Services\Commerce\StorePaymentService;
use App\Services\Commerce\StoreShipping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function fields(Request $request, CheckoutFields $fields): JsonResponse
    {
        $data = $request->validate(['payment_method' => ['nullable', 'string', 'max:80'],
            'product_ids' => ['sometimes', 'array', 'max:100'], 'product_ids.*' => ['required', 'integer', 'min:1']]);
        $store = $this->currentStore($request);
        $basket = isset($data['product_ids']) ? app(CheckoutBasket::class)->fromIds($store, $data['product_ids'], true) : app(CheckoutBasket::class)->forRequest($store, $request);
        $shipping = $basket['requires_shipping'] ? app(StoreShipping::class)->publicConfiguration($store)
            : ['enabled' => false, 'regions_enabled' => false, 'auto_select_region' => false, 'currency' => $store->currency ?: 'USD', 'flat_rate' => '0.00', 'free_over' => null, 'regions' => [], 'options' => []];

        return response()->json(['data' => $fields->effective($store, $data['payment_method'] ?? null, $basket['requires_shipping'], $basket['has_digital']),
            'shipping' => $shipping, 'requires_shipping' => $basket['requires_shipping'], 'has_digital' => $basket['has_digital']]);
    }

    public function quote(CheckoutQuoteRequest $request, CheckoutQuote $quotes): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $quotes->calculate(
            $this->currentStore($request), $data['items'], $data['coupon_code'] ?? null, $this->auth->resolve($request), $data,
        )], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** POST /storefront/checkout */
    public function store(CheckoutRequest $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $shippingSelection = $request->safe()->only(array_keys(StoreShipping::SELECTION_RULES));
        $requiresShipping = app(CheckoutBasket::class)->forRequest($store, $request)['requires_shipping'];
        if ($requiresShipping) {
            app(StoreShipping::class)->quote($store, '0.00', $shippingSelection);
        }
        $payment = StorePaymentGateway::query()
            ->where('store_id', $store->id)
            ->where('enabled', true)
            ->when(! $requiresShipping, fn ($query) => $query->where('gateway', '!=', 'cod'))
            ->when($request->filled('payment_method'), fn ($query) => $query->where('gateway', $request->string('payment_method')->toString()))
            ->orderBy('sort_order')
            ->first();

        if ($payment === null && ! $request->filled('payment_method') && $requiresShipping) {
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
        // A funnel order has its own transient cart, even for a signed-in customer.
        // Do not merge, replace or convert their ordinary shopping cart.
        $order = DB::transaction(function () use ($request, $store, $customer, $payment, $shippingSelection) {
            $attempt = app(CheckoutAttempts::class)->lock($request);
            $cart = $request->input('cart_mode') === 'direct'
                ? $this->carts->create($store, null)
                : $this->carts->resolve($request, $store, $customer);

            // The storefront cart lives on the client; sync the submitted line items into the server
            // cart so checkout places exactly what the shopper sees, without a stateful cart round-trip.
            $items = $request->input('items', []);
            if (! empty($items) || $request->has('coupon_code')) {
                DB::transaction(function () use ($cart, $items, $request, $customer) {
                    Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
                    if (! empty($items)) {
                        $this->carts->clear($cart);
                        foreach ($items as $line) {
                            $this->carts->addItem($cart, (int) $line['product_id'], (int) ($line['quantity'] ?? 1), isset($line['variant_id']) ? (int) $line['variant_id'] : null, $line['personalization'] ?? []);
                        }
                    }
                    if ($request->has('coupon_code')) {
                        $coupon = $request->filled('coupon_code') ? $this->coupons->resolveActive($request->string('coupon_code')->toString()) : null;
                        if ($request->filled('coupon_code') && $coupon === null) {
                            throw ValidationException::withMessages(['coupon_code' => 'This coupon is not available.']);
                        }
                        if ($coupon !== null) {
                            $this->coupons->validate($coupon, $customer, $cart->load('items')->subtotal());
                        }
                        $cart->update(['coupon_id' => $coupon?->id]);
                    }
                });
                $cart->load('items');
            }

            $validated = $request->validated();

            $order = $this->checkout->place(
                $store,
                $cart,
                $customer,
                [
                    'name' => ($validated['customer_name'] ?? ''),
                    'email' => ($validated['customer_email'] ?? null),
                    'phone' => ($validated['customer_phone'] ?? null),
                    'notes' => ($validated['notes'] ?? null),
                ],
                ($validated['shipping_address'] ?? null),
                $payment->gateway,
                $shippingSelection,
            );
            $attempt?->update(['store_order_id' => $order->id]);

            return $order;
        });

        try {
            $paymentResult = $this->payments->start($store, $order, $payment);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Your order was created, but the payment session could not start. Retry payment without placing another order.',
                'errors' => $exception->errors(),
                'data' => new StoreOrderResource($order),
                'receipt' => app(OrderReceipt::class)->make($order),
                'payment_retry' => [
                    'token' => $this->retryTokens->make($store->id, $order->id),
                    'expires_in' => 7200,
                ],
            ], 422, [], JSON_UNESCAPED_UNICODE);
        }

        return response()->json([
            'data' => new StoreOrderResource($order),
            'receipt' => app(OrderReceipt::class)->make($order),
            'payment' => $paymentResult,
        ], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function receipt(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:1000']]);
        $store = $this->currentStore($request);
        $id = app(OrderReceipt::class)->verify($data['token'], $store->id);
        abort_unless($id, 404, 'This private receipt is invalid or expired.');
        $order = StoreOrder::query()->where('store_id', $store->id)->whereKey($id)->with('items')->firstOrFail();

        return response()->json(['data' => new StoreOrderResource($order), 'bank_transfer' => BankTransferInstructions::forOrder($order)], 200,
            ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    /** POST /storefront/checkout/recover */
    public function recover(Request $request, CheckoutAttempts $attempts): JsonResponse
    {
        return $attempts->recover($request);
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
            if ($order->payment_method === 'cod') {
                $setting = (new StorePaymentGateway)->forceFill(['store_id' => $store->id, 'gateway' => 'cod', 'enabled' => true, 'credentials' => []]);
            }
        }
        if ($setting === null) {
            throw ValidationException::withMessages(['payment' => 'This payment method is no longer available.']);
        }

        $transaction = StorePaymentTransaction::query()
            ->where('store_id', $store->id)
            ->where('store_order_id', $order->id)
            ->where('gateway', $setting->gateway)
            ->latest('id')
            ->first();

        $paymentResult = $transaction && $transaction->status !== 'created'
            ? $this->payments->retry($store, $order, $setting, $transaction)
            : $this->payments->start($store, $order, $setting);

        return response()->json([
            'data' => new StoreOrderResource($order),
            'receipt' => app(OrderReceipt::class)->make($order),
            'payment' => $paymentResult,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** GET /storefront/payment-methods */
    public function paymentMethods(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $data = $request->validate(['product_ids' => ['sometimes', 'array', 'max:100'], 'product_ids.*' => ['required', 'integer', 'min:1']]);
        $basket = isset($data['product_ids']) ? app(CheckoutBasket::class)->fromIds($store, $data['product_ids'], true) : app(CheckoutBasket::class)->forRequest($store, $request);
        $names = [
            'cod' => 'Cash on delivery', 'bank_transfer' => 'Bank transfer', 'stripe' => 'Stripe',
            'paypal' => 'PayPal', 'tabby' => 'Tabby', 'tamara' => 'Tamara', 'paymob' => 'Paymob',
            'fawry' => 'Fawry', 'fawaterak' => 'Fawaterak', 'tap' => 'Tap Payments',
            'paytabs' => 'PayTabs', 'hyperpay' => 'HyperPay',
        ];

        $methods = StorePaymentGateway::query()
            ->where('store_id', $store->id)
            ->where('enabled', true)
            ->when(! $basket['requires_shipping'], fn ($query) => $query->where('gateway', '!=', 'cod'))
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
        $shippingSelection = $request->validate(StoreShipping::SELECTION_RULES);
        $requiresShipping = app(CheckoutBasket::class)->forRequest($store, $request)['requires_shipping'];
        if ($requiresShipping) {
            app(StoreShipping::class)->quote($store, '0.00', $shippingSelection);
        }
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
            'totals' => $this->pricing->forStore($store, $subtotal, $discount, $shippingSelection, $requiresShipping),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** DELETE /storefront/checkout/coupon — remove the applied coupon. */
    public function removeCoupon(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $shippingSelection = $request->validate(StoreShipping::SELECTION_RULES);
        $requiresShipping = app(CheckoutBasket::class)->forRequest($store, $request)['requires_shipping'];
        if ($requiresShipping) {
            app(StoreShipping::class)->quote($store, '0.00', $shippingSelection);
        }
        $customer = $this->auth->resolve($request);
        $cart = $this->carts->resolve($request, $store, $customer);

        $cart->update(['coupon_id' => null]);

        return response()->json([
            'data' => new CartResource($cart->fresh('items')),
            'totals' => $this->pricing->forStore($store, $cart->subtotal(), '0.00', $shippingSelection, $requiresShipping),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
