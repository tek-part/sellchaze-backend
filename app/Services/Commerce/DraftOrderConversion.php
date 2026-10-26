<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDraftOrder;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Converts a draft into exactly one real StoreOrder through the checkout rules: locked
 * catalog rows, stock + digital reservation, coupon usage, numbering, placement events.
 * Re-running it for a completed draft returns the same order.
 */
class DraftOrderConversion
{
    /**
     * @param  array{source:string, payment_method?:string|null, payment_status?:string, actor_id?:int|null,
     *     customer?:array<string,mixed>|null, shipping_address?:array<string,mixed>|null, notes?:string|null}  $options
     * @return array{order: StoreOrder, created: bool}
     */
    public function complete(Store $store, int $draftId, array $options): array
    {
        return DB::transaction(function () use ($store, $draftId, $options) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $draft = StoreDraftOrder::forStore($store)->whereKey($draftId)->lockForUpdate()->firstOrFail();
            if ($draft->status === 'completed') {
                $order = StoreOrder::forStore($store)->whereKey($draft->completed_order_id)->with('items')->first();
                abort_if($order === null, 409, 'The order created from this draft is no longer available.');

                return ['order' => $order, 'created' => false];
            }
            if (! $draft->editable()) {
                throw ValidationException::withMessages(['status' => 'A cancelled draft cannot be completed.']);
            }
            $invoice = $options['source'] === 'invoice';
            if ($invoice) {
                abort_if($draft->expired(), 404);
                $draft->customer = array_filter(array_replace($draft->customer ?? [], $options['customer'] ?? []), fn ($value) => $value !== null && $value !== '');
                if (array_key_exists('shipping_address', $options)) {
                    $draft->shipping_address = $options['shipping_address'];
                }
            }
            $attributes = $draft->only(['store_customer_id', 'customer', 'shipping_address', 'line_items', 'applied_discount', 'shipping_line']);
            ['totals' => $totals, 'context' => $context] = app(DraftOrders::class)->compute($store, $attributes, true);
            if ($invoice && bccomp($totals['grand_total'], (string) $draft->grand_total, 2) !== 0) {
                throw ValidationException::withMessages(['invoice' => 'This invoice has changed. Ask the store for an updated invoice link.']);
            }

            $customer = $draft->customer ?? [];
            if (blank($customer['name'] ?? null)) {
                throw ValidationException::withMessages(['customer.name' => 'Add the customer name before creating the order.']);
            }
            if ($totals['has_digital'] && ! filter_var($customer['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['customer.email' => 'A valid email address is required for digital delivery.']);
            }
            if ($totals['requires_whatsapp'] && StoreDigitalWhatsappClient::phone((string) ($customer['phone'] ?? '')) === null) {
                throw ValidationException::withMessages(['customer.phone' => 'Enter an international WhatsApp number for digital delivery.']);
            }
            if ($totals['requires_shipping'] && blank($draft->shipping_address['line1'] ?? null)) {
                throw ValidationException::withMessages(['shipping_address.line1' => 'Enter a delivery address for this order.']);
            }
            $payment = $options['payment_method'] ?? ($totals['requires_shipping'] ? 'cod' : 'bank_transfer');
            if (! $totals['requires_shipping'] && $payment === 'cod') {
                throw ValidationException::withMessages(['payment_method' => 'Cash on delivery is not available for an order without delivery.']);
            }
            $phone = app(OrderLimits::class)->assertPhone($store, $customer['phone'] ?? null);
            foreach ($context['product_counts'] as $count) {
                app(OrderLimits::class)->assertQuantity($store, $count);
            }

            $pricing = ['subtotal' => $totals['subtotal'], 'discount_total' => $totals['discount_total'], 'shipping_total' => $totals['shipping_total'],
                'tax_total' => $totals['tax_total'], 'grand_total' => $totals['grand_total'], 'payment_adjustment' => null];
            if ($invoice) {
                $pricing = app(PaymentPriceAdjustment::class)->forMethod($store, $payment, $pricing);
            }
            $address = null;
            if ($totals['requires_shipping']) {
                $details = $totals['shipping']['details'] ?? ['mode' => 'none', 'base_rate' => '0.00'];
                $details['draft_order'] = $draft->number;
                $address = array_merge($draft->shipping_address ?? [], $totals['shipping']['address'] ?? [], ['shipping_details' => $details]);
            }
            $coupon = collect($totals['discounts'])->first(fn (array $row) => $row['code'] !== null);

            $order = new StoreOrder([
                'store_id' => $store->id,
                'store_customer_id' => $draft->store_customer_id,
                'order_number' => app(StoreOrderService::class)->generateNumber(),
                'status' => 'pending',
                'currency' => $totals['currency'],
                'customer_name' => $customer['name'],
                'customer_email' => $customer['email'] ?? null,
                'customer_phone' => $customer['phone'] ?? null,
                'customer_phone_normalized' => $phone,
                'shipping_address' => $address,
                'payment_method' => $payment,
                'payment_status' => 'pending',
                'subtotal' => $pricing['subtotal'],
                'shipping_total' => $pricing['shipping_total'],
                'discount_total' => $pricing['discount_total'],
                'coupon_code' => $coupon['code'] ?? null,
                'discount_details' => $totals['discounts'] ?: null,
                'tax_total' => $pricing['tax_total'],
                'grand_total' => $pricing['grand_total'],
                'payment_adjustment' => $pricing['payment_adjustment'],
                'notes' => $invoice ? ($options['notes'] ?? null) : null,
                'placed_at' => now(),
            ]);
            $order->save();

            foreach ($totals['line_items'] as $index => $line) {
                $stock = $context['stocks'][$index]['stock'] ?? null;
                $product = $context['stocks'][$index]['product'] ?? null;
                $item = $order->items()->create([
                    'store_id' => $store->id,
                    'store_product_id' => $line['type'] === 'product' ? $line['product_id'] : null,
                    'variant_id' => $line['variant_id'] ?? null,
                    'variant_name' => $line['variant_name'] ?? null,
                    'variant_options' => $line['variant_options'] ?? null,
                    'sku' => $line['sku'] ?? null,
                    'name' => $line['title'],
                    'unit_price' => $line['unit_price'],
                    'unit_cost' => $stock?->cost ?? $product?->cost,
                    'quantity' => $line['quantity'],
                    'line_total' => $line['line_total'],
                ]);
                if ($stock !== null && $product instanceof Product) {
                    app(StoreInventory::class)->reserve($stock, $item);
                    app(DigitalProducts::class)->reserve($product, $item);
                }
            }
            foreach ($context['usages'] as $usage) {
                app(CouponService::class)->recordUsage($usage['coupon'], $context['customer'], $order, $usage['coupon']->type === 'free_shipping' ? '0.00' : $totals['discount_total']);
            }
            $actorId = $options['actor_id'] ?? null;
            $order->statusChanges()->create(['from_status' => 'pending', 'to_status' => 'pending', 'actor_id' => $actorId, 'source' => $invoice ? 'draft_invoice' : 'draft_order',
                'notes' => trim('Created from draft '.$draft->number.($draft->note ? "\n".$draft->note : ''))]);

            if (! $invoice) {
                $setting = StorePaymentGateway::where('store_id', $store->id)->where('gateway', $payment)->where('enabled', true)->first()
                    ?? (new StorePaymentGateway)->forceFill(['store_id' => $store->id, 'gateway' => $payment, 'enabled' => true, 'test_mode' => false, 'credentials' => [], 'notes' => '']);
                app(StorePaymentService::class)->start($store, $order, $setting);
            }
            app(StoreOrderPlacement::class)->record($order);
            if (! $invoice && ($options['payment_status'] ?? 'pending') === 'paid') {
                $transaction = StorePaymentTransaction::query()->where('store_id', $store->id)->where('store_order_id', $order->id)->latest('id')->firstOrFail();
                app(PaymentSettlement::class)->transition($transaction, 'paid', 'DRAFT-'.$draft->number);
                $order->statusChanges()->create(['from_status' => 'pending', 'to_status' => 'pending', 'actor_id' => $actorId, 'source' => 'draft_order',
                    'notes' => 'Marked as paid when created from draft '.$draft->number]);
                $order->refresh();
            }

            $draft->forceFill([
                'status' => 'completed', 'completed_order_id' => $order->id, 'completed_at' => now(), 'completed_by_id' => $actorId,
                'updated_by_id' => $actorId ?? $draft->updated_by_id, ...DraftOrders::snapshot($totals),
                // A merchant completion retires the shared link; an invoice completion keeps it so a repeat returns the same order.
                'invoice_token_hash' => $invoice ? $draft->invoice_token_hash : null,
            ])->save();

            return ['order' => $order->load('items'), 'created' => true];
        });
    }
}
