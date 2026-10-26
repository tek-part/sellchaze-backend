<?php

namespace App\Services\Commerce;

use App\Models\Scopes\StoreScope;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreOrderRefund;
use App\Services\Notifications\StoreNotificationSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recorded refunds on storefront orders. Nothing here calls a payment gateway: the merchant returns
 * the money through the gateway dashboard, cash, a bank transfer or a credit note, and this service
 * keeps the books (amounts per line, shipping, restock, payment and order status) consistent.
 *
 * Money is handled in integer cents (bcmath strings) so allocations always add up exactly:
 * the order total minus shipping is spread over the lines by line total, the last line absorbing
 * rounding, and the last unit refunded on a line returns whatever is left of that line's share.
 *
 * @phpstan-type RefundLine array{item: StoreOrderItem, allocated: string, refunded_quantity: int, refundable_quantity: int, refunded_amount: string, remaining_amount: string}
 */
class StoreOrderRefunds
{
    /** Refunds are recorded after fulfilment or for paid orders that were cancelled. */
    public const REFUNDABLE_STATUSES = ['shipped', 'delivered', 'returning_from_delivery', 'request_refund', 'refund_in_progress', 'refunded', 'cancelled'];

    /** Cash on delivery is in the merchant's hands once the courier delivered the order. */
    private const COD_COLLECTED_STATUSES = ['delivered', 'request_refund', 'refund_in_progress'];

    public function __construct(private readonly StoreOrderService $orders) {}

