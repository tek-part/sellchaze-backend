<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Exceptions\CheckoutTotalChanged;
use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\ApplyCouponRequest;
use App\Http\Requests\Storefront\CheckoutQuoteRequest;
use App\Http\Requests\Storefront\CheckoutRequest;
use App\Http\Resources\Storefront\CartResource;
use App\Http\Resources\Storefront\StoreOrderResource;
use App\Models\Cart;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use App\Services\Commerce\Apps\CountryRestriction;
use App\Services\Commerce\BankTransferInstructions;
use App\Services\Commerce\CampaignAttribution;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CheckoutAttempts;
use App\Services\Commerce\CheckoutBasket;
use App\Services\Commerce\CheckoutBotProtection;
use App\Services\Commerce\CheckoutFields;
use App\Services\Commerce\CheckoutLeads;
use App\Services\Commerce\CheckoutPhoneVerification;
use App\Services\Commerce\CheckoutQuote;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\CouponService;
use App\Services\Commerce\CustomerAuthService;
use App\Services\Commerce\DiscountEngine;
use App\Services\Commerce\GiftCards;
use App\Services\Commerce\HostedPaymentGateways;
use App\Services\Commerce\HostedPaymentReconciliation;
use App\Services\Commerce\OrderLimits;
use App\Services\Commerce\OrderReceipt;
use App\Services\Commerce\PaymentGatewayCatalog;
use App\Services\Commerce\PaymentRetryToken;
use App\Services\Commerce\PricingCalculator;
use App\Services\Commerce\ProductAbTests;
use App\Services\Commerce\ProductPreferences;
use App\Services\Commerce\QuantityOffers;
use App\Services\Commerce\ReferralAttribution;
use App\Services\Commerce\RegionalHostedPayments;
use App\Services\Commerce\StorefrontEvents;
use App\Services\Commerce\StorePaymentService;
use App\Services\Commerce\StoreShipping;
use App\Services\Commerce\StoreTrackingEvents;
use App\Services\Commerce\TapPaymentSettings;
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
            'product_ids' => ['sometimes', 'array', 'max:100'], 'product_ids.*' => ['required', 'integer', 'min:1'],
            'shipping_country' => StoreShipping::SELECTION_RULES['shipping_country'], 'shipping_region_id' => StoreShipping::SELECTION_RULES['shipping_region_id']]);
        $store = $this->currentStore($request);
        $basket = isset($data['product_ids']) ? app(CheckoutBasket::class)->fromIds($store, $data['product_ids'], true) : app(CheckoutBasket::class)->forRequest($store, $request);
        // The address picks the zone, so the storefront refetches this when country or region changes.
        $shipping = $basket['requires_shipping'] ? app(StoreShipping::class)->publicConfiguration($store, ['country' => $data['shipping_country'] ?? null, 'region_id' => $data['shipping_region_id'] ?? null])
            : ['enabled' => false, 'regions_enabled' => false, 'auto_select_region' => false, 'currency' => $store->currency ?: 'USD', 'flat_rate' => '0.00', 'free_over' => null, 'regions' => [], 'options' => [], 'zones_enabled' => false];
        $restriction = app(CountryRestriction::class);
        if ($restriction->enabled($store)) {
            // Regions in a refused country are not offered; placement enforces the same policy.
            $shipping['regions'] = array_values(array_filter($shipping['regions'], fn (array $region) => $restriction->allows($store, (string) ($region['country'] ?? ''))));
        }

        return response()->json(['data' => $fields->effective($store, $data['payment_method'] ?? null, $basket['requires_shipping'], $basket['has_digital'], $basket['requires_whatsapp']),
            'shipping' => $shipping, 'requires_shipping' => $basket['requires_shipping'], 'has_digital' => $basket['has_digital'],
            'phone_verification' => app(CheckoutPhoneVerification::class)->publicConfiguration($store),
            'bot_protection' => app(CheckoutBotProtection::class)->publicConfiguration($store),
            'country_restriction' => $basket['requires_shipping'] ? $restriction->publicConfiguration($store) : null]);
    }

    public function quote(CheckoutQuoteRequest $request, CheckoutQuote $quotes): JsonResponse
    {
        $data = $request->validated();
        $store = $this->currentStore($request);

        return response()->json(['data' => $quotes->calculate(
            $store, $data['items'], $data['coupon_code'] ?? null, $this->auth->resolve($request), $data + ['client_ip' => $request->ip()], $data['gift_cards'] ?? [], $this->quoteContact($store, $data),
        )], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * The guest contact typed so far, normalized like checkout; a value that is not valid yet counts as
     * not given, so a half-typed field never fails the quote.
     *
     * @return array{phone: ?string, email: ?string}|null
     */
    private function quoteContact(Store $store, array $data): ?array
    {
        $limits = app(OrderLimits::class);
        $phone = $limits->normalizePhone($data['customer_phone'] ?? null, $limits->configured($store)['phone_country']);
        $email = filter_var($data['customer_email'] ?? null, FILTER_VALIDATE_EMAIL) === false ? null : (string) $data['customer_email'];

        return $phone === null && $email === null ? null : ['phone' => $phone, 'email' => $email];
    }

    /** POST /storefront/checkout/gift-card/check — masked balance; one generic error for every failure. */
    public function checkGiftCard(Request $request, GiftCards $giftCards): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $store = $this->currentStore($request);
        $card = $giftCards->lookup($store, $data['code']);
        if ($card === null || ! $giftCards->usable($card, $store->currency ?: 'USD')) {
            throw ValidationException::withMessages(['code' => GiftCards::INVALID]);
        }

        return response()->json(['data' => ['last4' => $card->last4, 'balance' => (string) $card->balance, 'currency' => $card->currency,
            'expires_at' => $card->expires_at?->toIso8601String()]], 200, ['Cache-Control' => 'private, no-store']);
    }

    /** POST /storefront/checkout */
    public function store(CheckoutRequest $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $shippingSelection = $request->safe()->only(array_keys(StoreShipping::SELECTION_RULES));
        // The delivery address country is authoritative for zone matching (CheckoutService does the same).
        $addressCountry = $request->validated('shipping_address.country');
        if (is_string($addressCountry) && $addressCountry !== '') {
            $shippingSelection['shipping_country'] = strtoupper($addressCountry);
        }
        $requiresShipping = app(CheckoutBasket::class)->forRequest($store, $request)['requires_shipping'];
        if ($requiresShipping) {
            app(StoreShipping::class)->validateSelection($store, $shippingSelection);
        }
        $payment = StorePaymentGateway::query()
            ->where('store_id', $store->id)
            ->where('enabled', true)
            ->when(! $requiresShipping, fn ($query) => $query->where('gateway', '!=', 'cod'))
            ->when($request->filled('payment_method'), fn ($query) => $query->where('gateway', $request->string('payment_method')->toString()))
            ->orderBy('sort_order')
            ->get()->first(fn (StorePaymentGateway $setting) => PaymentGatewayCatalog::offered($setting, $store->currency));

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

        $giftCards = $request->validated('gift_cards') ?? [];
        // Nothing left to pay needs no gateway; placement rejects this when a balance remains.
        if ($payment === null && $giftCards !== [] && (! $request->filled('payment_method') || $request->input('payment_method') === GiftCards::METHOD)) {
            $payment = (new StorePaymentGateway)->forceFill(['store_id' => $store->id, 'gateway' => GiftCards::METHOD, 'enabled' => true,
                'test_mode' => false, 'sort_order' => 0, 'credentials' => []]);
        }

        if ($payment === null) {
            throw ValidationException::withMessages([
                'payment_method' => 'The selected payment method is not available for this store.',
            ]);
        }
        if (in_array($payment->gateway, HostedPaymentGateways::PROVIDERS, true)) {
            app(RegionalHostedPayments::class)->validate($payment, strtoupper($store->currency));
        }

        $customer = $this->auth->resolve($request);
        // A funnel order has its own transient cart, even for a signed-in customer.
        // Do not merge, replace or convert their ordinary shopping cart.
        try {
            $order = DB::transaction(function () use ($request, $store, $customer, $payment, $shippingSelection, $giftCards) {
                $attempt = app(CheckoutAttempts::class)->lock($request);
                $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
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
                                $this->carts->addItem($cart, (int) $line['product_id'], (int) ($line['quantity'] ?? 1), isset($line['variant_id']) ? (int) $line['variant_id'] : null, $line['personalization'] ?? [],
                                    $line['quantity_offer_id'] ?? null, isset($line['quantity_offer_version']) ? (int) $line['quantity_offer_version'] : null);
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
                        'phone_verification' => ($validated['phone_verification'] ?? null),
                        'bot_proof' => ($validated['bot_proof'] ?? null),
                        'bot_ip' => ($request->ip() ?? ''),
                        'notes' => ($validated['notes'] ?? null),
                        'payment_adjustment_token' => ($validated['payment_adjustment_token'] ?? null),
                        'expected_total' => isset($validated['expected_total']) ? (string) $validated['expected_total'] : null,
                    ],
                    ($validated['shipping_address'] ?? null),
                    $payment->gateway,
                    $shippingSelection,
                    $giftCards,
                );
                $attempt?->update(['store_order_id' => $order->id]);
                app(ProductAbTests::class)->attribute($store, $order, $validated['ab_token'] ?? null);
                if ($order->wasRecentlyCreated && isset($validated['marketing_attribution'])) {
                    $order->update(['marketing_attribution' => app(CampaignAttribution::class)->snapshot($validated['marketing_attribution'])]);
                }
                if ($order->wasRecentlyCreated && isset($validated['referral_attribution'])) {
                    $order->update(['referral_attribution' => app(ReferralAttribution::class)->snapshot($validated['referral_attribution'])]);
                }
                if ($order->wasRecentlyCreated && isset($validated['analytics_session'])) {
                    $order->update(['analytics_session_hash' => app(StorefrontEvents::class)->orderSession($store, $validated['analytics_session'])]);
                }
                app(CheckoutLeads::class)->converted($store, $validated['lead_token'] ?? null, $order);
                if ($order->wasRecentlyCreated) {
                    app(StoreTrackingEvents::class)->purchase($store, $order, $request);
                }

                return $order;
            });
        } catch (CheckoutTotalChanged $changed) {
            // Nothing was placed. Answer with the quote the order would now be placed at.
            return response()->json([
                'message' => __('Your total was updated. Please review it and confirm your order.'),
                'code' => 'total_changed',
                'quote' => app(CheckoutQuote::class)->calculate($store, $changed->items, $changed->couponCode, $customer,
                    $shippingSelection + ['payment_method' => $payment->gateway, 'client_ip' => $request->ip()], $giftCards, $changed->guest),
            ], 409, [], JSON_UNESCAPED_UNICODE);
        }

        if ($order->payment_method === GiftCards::METHOD) {
            return response()->json([
                'data' => new StoreOrderResource($order),
                'receipt' => app(OrderReceipt::class)->make($order),
                'payment' => ['transaction_id' => null, 'status' => 'paid', 'redirect_url' => null],
            ], 201, [], JSON_UNESCAPED_UNICODE);
        }

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

        $transaction = StorePaymentTransaction::query()->where('store_id', $store->id)->where('store_order_id', $order->id)->latest('id')->first();
        if ($transaction && in_array($transaction->gateway, HostedPaymentGateways::PROVIDERS, true)) {
            try {
                app(HostedPaymentReconciliation::class)->refresh($transaction);
                $order->refresh()->load('items');
            } catch (\Throwable) { /* A provider outage must not hide a private receipt. */
            }
        }

        return response()->json(['data' => new StoreOrderResource($order), 'bank_transfer' => BankTransferInstructions::forOrder($order),
            'payment_method' => $transaction?->metadata['presentation'] ?? null,
            'transfer_proofs' => $order->payment_method === 'transfer_receipt' ? DB::table('store_payment_proofs')->where('store_id', $store->id)->where('store_order_id', $order->id)->orderByDesc('created_at')->get(['id', 'status', 'reference', 'review_note', 'created_at']) : []], 200,
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
        if (in_array($order->status, ['cancelled', ...StoreOrder::RETURN_STATUSES], true)) {
            throw ValidationException::withMessages(['payment' => 'This order is cancelled or in a return workflow and cannot accept a new payment.']);
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
        $methods = StorePaymentGateway::query()
            ->where('store_id', $store->id)
            ->where('enabled', true)
            ->when(! $basket['requires_shipping'], fn ($query) => $query->where('gateway', '!=', 'cod'))
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (StorePaymentGateway $setting) => PaymentGatewayCatalog::offered($setting, $store->currency))
            ->map(fn (StorePaymentGateway $setting) => [
                'slug' => $setting->gateway,
                ...PaymentGatewayCatalog::presentation($setting),
                'test_mode' => $setting->gateway === 'deema' ? (bool) TapPaymentSettings::connection($setting)?->test_mode : $setting->gateway !== 'cod' && $setting->test_mode,
            ])->values();

        return response()->json(['data' => $methods]);
    }

    /** POST /storefront/checkout/coupon/apply — attach a coupon to the cart. */
    public function applyCoupon(ApplyCouponRequest $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $shippingSelection = $request->validate(StoreShipping::SELECTION_RULES);
        $requiresShipping = app(CheckoutBasket::class)->forRequest($store, $request)['requires_shipping'];
        if ($requiresShipping) {
            app(StoreShipping::class)->validateSelection($store, $shippingSelection);
        }
        $customer = $this->auth->resolve($request);
        $cart = $this->carts->resolve($request, $store, $customer);

        $coupon = $this->coupons->resolveActive((string) $request->input('code'));
        if ($coupon === null) {
            throw ValidationException::withMessages(['code' => 'Invalid coupon code.']);
        }

        $subtotal = $cart->subtotal();
        $productFreeShipping = ProductPreferences::freeForCart($cart);
        $discounts = app(DiscountEngine::class)->evaluate($store, DiscountEngine::linesFromCart($cart->items), $coupon, $customer);
        $offerFreeShipping = app(QuantityOffers::class)->freeShippingForCart($cart) || $discounts['free_shipping'];

        $cart->update(['coupon_id' => $coupon->id]);

        return response()->json([
            'data' => new CartResource($cart->fresh('items')),
            'coupon' => ['code' => $coupon->code, 'type' => $coupon->type, 'value' => $coupon->value],
            'discounts' => $discounts['applied'],
            'totals' => $this->pricing->forStore($store, $subtotal, $discounts['discount'], $shippingSelection, $requiresShipping, $offerFreeShipping, $productFreeShipping, app(StoreShipping::class)->cartWeightGrams($cart)),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** DELETE /storefront/checkout/coupon — remove the applied coupon. */
    public function removeCoupon(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $shippingSelection = $request->validate(StoreShipping::SELECTION_RULES);
        $requiresShipping = app(CheckoutBasket::class)->forRequest($store, $request)['requires_shipping'];
        if ($requiresShipping) {
            app(StoreShipping::class)->validateSelection($store, $shippingSelection);
        }
        $customer = $this->auth->resolve($request);
        $cart = $this->carts->resolve($request, $store, $customer);

        $productFreeShipping = ProductPreferences::freeForCart($cart);
        $cart->update(['coupon_id' => null]);
        // Automatic discounts still apply without a code.
        $discounts = app(DiscountEngine::class)->evaluate($store, DiscountEngine::linesFromCart($cart->items), null, $customer);
        $offerFreeShipping = app(QuantityOffers::class)->freeShippingForCart($cart) || $discounts['free_shipping'];

        return response()->json([
            'data' => new CartResource($cart->fresh('items')),
            'discounts' => $discounts['applied'],
            'totals' => $this->pricing->forStore($store, $cart->subtotal(), $discounts['discount'], $shippingSelection, $requiresShipping, $offerFreeShipping, $productFreeShipping, app(StoreShipping::class)->cartWeightGrams($cart)),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
