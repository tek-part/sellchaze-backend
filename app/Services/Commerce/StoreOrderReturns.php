<?php

namespace App\Services\Commerce;

use App\Models\Scopes\StoreScope;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreOrderReturn;
use App\Models\StoreOrderStatusChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Return requests: a customer asks to send delivered items back within the store's return window,
 * the merchant approves or rejects, marks the parcel received and closes it — optionally recording
 * the refund for the returned lines through {@see StoreOrderRefunds} in the same transaction.
 */
class StoreOrderReturns
{
    public function __construct(private readonly StoreOrderRefunds $refunds) {}

    /**
     * Whether the customer may open a return on this order now, and how many units of each line.
     *
     * @return array{eligible:bool, reason:string|null, window_days:int, delivered_at:string|null, deadline:string|null, items:list<array<string, mixed>>}
     */
    public function eligibility(StoreOrder $order): array
    {
        $store = Store::query()->findOrFail($order->store_id);
        $window = app(OrderLimits::class)->configured($store)['return_window_days'];
        $deliveredAt = $this->deliveredAt($order);
        $deadline = $deliveredAt?->copy()->addDays($window);
        $items = $this->returnable($order);
        $reason = match (true) {
            $window === 0 => 'disabled',
            $order->status !== 'delivered' => 'not_delivered',
            $deadline !== null && $deadline->isPast() => 'window_expired',
            $this->openReturn($order) !== null => 'open_return',
            collect($items)->sum('returnable_quantity') === 0 => 'nothing_returnable',
            default => null,
        };

        return ['eligible' => $reason === null, 'reason' => $reason, 'window_days' => $window,
            'delivered_at' => $deliveredAt?->toIso8601String(), 'deadline' => $window > 0 ? $deadline?->toIso8601String() : null, 'items' => $items];
    }

    /**
     * A customer's return request. The order row is locked so double submissions open one return.
     *
     * @param  array{items: array<int, array{store_order_item_id:int|string, quantity:int|string}>, reason: string, note?: string|null}  $data
     */
    public function request(StoreOrder $order, int $customerId, array $data): StoreOrderReturn
    {
        return DB::transaction(function () use ($order, $customerId, $data) {
            $current = StoreOrder::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $order->store_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $order->setRawAttributes($current->getAttributes(), true);
            abort_unless((int) $order->store_customer_id === $customerId, 404, 'Order not found.');
            $eligibility = $this->eligibility($order);
            if (! $eligibility['eligible']) {
                throw ValidationException::withMessages(['order' => $this->ineligibleMessage((string) $eligibility['reason'])]);
            }
            $available = collect($eligibility['items'])->keyBy('store_order_item_id');
            $requested = [];
            foreach ($data['items'] as $row) {
                $id = (int) $row['store_order_item_id'];
                $requested[$id] = ($requested[$id] ?? 0) + (int) $row['quantity'];
            }
            $items = [];
            foreach ($requested as $id => $quantity) {
                $line = $available->get($id);
                if ($line === null || $quantity < 1 || $quantity > $line['returnable_quantity']) {
                    throw ValidationException::withMessages(['items' => $line === null ? 'One of the items cannot be returned.' : "You can return at most {$line['returnable_quantity']} of {$line['name']}."]);
                }
                $items[] = ['store_order_item_id' => $id, 'quantity' => $quantity];
            }
            $return = StoreOrderReturn::query()->create(['store_id' => $order->store_id, 'store_order_id' => $order->id, 'store_customer_id' => $customerId,
                'status' => 'requested', 'reason' => $data['reason'], 'customer_note' => $data['note'] ?? null, 'items' => $items]);
            $this->timeline($order, null, 'return_requested', 'Return #'.$return->id.' requested by the customer ('.str_replace('_', ' ', $data['reason']).')'.(($data['note'] ?? null) ? ' — '.$data['note'] : ''));

            return $return;
        });
    }