    /** Whether the customer's money reached the merchant, i.e. whether there is money to give back. */
    public static function paymentCollected(StoreOrder $order): bool
    {
        return in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true)
            || ($order->payment_method === 'cod' && in_array($order->status, self::COD_COLLECTED_STATUSES, true));
    }

    /**
     * What has been refunded and what can still be refunded, per line and in total.
     *
     * @return array<string, mixed>
     */
    public function summary(StoreOrder $order): array
    {
        $state = $this->state($order);

        return [
            'currency' => $order->currency,
            'order_status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_collected' => $state['collected'],
            'refundable' => $state['open'],
            'grand_total' => $this->money($state['grand']),
            'refunded_total' => $this->money($state['refunded']),
            'refundable_total' => $this->money($state['remaining']),
            'fully_refunded' => $state['fully'],
            'shipping' => ['total' => $this->money($state['shipping_cap']), 'refunded' => $this->money($state['shipping_refunded']),
                'refundable' => $this->money($state['shipping_remaining'])],
            'items' => $state['lines']->map(fn (array $line) => [
                'store_order_item_id' => $line['item']->id,
                'name' => $line['item']->name,
                'variant_name' => $line['item']->variant_name,
                'sku' => $line['item']->sku,
                'unit_price' => $line['item']->unit_price,
                'line_total' => $line['item']->line_total,
                'quantity' => $line['item']->quantity,
                'refunded_quantity' => $line['refunded_quantity'],
                'refundable_quantity' => $line['refundable_quantity'],
                'allocated_amount' => $this->money($line['allocated']),
                'refunded_amount' => $this->money($line['refunded_amount']),
                'refundable_amount' => $this->money($line['remaining_amount']),
                'restockable' => $line['item']->inventory_status === 'committed' && $line['item']->digital_delivery === null,
                'digital' => $line['item']->digital_delivery !== null,
            ])->values()->all(),
            'refunds' => $state['refunds']->map(fn (StoreOrderRefund $refund) => $this->present($refund, $state['lines']))->values()->all(),
        ];
    }

    /**
     * Record a refund. Runs in one transaction with the order row locked, so two submissions can
     * never refund the same units or the same money twice.
     *
     * @param  array{items?: array<int, array{store_order_item_id:int|string, quantity:int|string}>, shipping_amount?: string|int|float|null,
     *     amount?: string|int|float|null, reason?: string|null, note?: string|null, method: string, restock?: bool, expected_refunded_total?: string|int|float|null}  $data
     */
    public function create(StoreOrder $order, array $data, ?int $actorId = null, ?string $source = 'refund'): StoreOrderRefund
    {
        return DB::transaction(function () use ($order, $data, $actorId, $source) {
            $current = StoreOrder::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $order->store_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $order->setRawAttributes($current->getAttributes(), true);
            if (! in_array($order->status, self::REFUNDABLE_STATUSES, true)) {
                throw ValidationException::withMessages(['order' => 'Record refunds once the order has shipped, been delivered or returned, or was cancelled after payment. Edit or cancel unshipped orders instead.']);
            }
            $state = $this->state($order);
            if (array_key_exists('expected_refunded_total', $data) && $data['expected_refunded_total'] !== null
                && bccomp($this->cents($data['expected_refunded_total']), $state['refunded'], 0) !== 0) {
                abort(409, 'Refunds on this order changed. Reload the order before recording another refund.');
            }
            if (! $state['open']) {
                throw ValidationException::withMessages(['order' => $order->status === 'cancelled' && ! $state['collected']
                    ? 'Nothing was paid on this cancelled order, so there is nothing to refund.' : 'Everything on this order has already been refunded.']);
            }

            $requested = [];
            foreach ($data['items'] ?? [] as $row) {
                $id = (int) $row['store_order_item_id'];
                $requested[$id] = ($requested[$id] ?? 0) + (int) $row['quantity'];
            }
            $lines = $state['lines']->keyBy(fn (array $line) => $line['item']->id);
            $records = [];
            $itemsCents = '0';
            foreach ($requested as $id => $quantity) {
                $line = $lines->get($id);
                if ($line === null) {
                    throw ValidationException::withMessages(['items' => 'One of the items does not belong to this order.']);
                }
                if ($quantity < 1 || $quantity > $line['refundable_quantity']) {
                    throw ValidationException::withMessages(['items' => "You can refund at most {$line['refundable_quantity']} of {$line['item']->name}."]);
                }
                $cents = $state['collected'] ? $this->lineAmount($line, $quantity) : '0';
                $itemsCents = bcadd($itemsCents, $cents, 0);
                $records[] = ['line' => $line, 'quantity' => $quantity, 'cents' => $cents];
            }
            $shipping = $this->cents($data['shipping_amount'] ?? '0');
            $shippingLimit = $state['collected'] ? $state['shipping_remaining'] : '0';
            if (bccomp($shipping, $shippingLimit, 0) > 0) {
                throw ValidationException::withMessages(['shipping_amount' => 'The shipping refund cannot exceed '.$this->money($shippingLimit).' '.$order->currency.'.']);
            }
            if ($records === [] && bccomp($shipping, '0', 0) <= 0) {
                throw ValidationException::withMessages(['items' => 'Choose at least one item or a shipping amount to refund.']);
            }
            $computed = bcadd($itemsCents, $shipping, 0);
            $max = bccomp($computed, $state['remaining'], 0) > 0 ? $state['remaining'] : $computed;
            $amount = array_key_exists('amount', $data) && $data['amount'] !== null && $data['amount'] !== '' ? $this->cents($data['amount']) : $max;
            if (bccomp($amount, '0', 0) < 0 || bccomp($amount, $max, 0) > 0) {
                throw ValidationException::withMessages(['amount' => 'The refund amount cannot exceed '.$this->money($max).' '.$order->currency.'.']);
            }

            $restock = (bool) ($data['restock'] ?? false);
            $restocked = 0;
            $items = [];
            foreach ($records as $record) {
                /** @var StoreOrderItem $item */
                $item = $record['line']['item'];
                $units = $restock ? app(StoreInventory::class)->restock($item, $record['quantity'], $actorId, 'Refund on order '.$order->order_number) : 0;
                $restocked += $units;
                $items[] = ['store_order_item_id' => $item->id, 'quantity' => $record['quantity'], 'amount' => $this->money($record['cents']), 'restocked_quantity' => $units];
            }

            $refund = StoreOrderRefund::query()->create([
                'store_id' => $order->store_id, 'store_order_id' => $order->id, 'amount' => $this->money($amount), 'shipping_amount' => $this->money($shipping),
                'reason' => $data['reason'] ?? null, 'note' => $data['note'] ?? null, 'method' => $data['method'], 'restocked' => $restocked > 0,
                'items' => $items, 'actor_id' => $actorId,
            ]);

            $refundedTotal = bcadd($state['refunded'], $amount, 0);
            $moneyDone = ! $state['collected'] || bccomp($refundedTotal, $state['grand'], 0) >= 0;
            $unitsLeft = $state['lines']->sum('refundable_quantity') - array_sum(array_column($records, 'quantity'));
            $fully = $moneyDone && $unitsLeft <= 0;
            $order->refunded_total = $this->money($refundedTotal);
            if ($state['collected'] && bccomp($refundedTotal, '0', 0) > 0) {
                $order->payment_status = $moneyDone ? 'refunded' : 'partially_refunded';
            }
            $order->save();

            $note = $this->note($order, $refund, $restocked);
            if ($fully && $order->status !== 'refunded' && $this->orders->canTransition($order, 'refunded')) {
                // The lifecycle owns the status change, its timeline entry, webhooks and the (deduplicated) customer email.
                $this->orders->transition($order, 'refunded', $actorId, $note, $source, $state['collected'] && bccomp($refundedTotal, '0', 0) > 0);
            } else {
                $order->statusChanges()->create(['store_id' => $order->store_id, 'from_status' => $order->status, 'to_status' => $order->status,
                    'actor_id' => $actorId, 'notes' => $note, 'source' => $source]);
                if ($fully && $state['collected'] && bccomp($refundedTotal, '0', 0) > 0) {
                    // Outbox deduplication keeps this to one message per channel even if the status was set to refunded earlier.
                    app(StoreNotificationSettings::class)->schedule($order, 'order_refunded');
                }
            }
            DB::afterCommit(fn () => StoreAnalyticsService::forget($order->store_id));

            return $refund;
        });
    }

    /**
     * @return array{collected:bool, open:bool, fully:bool, grand:string, refunded:string, remaining:string, shipping_cap:string,
     *     shipping_refunded:string, shipping_remaining:string, lines:Collection<int, RefundLine>, refunds:Collection<int, StoreOrderRefund>}
     */
    private function state(StoreOrder $order): array
    {
        /** @var Collection<int, StoreOrderItem> $items */
        $items = StoreOrderItem::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $order->store_id)
            ->where('store_order_id', $order->id)->orderBy('id')->get();
        /** @var Collection<int, StoreOrderRefund> $refunds */
        $refunds = StoreOrderRefund::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $order->store_id)
            ->where('store_order_id', $order->id)->with('actor:id,name')->orderBy('id')->get();

        $grand = $this->positive($this->cents($order->grand_total));
        $shippingTotal = $this->positive($this->cents($order->shipping_total));
        $shippingCap = bccomp($shippingTotal, $grand, 0) > 0 ? $grand : $shippingTotal;
        $goods = bcsub($grand, $shippingCap, 0);

        // Spread the goods share by line total (or by units when every line is free).
        $weights = $items->mapWithKeys(fn (StoreOrderItem $item) => [$item->id => $this->positive($this->cents($item->line_total))]);
        $weightSum = $weights->reduce(fn (string $sum, string $weight) => bcadd($sum, $weight, 0), '0');
        if (bccomp($weightSum, '0', 0) === 0) {
            $weights = $items->mapWithKeys(fn (StoreOrderItem $item) => [$item->id => (string) max(0, $item->quantity)]);
            $weightSum = (string) $items->sum(fn (StoreOrderItem $item) => max(0, $item->quantity));
        }
        $allocations = [];
        $assigned = '0';
        $lastWeighted = $items->filter(fn (StoreOrderItem $item) => bccomp($weights[$item->id], '0', 0) > 0)->last();
        foreach ($items as $item) {
            if (bccomp($weightSum, '0', 0) === 0 || bccomp($weights[$item->id], '0', 0) === 0) {
                $allocations[$item->id] = '0';
            } elseif ($lastWeighted !== null && $item->id === $lastWeighted->id) {
                $allocations[$item->id] = bcsub($goods, $assigned, 0);
            } else {
                $allocations[$item->id] = bcdiv(bcmul($goods, $weights[$item->id], 0), $weightSum, 0);
                $assigned = bcadd($assigned, $allocations[$item->id], 0);
            }
        }

        $refundedQuantities = [];
        $refundedAmounts = [];
        $refunded = '0';
        $shippingRefunded = '0';
        foreach ($refunds as $refund) {
            $refunded = bcadd($refunded, $this->cents($refund->amount), 0);
            $shippingRefunded = bcadd($shippingRefunded, $this->cents($refund->shipping_amount), 0);
            foreach ($refund->items ?? [] as $row) {
                $id = (int) $row['store_order_item_id'];
                $refundedQuantities[$id] = ($refundedQuantities[$id] ?? 0) + (int) $row['quantity'];
                $refundedAmounts[$id] = bcadd($refundedAmounts[$id] ?? '0', $this->cents($row['amount'] ?? '0'), 0);
            }
        }

        $lines = $items->map(function (StoreOrderItem $item) use ($allocations, $refundedQuantities, $refundedAmounts) {
            $refundedQuantity = $refundedQuantities[$item->id] ?? 0;
            $refundedAmount = $refundedAmounts[$item->id] ?? '0';
            $remaining = bcsub($allocations[$item->id], $refundedAmount, 0);

            return ['item' => $item, 'allocated' => $allocations[$item->id], 'refunded_quantity' => $refundedQuantity,
                'refundable_quantity' => max(0, $item->quantity - $refundedQuantity), 'refunded_amount' => $refundedAmount,
                'remaining_amount' => $this->positive($remaining)];
        })->values();

        $collected = self::paymentCollected($order);
        $remaining = $collected ? $this->positive(bcsub($grand, $refunded, 0)) : '0';
        $shippingRemaining = $this->positive(bcsub($shippingCap, $shippingRefunded, 0));
        $unitsLeft = $lines->sum('refundable_quantity');
        $fully = $unitsLeft === 0 && $refunds->isNotEmpty() && bccomp($remaining, '0', 0) === 0;
        // A cancelled order only needs a refund when money was actually taken.
        $open = in_array($order->status, self::REFUNDABLE_STATUSES, true) && ($collected || $order->status !== 'cancelled')
            && ($unitsLeft > 0 || bccomp($collected ? $shippingRemaining : '0', '0', 0) > 0);

        return ['collected' => $collected, 'open' => $open, 'fully' => $fully, 'grand' => $grand, 'refunded' => $refunded, 'remaining' => $remaining,
            'shipping_cap' => $shippingCap, 'shipping_refunded' => $shippingRefunded, 'shipping_remaining' => $collected ? $shippingRemaining : '0',
            'lines' => $lines, 'refunds' => $refunds];
    }

    /**
     * Cents for `$quantity` more units of a line: its proportional share, and the exact remainder for the last units.
     *
     * @param  RefundLine  $line
     */
    private function lineAmount(array $line, int $quantity): string
    {
        if ($quantity >= $line['refundable_quantity']) {
            return $line['remaining_amount'];
        }
        $share = bcdiv(bcmul($line['allocated'], (string) $quantity, 0), (string) max(1, $line['item']->quantity), 0);

        return bccomp($share, $line['remaining_amount'], 0) > 0 ? $line['remaining_amount'] : $share;
    }

    /** @param Collection<int, RefundLine> $lines */
    private function present(StoreOrderRefund $refund, Collection $lines): array
    {
        $names = $lines->mapWithKeys(fn (array $line) => [$line['item']->id => $line['item']->name]);

        return ['id' => $refund->id, 'amount' => $refund->amount, 'shipping_amount' => $refund->shipping_amount, 'reason' => $refund->reason,
            'note' => $refund->note, 'method' => $refund->method, 'restocked' => $refund->restocked,
            'items' => collect($refund->items ?? [])->map(fn (array $row) => ['store_order_item_id' => (int) $row['store_order_item_id'],
                'name' => $names[(int) $row['store_order_item_id']] ?? null, 'quantity' => (int) $row['quantity'], 'amount' => $row['amount'] ?? '0.00',
                'restocked_quantity' => (int) ($row['restocked_quantity'] ?? 0)])->values()->all(),
            'actor' => $refund->relationLoaded('actor') ? $refund->actor?->name : null, 'created_at' => $refund->created_at];
    }

    private function note(StoreOrder $order, StoreOrderRefund $refund, int $restocked): string
    {
        $units = array_sum(array_column($refund->items, 'quantity'));
        $parts = ['Refund recorded: '.$refund->amount.' '.$order->currency.' via '.str_replace('_', ' ', $refund->method)];
        if ($units > 0) {
            $parts[] = $units.' item(s)';
        }
        if (bccomp((string) $refund->shipping_amount, '0', 2) > 0) {
            $parts[] = 'shipping '.$refund->shipping_amount;
        }
        if ($restocked > 0) {
            $parts[] = $restocked.' restocked';
        }

        return implode(' · ', $parts).($refund->note ? ' — '.$refund->note : '');
    }

    private function cents(mixed $value): string
    {
        return bcmul(is_numeric($value) ? (string) $value : '0', '100', 0);
    }

    private function money(string $cents): string
    {
        return bcdiv($cents, '100', 2);
    }

    private function positive(string $cents): string
    {
        return bccomp($cents, '0', 0) > 0 ? $cents : '0';
    }
}
