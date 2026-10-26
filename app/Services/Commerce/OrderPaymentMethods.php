<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use App\Models\StoreShipment;
use App\Models\StoreShippingRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A merchant moves an unpaid order to another payment method, e.g. a failed online payment to cash on
 * delivery or a transfer. The method's own pricing is recalculated: the zone's cash-on-delivery fee (and
 * the tax charged on it) and the payment method adjustment. Gift cards keep their fixed amounts. Moving
 * to cash on delivery waives a deposit; another method keeps the deposit rule frozen on the order.
 * The open payment attempts of the old method are closed and the change is recorded on the timeline.
 */
class OrderPaymentMethods
{
    /** Methods a merchant can move an order to: each is settled by the merchant, not by a provider redirect. */
    public const TARGETS = ['cod', 'bank_transfer', 'transfer_receipt'];

    public function blocker(Store $store, StoreOrder $order): ?string
    {
        if ($order->trashed()) {
            return 'order_archived';
        }
        if (! in_array($order->status, ['pending', 'confirmed', 'processing'], true)) {
            return 'order_closed';
        }
        if (! in_array($order->payment_status, ['pending', 'failed'], true) || $order->deposit_paid_at !== null) {
            return 'payment_settled';
        }
        if (strtoupper($order->currency) !== strtoupper($store->currency ?: 'USD')) {
            return 'currency_changed';
        }
        if (StoreShipment::where('store_id', $store->id)->where('store_order_id', $order->id)->exists()) {
            return 'shipment_exists';
        }
        if (StorePaymentTransaction::where('store_id', $store->id)->where('store_order_id', $order->id)
            ->where(fn ($query) => $query->whereIn('status', ['paid', 'processing'])->orWhereNotNull('paid_at'))->exists()) {
            return 'payment_in_progress';
        }

        return null;
    }

    /** The methods an order can move to, each with the totals it would have. */
    public function options(Store $store, StoreOrder $order): array
    {
        $blocker = $this->blocker($store, $order);
        $methods = [];
        if ($blocker === null) {
            foreach ($this->available($store, $order) as $slug => $setting) {
                if ($slug === $order->payment_method) {
                    continue;
                }
                try {
                    $preview = $this->preview($store, $order, $slug);
                } catch (ValidationException) {
                    continue;
                }
                $methods[] = ['slug' => $slug, 'name' => PaymentGatewayCatalog::presentation($setting ?? $this->fallback($store, $slug))['name']] + array_diff_key($preview, ['payment_adjustment' => true]);
            }
        }

        return ['current' => $order->payment_method, 'payment_status' => $order->payment_status, 'grand_total' => $order->grand_total,
            'blocker' => $blocker, 'methods' => $methods];
    }