    /**
     * Merchant workflow step. Status moves along {@see StoreOrderReturn::TRANSITIONS}; notes and the
     * resolution can be updated on their own. `refund` (when approving, receiving or closing) records
     * the refund for the returned lines with the same rules and locks as a manual refund.
     *
     * @param  array{status?: string|null, merchant_note?: string|null, resolution?: string|null, refund?: array<string, mixed>|null}  $data
     */
    public function update(StoreOrderReturn $return, array $data, int $actorId): StoreOrderReturn
    {
        return DB::transaction(function () use ($return, $data, $actorId) {
            $order = StoreOrder::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $return->store_id)->whereKey($return->store_order_id)->lockForUpdate()->firstOrFail();
            $locked = StoreOrderReturn::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $return->store_id)->whereKey($return->id)->lockForUpdate()->firstOrFail();
            $return->setRawAttributes($locked->getAttributes(), true);
            $from = $return->status;
            $to = $data['status'] ?? $from;
            if ($to !== $from && ! in_array($to, StoreOrderReturn::TRANSITIONS[$from] ?? [], true)) {
                throw ValidationException::withMessages(['status' => "Cannot move a return from {$from} to {$to}."]);
            }
            if (array_key_exists('merchant_note', $data)) {
                $return->merchant_note = $data['merchant_note'];
            }
            if (array_key_exists('resolution', $data)) {
                $return->resolution = $data['resolution'];
            }
            $return->status = $to;
            $refund = null;
            if (! empty($data['refund'])) {
                if (! in_array($to, ['approved', 'received', 'closed'], true)) {
                    throw ValidationException::withMessages(['refund' => 'Approve or receive the return before refunding it.']);
                }
                if ($return->store_order_refund_id !== null) {
                    throw ValidationException::withMessages(['refund' => 'This return already has a refund.']);
                }
                $payload = array_merge(['reason' => 'returned', 'restock' => $to !== 'approved'], $data['refund'], [
                    'items' => collect($return->items)->map(fn (array $row) => ['store_order_item_id' => (int) $row['store_order_item_id'], 'quantity' => (int) $row['quantity']])->all(),
                ]);
                $refund = $this->refunds->create($order, $payload, $actorId, 'return_refund');
                $return->store_order_refund_id = $refund->id;
                $return->resolution = 'refund';
            }
            $return->save();
            if ($to !== $from) {
                $this->timeline($order->refresh(), $actorId, 'return_'.$to, 'Return #'.$return->id.': '.$from.' → '.$to.($return->merchant_note ? ' — '.$return->merchant_note : ''));
            }

            return $return;
        });
    }

    /** @return array<string, mixed> */
    /** @param array<int, string>|null $names item id => line name, preloaded by list endpoints */
    public function present(StoreOrderReturn $return, bool $merchant = true, ?array $names = null): array
    {
        $names ??= $this->lineNames((int) $return->store_id, (int) $return->store_order_id);

        $row = [
            'id' => $return->id,
            'store_order_id' => $return->store_order_id,
            'status' => $return->status,
            'reason' => $return->reason,
            'customer_note' => $return->customer_note,
            'merchant_note' => $return->merchant_note,
            'resolution' => $return->resolution,
            'store_order_refund_id' => $return->store_order_refund_id,
            'next_statuses' => StoreOrderReturn::TRANSITIONS[$return->status] ?? [],
            'items' => collect($return->items ?? [])->map(fn (array $item) => ['store_order_item_id' => (int) $item['store_order_item_id'],
                'name' => $names[(int) $item['store_order_item_id']] ?? null, 'quantity' => (int) $item['quantity']])->values()->all(),
            'created_at' => $return->created_at,
            'updated_at' => $return->updated_at,
        ];

        // Internal notes and workflow controls never reach the storefront.
        return $merchant ? $row : array_diff_key($row, array_flip(['merchant_note', 'store_order_refund_id', 'next_statuses']));
    }

    public function openReturn(StoreOrder $order): ?StoreOrderReturn
    {
        return StoreOrderReturn::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $order->store_id)
            ->where('store_order_id', $order->id)->whereIn('status', StoreOrderReturn::OPEN)->first();
    }

    public function deliveredAt(StoreOrder $order): ?Carbon
    {
        $change = StoreOrderStatusChange::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $order->store_id)
            ->where('store_order_id', $order->id)->where('to_status', 'delivered')->where('from_status', '!=', 'delivered')->orderByDesc('id')->first();
        if ($change?->created_at) {
            return Carbon::parse($change->created_at);
        }

        return $order->status === 'delivered' && $order->updated_at ? Carbon::parse($order->updated_at) : null;
    }

    /**
     * Physical lines and how many units can still be returned: bought minus what was already refunded
     * or is part of an earlier non-rejected return (whichever is larger, so a refunded return is not counted twice).
     *
     * @return list<array<string, mixed>>
     */
    private function returnable(StoreOrder $order): array
    {
        $refunded = collect($this->refunds->summary($order)['items'])->keyBy('store_order_item_id');
        $returned = [];
        StoreOrderReturn::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $order->store_id)->where('store_order_id', $order->id)
            ->where('status', '!=', 'rejected')->get()->each(function (StoreOrderReturn $row) use (&$returned) {
                foreach ($row->items ?? [] as $item) {
                    $id = (int) $item['store_order_item_id'];
                    $returned[$id] = ($returned[$id] ?? 0) + (int) $item['quantity'];
                }
            });

        return $this->items($order)->map(function (StoreOrderItem $item) use ($refunded, $returned) {
            $used = max((int) ($refunded[$item->id]['refunded_quantity'] ?? 0), $returned[$item->id] ?? 0);

            return ['store_order_item_id' => $item->id, 'name' => $item->name, 'variant_name' => $item->variant_name, 'quantity' => $item->quantity,
                'returnable_quantity' => $item->digital_delivery !== null ? 0 : max(0, $item->quantity - $used)];
        })->values()->all();
    }

    /** @return Collection<int, StoreOrderItem> */
    private function items(StoreOrder $order): Collection
    {
        return StoreOrderItem::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $order->store_id)->where('store_order_id', $order->id)->orderBy('id')->get();
    }

    /** @return array<int, string> */
    private function lineNames(int $storeId, int $orderId): array
    {
        return StoreOrderItem::query()->withoutGlobalScopes()->where('store_id', $storeId)->where('store_order_id', $orderId)->pluck('name', 'id')->all();
    }

    private function timeline(StoreOrder $order, ?int $actorId, string $source, string $note): void
    {
        StoreOrderStatusChange::query()->create(['store_id' => $order->store_id, 'store_order_id' => $order->id, 'from_status' => $order->status,
            'to_status' => $order->status, 'actor_id' => $actorId, 'notes' => $note, 'source' => $source]);
    }

    private function ineligibleMessage(string $reason): string
    {
        return match ($reason) {
            'disabled' => 'This store does not accept return requests online. Please contact the store.',
            'not_delivered' => 'Returns can be requested once the order has been delivered.',
            'window_expired' => 'The return window for this order has ended.',
            'open_return' => 'This order already has an open return request.',
            default => 'There is nothing left to return on this order.',
        };
    }
}
