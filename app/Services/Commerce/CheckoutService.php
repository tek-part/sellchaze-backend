<?php

namespace App\Services\Commerce;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreOrder;
use App\Services\Commerce\Apps\CountryRestriction;
use App\Services\Commerce\Apps\OrderProtection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5 checkout. Converts an active cart into an immutable StoreOrder inside
 * a single transaction. Every line is re-validated against the live product
 * (existence, active state, price) — fail-closed — before the order is written,
 * and all customer/line data is snapshotted so the order never mutates later.
 */
class CheckoutService
{
    public function __construct(
        private readonly StoreOrderService $orders,
        private readonly CouponService $coupons,
        private readonly PricingCalculator $pricing,
        private readonly PurchasableSelection $selections,
        private readonly StoreInventory $inventory,
    ) {}

    /**
     * @param  array{name:string,email:string|null,phone?:string|null,notes?:string|null}  $contact
     * @param  array<string,mixed>|null  $shippingAddress
     * @param  array<int, mixed>  $giftCards  codes; when they cover the total the order is paid by gift card
     */
    public function place(Store $store, Cart $cart, ?StoreCustomer $customer, array $contact, ?array $shippingAddress, string $paymentMethod, array $shippingSelection = [], array $giftCards = []): StoreOrder
    {
        return DB::transaction(function () use ($store, $cart, $customer, $contact, $shippingAddress, $paymentMethod, $shippingSelection, $giftCards) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $botProof = app(CheckoutBotProtection::class)->consume($store, $contact['bot_proof'] ?? null, $contact['bot_ip'] ?? null);
            $phone = app(OrderLimits::class)->assertPhone($store, $contact['phone'] ?? null);
            $phoneProof = app(CheckoutPhoneVerification::class)->consume($store, $phone, $contact['phone_verification'] ?? null);
            app(CountryRestriction::class)->assertVisitor($store, $contact['bot_ip'] ?? null);
            $cart = Cart::query()->where('store_id', $store->id)->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            if ($cart->status !== 'active') {
                throw ValidationException::withMessages(['cart' => 'This cart is no longer active.']);
            }
            $items = $cart->items()->orderBy('store_product_id')->orderBy('variant_id')->orderBy('id')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
            }
            $subtotal = '0.00';
            $lines = [];
            $stocks = [];
            $counts = [];
            $productCounts = [];
            $requiresShipping = false;
            $offerFreeShipping = false;
            $allPhysicalFree = true;
            $freeProductIds = [];
            $hasDigital = false;
            $requiresWhatsapp = false;
            $weightGrams = 0;

            foreach ($items as $item) {
                $selection = $this->selections->resolve($store, (int) $item->store_product_id, $item->variant_id === null ? null : (int) $item->variant_id, true,
                    $item->quantity_offer['id'] ?? null, (int) $item->quantity, $item->quantity_offer['version'] ?? null);
                $product = $selection['product'];
                $productCounts[$product->id] = ($productCounts[$product->id] ?? 0) + $item->quantity;
                app(OrderLimits::class)->assertQuantity($store, $productCounts[$product->id]);
                $requiresShipping = $requiresShipping || $product->digital_type === 'physical';
                if ($product->digital_type === 'physical') {
                    $free = ProductPreferences::flag($product, 'is_free_shipping');
                    $allPhysicalFree = $allPhysicalFree && $free;
                    if ($free) {
                        $freeProductIds[] = $product->id;
                    }
                }
                $offerFreeShipping = $offerFreeShipping || ($product->digital_type === 'physical' && ($selection['offer']['free_shipping'] ?? false));
                $hasDigital = $hasDigital || $product->digital_type !== 'physical';
                $deliverySettings = app(DigitalDeliverySettings::class)->effective($store, (int) $product->id);
                $requiresWhatsapp = $requiresWhatsapp || ($product->digital_type !== 'physical' && $deliverySettings['enabled'] && $deliverySettings['whatsapp_enabled']);
                $variant = $selection['variant'];
                $custom = app(ProductPersonalization::class)->resolve($product, $item->personalization ?? [], true);
                $stocks[] = $variant ?? $product;
                $stockKey = $product->id.':'.($variant?->id ?? 'base');
                $counts[$stockKey] = ($counts[$stockKey] ?? 0) + $item->quantity;
                $this->inventory->assertAvailable($variant ?? $product, $counts[$stockKey]);
                // Price validation: the snapshot must still match the live price.
                if (bccomp($selection['price'], (string) $item->unit_price, 2) !== 0) {
                    throw ValidationException::withMessages([
                        'items' => "The price of \"{$product->name}\" changed. Please review your cart.",
                    ]);
                }

                $weightGrams += StoreShipping::lineWeightGrams($product, $variant, (int) $item->quantity);
                $lineTotal = bcmul($selection['price'], (string) $item->quantity, 2);
                $subtotal = bcadd($subtotal, $lineTotal, 2);
                $lines[] = [
                    'store_product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'variant_name' => $variant?->name,
                    'variant_options' => $variant?->options,
                    'sku' => $variant?->sku ?? $product->sku,
                    'personalization' => $custom['snapshot'] ?: null,
                    'quantity_offer' => $selection['offer'],
                    'name' => $selection['name'],
                    'unit_price' => $selection['price'],
                    'unit_cost' => $variant?->cost ?? $product->cost,
                    'quantity' => $item->quantity,
                    'line_total' => $lineTotal,
                ];
            }

            // Coupon: re-validated fail-closed at order time (it may have expired
            // or hit its limit since it was applied to the cart). lockForUpdate
            // serializes concurrent redemptions of the same coupon so the
            // usage-count check + insert is atomic — max_uses cannot be exceeded
            // under concurrent checkouts.
            $coupon = $cart->coupon_id ? Coupon::query()->lockForUpdate()->find($cart->coupon_id) : null;
            $discounts = app(DiscountEngine::class)->evaluate($store,
                array_map(fn (array $line) => ['product_id' => (int) $line['store_product_id'], 'quantity' => (int) $line['quantity'], 'unit_price' => (string) $line['unit_price']], $lines),
                $coupon, $customer, ['phone' => $phone, 'email' => $contact['email'] ?? null], true);
            $discount = $discounts['discount'];
            $offerFreeShipping = $offerFreeShipping || $discounts['free_shipping'];

            if ($hasDigital && (! is_string($contact['email']) || ! filter_var($contact['email'], FILTER_VALIDATE_EMAIL))) {
                throw ValidationException::withMessages(['customer_email' => 'A valid email address is required for digital delivery.']);
            }
            if ($requiresWhatsapp && StoreDigitalWhatsappClient::phone((string) ($contact['phone'] ?? '')) === null) {
                throw ValidationException::withMessages(['customer_phone' => 'Enter a valid international WhatsApp number with its country code.']);
            }
            if (! $requiresShipping && $paymentMethod === 'cod') {
                throw ValidationException::withMessages(['payment_method' => 'Cash on delivery is not available for an entirely digital order.']);
            }
            // Recheck delivery fields after locking the authoritative cart. A
            // concurrent cart edit must not turn a digital form into an addressless parcel.
            if ($requiresShipping) {
                $addressRules = array_filter(app(CheckoutFields::class)->rules($store, $paymentMethod, true, $hasDigital),
                    fn (string $key) => str_starts_with($key, 'shipping_address'), ARRAY_FILTER_USE_KEY);
                Validator::make(['shipping_address' => $shippingAddress], $addressRules)->validate();
            }
            app(OrderLimits::class)->assertMinimum($store, $subtotal, $discount);
            $productFreeShipping = $requiresShipping && $allPhysicalFree;
            // The submitted address is authoritative for zones and tax overrides; the payment
            // method decides a zone rate's cash-on-delivery fee.
            if (is_string($shippingAddress['country'] ?? null) && $shippingAddress['country'] !== '') {
                $shippingSelection['shipping_country'] = strtoupper($shippingAddress['country']);
            }
            // Gift cards pay after discounts, delivery and tax. Rows stay locked until the order commits,
            // so two checkouts with the same card serialize and the second sees the reduced balance.
            // Cards that cover the order without a method's fee (e.g. a zone's COD fee) pay it all.
            $currency = $cart->currency ?: ($store->currency ?: 'USD');
            $giftCardService = app(GiftCards::class);
            $cards = $giftCards === [] ? [] : $giftCardService->resolve($store, $giftCards, $currency, true);
            $tender = null;
            $paymentStatus = 'pending';
            if ($cards !== []) {
                $uncharged = $this->pricing->forStore($store, $subtotal, $discount, ['payment_method' => GiftCards::METHOD] + $shippingSelection, $requiresShipping, $offerFreeShipping, $productFreeShipping, $weightGrams);
                if (bccomp($uncharged['grand_total'], '0', 2) <= 0) {
                    // Nothing is due: the cards are not used, and no payment method is needed.
                    $cards = [];
                    if ($paymentMethod === GiftCards::METHOD) {
                        $paymentStatus = 'paid';
                    }
                } else {
                    $tender = $giftCardService->allocate($cards, $uncharged['grand_total']);
                    if (bccomp($tender['amount_due'], '0', 2) === 0) {
                        $paymentMethod = GiftCards::METHOD;
                        $paymentStatus = 'paid';
                    }
                }
            }
            if ($paymentStatus !== 'paid' && $paymentMethod === GiftCards::METHOD) {
                throw ValidationException::withMessages(['payment_method' => 'Choose a payment method for the amount left after gift cards.']);
            }
            $shippingSelection['payment_method'] = $paymentMethod;
            $totals = $this->pricing->forStore($store, $subtotal, $discount, $shippingSelection, $requiresShipping, $offerFreeShipping, $productFreeShipping, $weightGrams);
            if ($cards !== [] && $paymentStatus !== 'paid') {
                $tender = $giftCardService->allocate($cards, $totals['grand_total']);
            }
            // A payment method's adjustment applies to what that method collects.
            $adjustments = app(PaymentPriceAdjustment::class);
            $totals = $adjustments->forDue($store, $paymentMethod, $totals, $tender['amount_due'] ?? $totals['grand_total']);
            $adjustments->assertQuoted($store, $totals['payment_adjustment'], $contact['payment_adjustment_token'] ?? null);
            if ($requiresShipping) {
                $delivery = app(StoreShipping::class)->quote($store, bcsub($subtotal, $totals['discount_total'], 2), $shippingSelection, $offerFreeShipping, $productFreeShipping, $weightGrams);
                if ($freeProductIds !== []) {
                    $delivery['details']['free_shipping_product_ids'] = array_values(array_unique($freeProductIds));
                }
                if ($shippingAddress !== null || $delivery['address'] !== []) {
                    $shippingAddress = array_merge($shippingAddress ?? [], $delivery['address'], ['shipping_details' => $delivery['details']]);
                }
            } else {
                $shippingAddress = null;
            }
            app(CountryRestriction::class)->assertDelivery($store, $shippingAddress['country'] ?? null, $requiresShipping);
            $protection = app(OrderProtection::class);
            $signals = $protection->evaluate($store, ['phone' => $phone, 'payment_method' => $paymentMethod, 'name' => $contact['name'] ?? null,
                'address' => $shippingAddress, 'units' => (int) array_sum(array_column($lines, 'quantity'))]);
            $protection->assertNotBlocked($store, $signals);

            $order = new StoreOrder([
                'store_customer_id' => $customer?->id,
                'order_number' => $this->orders->generateNumber(),
                'status' => 'pending',
                'currency' => $currency,
                'customer_name' => $contact['name'],
                'customer_email' => $contact['email'],
                'customer_phone' => $contact['phone'] ?? null,
                'customer_phone_normalized' => $phone,
                'shipping_address' => $shippingAddress,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'subtotal' => $totals['subtotal'],
                'shipping_total' => $totals['shipping_total'],
                'discount_total' => $totals['discount_total'],
                'coupon_code' => $coupon?->code,
                'discount_details' => $discounts['applied'] ?: null,
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'gift_card_total' => $tender['total'] ?? '0.00',
                'gift_card_details' => $tender === null ? null : (GiftCards::details($tender) ?: null),
                'payment_adjustment' => $totals['payment_adjustment'],
                'notes' => $contact['notes'] ?? null,
                'placed_at' => now(),
            ]);
            $order->forceFill(['checkout_bot_verified' => $botProof !== null])->save();

            foreach ($lines as $index => $line) {
                $item = $order->items()->create($line);
                $this->inventory->reserve($stocks[$index], $item);
                $digitalProduct = $stocks[$index] instanceof Product ? $stocks[$index] : Product::query()->where('store_id', $store->id)->findOrFail($item->store_product_id);
                app(DigitalProducts::class)->reserve($digitalProduct, $item);
            }

            $protection->record($order, $store, $signals);
            $phoneProof?->update(['store_order_id' => $order->id]);
            $botProof?->update(['store_order_id' => $order->id]);

            foreach ($discounts['usages'] as $usage) {
                $this->coupons->recordUsage($usage['coupon'], $customer, $order, $usage['coupon']->type === 'free_shipping' ? '0.00' : $totals['discount_total']);
            }

            if ($tender !== null) {
                $giftCardService->redeem($order, $tender);
            }

            $cart->update(['status' => 'converted', 'coupon_id' => null]);

            app(StoreOrderPlacement::class)->record($order);
            if ($paymentStatus === 'paid') {
                app(DigitalDelivery::class)->record($order);
            }

            return $order->load('items');
        });
    }
}