    public function change(Store $store, StoreOrder $order, string $method, int $actorId, ?string $note = null, ?string $expectedTotal = null): StoreOrder
    {
        return DB::transaction(function () use ($store, $order, $method, $actorId, $note, $expectedTotal) {
            // Payment confirmation locks transactions before the order; keep the same order.
            $transactions = StorePaymentTransaction::where('store_id', $store->id)->where('store_order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $current = StoreOrder::forStore($store)->withTrashed()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $blocker = $this->blocker($store, $current);
            if ($blocker !== null) {
                throw ValidationException::withMessages(['payment_method' => __('The payment method of this order can no longer change.'), 'reason' => $blocker]);
            }
            $available = $this->available($store, $current);
            if ($method === $current->payment_method || ! array_key_exists($method, $available)) {
                throw ValidationException::withMessages(['payment_method' => __('Choose another available payment method.')]);
            }
            $preview = $this->preview($store, $current, $method);
            abort_if($expectedTotal !== null && bccomp($expectedTotal, $preview['grand_total'], 2) !== 0, 409, 'The order total for this payment method changed. Review it before saving.');

            $from = $current->payment_method;
            $before = (string) $current->grand_total;
            $address = $current->shipping_address;
            if (is_array($address) && ($address['shipping_details']['mode'] ?? null) === 'zone') {
                $address['shipping_details']['cod_fee'] = $preview['cod_fee'];
            }
            $current->forceFill(['payment_method' => $method, 'payment_status' => 'pending', 'shipping_total' => $preview['shipping_total'],
                'tax_total' => $preview['tax_total'], 'grand_total' => $preview['grand_total'], 'payment_adjustment' => $preview['payment_adjustment'],
                'shipping_address' => $address, 'deposit_total' => $preview['deposit']['amount'] ?? null,
                'deposit_details' => $preview['deposit'] === null ? null : $current->deposit_details])->save();

            // Earlier attempts no longer match the order; a late provider confirmation is refused, not applied.
            foreach ($transactions as $transaction) {
                if (in_array($transaction->status, ['created', 'pending', 'redirect_pending', 'failed'], true)) {
                    $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
                    $metadata['superseded'] = ['by' => 'payment_method_change', 'method' => $method, 'at' => now()->toIso8601String()];
                    $transaction->update(['status' => 'failed', 'failed_at' => $transaction->failed_at ?? now(), 'metadata' => $metadata]);
                }
            }
            $setting = $available[$method] ?? $this->fallback($store, $method);
            StorePaymentTransaction::query()->create(['store_id' => $store->id, 'store_order_id' => $current->id, 'gateway' => $method,
                'idempotency_key' => (string) Str::uuid(), 'status' => 'pending', 'amount' => $current->amountDue(), 'currency' => strtoupper($current->currency),
                'metadata' => ['presentation' => PaymentGatewayCatalog::presentation($setting)]
                    + (in_array($method, BankTransferInstructions::TARGET_METHODS, true) ? ['bank_transfer' => BankTransferInstructions::snapshot($setting)] : [])]);

            $change = $current->statusChanges()->create(['store_id' => $store->id, 'from_status' => $current->status, 'to_status' => $current->status, 'actor_id' => $actorId,
                'source' => 'payment_method_change', 'notes' => 'Payment method changed: '.$from.' → '.$method.' · '.$current->currency.' '.$before.' → '.$current->grand_total
                    .($preview['deposit_removed'] ? ' · deposit waived' : '').($note ? "\n".$note : '')]);
            app(StoreOrderWebhooks::class)->resend($current, 'payment-method:'.$change->id);
            DB::afterCommit(fn () => StoreAnalyticsService::forget($store->id));

            return $current;
        });
    }

    /** @return array<string, StorePaymentGateway|null> enabled settings by method; cash on delivery is always possible for a delivered order */
    private function available(Store $store, StoreOrder $order): array
    {
        $settings = StorePaymentGateway::where('store_id', $store->id)->where('enabled', true)->whereIn('gateway', self::TARGETS)->get()
            ->filter(fn (StorePaymentGateway $setting) => PaymentGatewayCatalog::offered($setting, $store->currency))->keyBy('gateway');
        $methods = [];
        foreach (self::TARGETS as $slug) {
            if ($slug === 'cod' ? $order->requiresShipping() : $settings->has($slug)) {
                $methods[$slug] = $settings->get($slug);
            }
        }

        return $methods;
    }

    private function fallback(Store $store, string $slug): StorePaymentGateway
    {
        return (new StorePaymentGateway)->forceFill(['store_id' => $store->id, 'gateway' => $slug, 'enabled' => true, 'test_mode' => false, 'credentials' => []]);
    }

    /**
     * The order's totals with another method: the zone COD fee follows the method (with tax charged on
     * shipping), then the method's adjustment applies to what it collects after gift cards.
     *
     * @return array{grand_total:string,shipping_total:string,tax_total:string,cod_fee:string,payment_adjustment_total:string,payment_adjustment:?array,amount_due:string,deposit:?array,deposit_removed:bool}
     */
    private function preview(Store $store, StoreOrder $order, string $method): array
    {
        $address = is_array($order->shipping_address) ? $order->shipping_address : [];
        $details = is_array($address['shipping_details'] ?? null) ? $address['shipping_details'] : [];
        $zone = ($details['mode'] ?? null) === 'zone';
        $oldFee = $zone ? bcadd((string) ($details['cod_fee'] ?? '0'), '0', 2) : '0.00';
        $newFee = '0.00';
        if ($zone && $method === 'cod' && is_string($details['rate']['id'] ?? null)) {
            $rate = StoreShippingRate::where('store_id', $store->id)->whereKey($details['rate']['id'])->first();
            $newFee = $rate !== null && $rate->cod_fee !== null ? bcadd((string) $rate->cod_fee, '0', 2) : '0.00';
        }
        $shipping = bcadd(bcsub((string) $order->shipping_total, $oldFee, 2), $newFee, 2);
        $shipping = bccomp($shipping, '0', 2) < 0 ? '0.00' : $shipping;
        $tax = bcadd((string) $order->tax_total, '0', 2);
        if (bccomp($oldFee, $newFee, 2) !== 0 && bccomp($tax, '0', 2) > 0) {
            $rule = app(StoreTaxes::class)->resolve($store, ['shipping_country' => $details['country'] ?? ($address['country'] ?? null),
                'shipping_region_id' => $details['region']['id'] ?? null]);
            if ($rule !== null && $rule['shipping'] && bccomp($rule['rate'], '0', 3) > 0) {
                $base = bcadd(bcsub((string) $order->subtotal, (string) $order->discount_total, 2), $shipping, 2);
                $tax = $store->tax_prices_include ? bcdiv(bcmul($base, $rule['rate'], 5), bcadd('100', $rule['rate'], 3), 2) : bcdiv(bcmul($base, $rule['rate'], 5), '100', 2);
            }
        }
        $exclusiveTax = $store->tax_prices_include ? '0.00' : bcsub($tax, (string) $order->tax_total, 2);
        $base = bcadd(bcadd(bcsub((string) $order->grand_total, (string) ($order->payment_adjustment['amount'] ?? '0.00'), 2),
            bcsub($shipping, (string) $order->shipping_total, 2), 2), $exclusiveTax, 2);
        $base = bccomp($base, '0', 2) < 0 ? '0.00' : $base;
        $gift = bcadd((string) ($order->gift_card_total ?? '0.00'), '0', 2);
        $due = bcsub($base, $gift, 2);
        $totals = app(PaymentPriceAdjustment::class)->forDue($store, $method, ['subtotal' => (string) $order->subtotal, 'discount_total' => (string) $order->discount_total,
            'shipping_total' => $shipping, 'tax_total' => $tax, 'grand_total' => $base], bccomp($due, '0', 2) < 0 ? '0.00' : $due);
        $money = bcsub($totals['grand_total'], $gift, 2);
        $money = bccomp($money, '0', 2) < 0 ? '0.00' : $money;
        $deposit = $method === 'cod' || $order->deposit_details === null ? null : app(OrderDeposits::class)->forRule($order->deposit_details, $money);

        return ['grand_total' => $totals['grand_total'], 'shipping_total' => $shipping, 'tax_total' => $tax, 'cod_fee' => $newFee,
            'payment_adjustment_total' => $totals['payment_adjustment_total'], 'payment_adjustment' => $totals['payment_adjustment'],
            'amount_due' => $deposit['amount'] ?? $money, 'deposit' => $deposit === null ? null : OrderDeposits::presentQuote($deposit),
            'deposit_removed' => $order->deposit_total !== null && $deposit === null];
    }
}
