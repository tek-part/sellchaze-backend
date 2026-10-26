<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderStatusChange;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * G2: guest order tracking. An order number plus the phone or e-mail used on that order returns its
 * status timeline, shipments, items and totals — never addresses, contact details or notes. Lookups
 * stay inside the host's store, every miss answers the same, and each order number has its own
 * attempt budget so a contact cannot be guessed.
 */
class GuestOrderTracking
{
    public const ATTEMPTS_PER_ORDER = 10;

    public function find(Store $store, string $number, string $contact): ?StoreOrder
    {
        $key = 'order-track:'.$store->id.':'.hash('sha256', Str::upper(trim($number)));
        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS_PER_ORDER)) {
            return null;
        }
        $order = StoreOrder::query()->withoutGlobalScopes()->where('store_id', $store->id)->where('order_number', trim($number))->first();
        if ($order && $this->matches($store, $order, $contact)) {
            return $order;
        }
        RateLimiter::hit($key, 3600);

        return null;
    }

    private function matches(Store $store, StoreOrder $order, string $contact): bool
    {
        $contact = trim($contact);
        if (str_contains($contact, '@')) {
            return filled($order->customer_email) && hash_equals(Str::lower(trim((string) $order->customer_email)), Str::lower($contact));
        }
        $limits = app(OrderLimits::class);
        $country = $limits->configured($store)['phone_country'];
        $phone = $limits->normalizePhone($contact, $country);
        $orderPhone = $order->customer_phone_normalized ?: $limits->normalizePhone($order->customer_phone, $country);

        return $phone !== null && $orderPhone !== null && hash_equals($orderPhone, $phone);
    }

    public function present(StoreOrder $order): array
    {
        $order->loadMissing('items');

        return [
            'number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'fulfillment_status' => $order->fulfillment_status ?? 'unfulfilled',
            'currency' => $order->currency,
            'placed_at' => $order->placed_at ?? $order->created_at,
            'timeline' => $this->timeline($order),
            'fulfillments' => app(StoreFulfillments::class)->forCustomer($order),
            'items' => $order->items->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'variant_name' => $item->variant_name,
                'quantity' => (int) $item->quantity, 'unit_price' => $item->unit_price, 'line_total' => $item->line_total])->values()->all(),
            'subtotal' => $order->subtotal,
            'shipping_total' => $order->shipping_total,
            'discount_total' => $order->discount_total,
            'tax_total' => $order->tax_total,
            'grand_total' => $order->grand_total,
            'refunded_total' => $order->refunded_total ?? '0.00',
            'amount_due' => $order->amountDue(),
        ];
    }

    /** Status transitions the customer may see (notes and audit-only rows excluded): the status and when, never who or why. */
    public function timeline(StoreOrder $order): array
    {
        $changes = StoreOrderStatusChange::query()->withoutGlobalScopes()->where('store_id', $order->store_id)
            ->where('store_order_id', $order->id)->whereColumn('from_status', '!=', 'to_status')->orderBy('id')->get(['to_status', 'created_at']);
        $timeline = [['status' => 'placed', 'at' => $order->placed_at ?? $order->created_at]];
        foreach ($changes as $change) {
            if (is_string($change->to_status) && $change->to_status !== '') {
                $timeline[] = ['status' => $change->to_status, 'at' => $change->created_at];
            }
        }

        return $timeline;
    }
}
