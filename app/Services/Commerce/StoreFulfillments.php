<?php

namespace App\Services\Commerce;

use App\Models\Scopes\StoreScope;
use App\Models\StoreOrder;
use App\Models\StoreOrderFulfillment;
use App\Models\StoreOrderItem;
use App\Models\StoreOrderRefund;
use App\Models\StoreShipment;
use App\Services\Notifications\StoreNotificationSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Partial and split shipments. Each fulfillment ships some units of some physical lines: those units'
 * reservations become stock deductions (StoreInventory::commitPartial) and the order's
 * `fulfillment_status` follows (unfulfilled → partial → fulfilled). The first fulfillment moves the
 * order to `shipped`; once every unit shipped and every shipment was delivered the order is `delivered`.
 * The legacy "mark shipped" status action fulfils whatever is left in one untracked fulfillment.
 */
class StoreFulfillments
{
    /** Orders a merchant can (still) ship from. `confirmed` steps through processing like the carrier flow. */
    public const FULFILLABLE_STATUSES = ['confirmed', 'processing', 'waiting_for_pickup', 'shipped'];

    /** Order statuses meaning a digital-only order was handed over. */
    private const DIGITAL_DONE = ['delivered', 'request_refund', 'refund_in_progress', 'refunded'];

    public function __construct(private readonly StoreInventory $inventory) {}

    /** @return array<string, mixed> */
    public function summary(StoreOrder $order): array
    {
        $items = $this->items($order);
        $physical = $items->filter(fn (StoreOrderItem $item) => $item->digital_delivery === null)->values();
        $remaining = $physical->sum(fn (StoreOrderItem $item) => StoreInventory::unfulfilled($item));
        $names = $items->keyBy('id');

        return [
            'order_id' => $order->id,
            'order_status' => $order->status,
            'fulfillment_status' => $order->fulfillment_status ?? 'unfulfilled',
            'requires_shipping' => $order->requiresShipping(),
            'can_fulfill' => in_array($order->status, self::FULFILLABLE_STATUSES, true) && $remaining > 0,
            'unfulfilled_quantity' => $remaining,
            'items' => $physical->map(fn (StoreOrderItem $item) => [
                'store_order_item_id' => $item->id,
                'name' => $item->name,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'fulfilled_quantity' => StoreInventory::shippedUnits($item),
                'unfulfilled_quantity' => StoreInventory::unfulfilled($item),
            ])->all(),
            'fulfillments' => $order->fulfillments()->with('actor:id,name')->get()
                ->map(fn (StoreOrderFulfillment $row) => $this->present($row, $names))->values()->all(),
            'carriers' => collect(StoreOrderFulfillment::CARRIERS)->map(fn (array $carrier, string $key) => [
                'key' => $key, 'name' => $carrier['name'], 'tracking_url_template' => $carrier['template'] !== null,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array{items: array<int, array{store_order_item_id:int|string, quantity:int|string}>, carrier: string, carrier_name?: string|null,
     *     tracking_number?: string|null, tracking_url?: string|null, notify_customer?: bool, note?: string|null}  $data
     */
    public function create(StoreOrder $order, array $data, ?int $actorId = null): StoreOrderFulfillment
    {
        return DB::transaction(function () use ($order, $data, $actorId) {
            $this->lockOrder($order);
            if (! in_array($order->status, self::FULFILLABLE_STATUSES, true)) {
                throw ValidationException::withMessages(['order' => 'Confirm the order before fulfilling it. Delivered, cancelled or returned orders cannot ship more items.']);
            }
            if (! $order->requiresShipping()) {
                throw ValidationException::withMessages(['order' => 'This digital order does not need shipping.']);
            }
            $requested = [];
            foreach ($data['items'] as $row) {
                $id = (int) $row['store_order_item_id'];
                $requested[$id] = ($requested[$id] ?? 0) + (int) $row['quantity'];
            }
            $lines = $this->lockedLines($order, array_keys($requested));
            foreach ($requested as $id => $quantity) {
                $line = $lines->get($id);
                if ($line === null || $line->digital_delivery !== null) {
                    throw ValidationException::withMessages(['items' => 'One of the items is not a physical line of this order.']);
                }
                $open = StoreInventory::unfulfilled($line);
                if ($quantity < 1 || $quantity > $open) {
                    throw ValidationException::withMessages(['items' => "You can fulfil at most {$open} of {$line->name}."]);
                }
            }

            $fulfillment = StoreOrderFulfillment::query()->create([
                'store_id' => $order->store_id, 'store_order_id' => $order->id, 'status' => 'in_transit',
                'carrier' => $data['carrier'], 'carrier_name' => $this->clean($data['carrier_name'] ?? null),
                'tracking_number' => $this->clean($data['tracking_number'] ?? null), 'custom_tracking_url' => $this->clean($data['tracking_url'] ?? null),
                'items' => $lines->only(array_keys($requested))->map(fn (StoreOrderItem $line) => ['store_order_item_id' => $line->id, 'quantity' => $requested[$line->id]])->values()->all(),
                'shipped_at' => now(), 'notify_customer' => (bool) ($data['notify_customer'] ?? true), 'note' => $this->clean($data['note'] ?? null),
                'source' => 'manual', 'actor_id' => $actorId, 'version' => 1,
            ]);
            $note = 'Fulfillment #'.$fulfillment->id.' on order '.$order->order_number;
            foreach ($lines->only(array_keys($requested)) as $line) {
                $this->inventory->commitPartial($line, $requested[$line->id], $actorId, $note);
            }
            $this->recompute($order);
            $this->shipOrder($order, $actorId, $fulfillment);
            $this->announce($order, $fulfillment, 'created');

            return $fulfillment;
        });
    }

    /**
     * The legacy "mark shipped"/"mark delivered" path: fulfil every unit still waiting in one
     * fulfillment (linked to the order's Bosta shipment when there is one). The order is locked by
     * StoreOrderService::transition().
     */
    public function fulfilRemaining(StoreOrder $order, ?int $actorId, ?string $source, bool $notify): ?StoreOrderFulfillment
    {
        $lines = $this->lockedLines($order)->filter(fn (StoreOrderItem $line) => StoreInventory::unfulfilled($line) > 0);
        if ($lines->isEmpty()) {
            return null;
        }
        $shipment = StoreShipment::query()->where('store_id', $order->store_id)->where('store_order_id', $order->id)->where('status', '!=', 'rejected')->first();
        $fulfillment = StoreOrderFulfillment::query()->create([
            'store_id' => $order->store_id, 'store_order_id' => $order->id, 'status' => 'in_transit',
            'carrier' => $shipment ? 'bosta' : 'other', 'tracking_number' => $shipment?->tracking_number,
            'items' => $lines->map(fn (StoreOrderItem $line) => ['store_order_item_id' => $line->id, 'quantity' => StoreInventory::unfulfilled($line)])->values()->all(),
            'shipped_at' => now(), 'notify_customer' => $notify, 'source' => $source === 'carrier' ? 'carrier' : 'status',
            'actor_id' => $actorId, 'store_shipment_id' => $shipment?->id, 'version' => 1,
        ]);
        $note = 'Fulfillment #'.$fulfillment->id.' on order '.$order->order_number;
        foreach ($lines as $line) {
            $this->inventory->commitPartial($line, StoreInventory::unfulfilled($line), $actorId, $note);
        }
        $this->recompute($order);
        $this->announce($order, $fulfillment, 'created');

        return $fulfillment;
    }

    /** The order reached `delivered` directly: every open shipment is delivered too. Order locked by the caller. */
    public function markAllDelivered(StoreOrder $order): void
    {
        $open = StoreOrderFulfillment::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $order->store_id)
            ->where('store_order_id', $order->id)->whereIn('status', ['pending', 'in_transit'])->orderBy('id')->lockForUpdate()->get();
        foreach ($open as $fulfillment) {
            $fulfillment->fill(['status' => 'delivered', 'delivered_at' => now(), 'version' => $fulfillment->version + 1])->save();
            app(StoreOrderWebhooks::class)->fulfillmentChanged($order, $fulfillment, 'updated');
        }
    }

    /**
     * Tracking edits and delivery outcomes. A new tracking number notifies the customer again when the
     * fulfillment asks for it; delivering the last shipment of a fully fulfilled order delivers the order.
     *
     * @param  array{carrier?: string, carrier_name?: string|null, tracking_number?: string|null, tracking_url?: string|null,
     *     notify_customer?: bool, note?: string|null, status?: string}  $data
     */
    public function update(StoreOrderFulfillment $fulfillment, array $data, ?int $actorId = null): StoreOrderFulfillment
    {
        return DB::transaction(function () use ($fulfillment, $data, $actorId) {
            $order = StoreOrder::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $fulfillment->store_id)->whereKey($fulfillment->store_order_id)->firstOrFail();
            $this->lockOrder($order);
            $current = $this->lockedFulfillment($fulfillment);
            if ($current->status === 'cancelled') {
                throw ValidationException::withMessages(['fulfillment' => 'This fulfillment was cancelled.']);
            }
            $previousTracking = (string) $current->tracking_number;
            foreach (['carrier' => 'carrier', 'carrier_name' => 'carrier_name', 'tracking_number' => 'tracking_number', 'tracking_url' => 'custom_tracking_url', 'note' => 'note'] as $input => $column) {
                if (array_key_exists($input, $data)) {
                    $current->{$column} = $input === 'carrier' ? $data[$input] : $this->clean($data[$input]);
                }
            }
            if (array_key_exists('notify_customer', $data)) {
                $current->notify_customer = (bool) $data['notify_customer'];
            }
            $delivered = false;
            if (isset($data['status']) && $data['status'] !== $current->status) {
                if ($current->status === 'delivered') {
                    throw ValidationException::withMessages(['status' => 'A delivered fulfillment cannot change status.']);
                }
                if (! in_array($order->status, ['shipped', 'delivered'], true)) {
                    throw ValidationException::withMessages(['status' => 'This order is no longer in delivery.']);
                }
                $current->status = $data['status'];
                $current->delivered_at = $data['status'] === 'delivered' ? now() : null;
                $delivered = $data['status'] === 'delivered';
            }
            if (! $current->isDirty()) {
                return $current;
            }
            $current->version++;
            $current->save();
            $tracking = (string) $current->tracking_number;
            if ($tracking !== '' && $tracking !== $previousTracking && $current->notify_customer && $order->status === 'shipped') {
                app(StoreNotificationSettings::class)->schedule($order, 'shipping_confirmation', null, false, $current);
            }
            app(StoreOrderWebhooks::class)->fulfillmentChanged($order, $current, 'updated');
            if ($delivered) {
                $this->deliverOrderWhenComplete($order, $actorId);
            }

            return $current;
        });
    }

    /** Units go back to unfulfilled and their stock is held for the order again. */
    public function cancel(StoreOrderFulfillment $fulfillment, ?int $actorId = null): StoreOrderFulfillment
    {
        return DB::transaction(function () use ($fulfillment, $actorId) {
            $order = StoreOrder::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $fulfillment->store_id)->whereKey($fulfillment->store_order_id)->firstOrFail();
            $this->lockOrder($order);
            $current = $this->lockedFulfillment($fulfillment);
            if (in_array($current->status, ['cancelled', 'delivered'], true)) {
                throw ValidationException::withMessages(['fulfillment' => $current->status === 'delivered' ? 'Delivered shipments cannot be cancelled. Record a return instead.' : 'This fulfillment was already cancelled.']);
            }
            if ($order->status !== 'shipped') {
                throw ValidationException::withMessages(['fulfillment' => 'Shipments can only be cancelled while the order is shipped.']);
            }
            $refunded = StoreOrderRefund::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $order->store_id)->where('store_order_id', $order->id)
                ->get()->contains(fn (StoreOrderRefund $refund) => ! empty($refund->items));
            if ($refunded) {
                throw ValidationException::withMessages(['fulfillment' => 'Items on this order were refunded. Record a return instead of cancelling the shipment.']);
            }
            $quantities = collect($current->items)->mapWithKeys(fn (array $row) => [(int) $row['store_order_item_id'] => (int) $row['quantity']]);
            $note = 'Fulfillment #'.$current->id.' cancelled on order '.$order->order_number;
            foreach ($this->lockedLines($order, $quantities->keys()->all()) as $line) {
                $this->inventory->uncommitPartial($line, $quantities[$line->id], $actorId, $note);
            }
            $current->fill(['status' => 'cancelled', 'cancelled_at' => now(), 'version' => $current->version + 1])->save();
            $this->recompute($order);
            $active = StoreOrderFulfillment::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $order->store_id)
                ->where('store_order_id', $order->id)->whereIn('status', StoreOrderFulfillment::ACTIVE)->exists();
            if (! $active) {
                $this->unship($order, $actorId);
            }
            app(StoreOrderWebhooks::class)->fulfillmentChanged($order, $current, 'cancelled');

            return $current;
        });
    }

    /** Persist the order's fulfillment status from its lines. The caller holds the order lock. */
    public function recompute(StoreOrder $order): void
    {
        $physical = $this->items($order)->filter(fn (StoreOrderItem $item) => $item->digital_delivery === null);
        if ($physical->isEmpty()) {
            $status = in_array($order->status, self::DIGITAL_DONE, true) ? 'fulfilled' : 'unfulfilled';
        } else {
            $shipped = $physical->sum(fn (StoreOrderItem $item) => StoreInventory::shippedUnits($item));
            $open = $physical->sum(fn (StoreOrderItem $item) => StoreInventory::unfulfilled($item));
            $status = $shipped === 0 ? 'unfulfilled' : ($open > 0 ? 'partial' : 'fulfilled');
        }
        if ($order->fulfillment_status !== $status) {
            $order->fulfillment_status = $status;
            $order->save();
        }
    }

    /**
     * @param  Collection<int, StoreOrderItem>|null  $names  order lines keyed by id
     * @return array<string, mixed>
     */
    public function present(StoreOrderFulfillment $fulfillment, ?Collection $names = null, bool $merchant = true): array
    {
        $names ??= StoreOrderItem::query()->withoutGlobalScopes()->where('store_id', $fulfillment->store_id)
            ->where('store_order_id', $fulfillment->store_order_id)->get()->keyBy('id');
        $data = [
            'id' => $fulfillment->id,
            'status' => $fulfillment->status,
            'carrier' => $fulfillment->carrier,
            'carrier_name' => $fulfillment->carrierLabel(),
            'tracking_number' => $fulfillment->tracking_number,
            'tracking_url' => $fulfillment->trackingUrl(),
            'items' => collect($fulfillment->items)->map(fn (array $row) => [
                'store_order_item_id' => (int) $row['store_order_item_id'],
                'name' => $names->get((int) $row['store_order_item_id'])?->name,
                'variant_name' => $names->get((int) $row['store_order_item_id'])?->variant_name,
                'quantity' => (int) $row['quantity'],
            ])->values()->all(),
            'shipped_at' => $fulfillment->shipped_at,
            'delivered_at' => $fulfillment->delivered_at,
        ];
        if (! $merchant) {
            return $data;
        }

        return $data + [
            'custom_carrier_name' => $fulfillment->carrier_name,
            'custom_tracking_url' => $fulfillment->custom_tracking_url,
            'cancelled_at' => $fulfillment->cancelled_at,
            'notify_customer' => $fulfillment->notify_customer,
            'note' => $fulfillment->note,
            'source' => $fulfillment->source,
            'actor' => $fulfillment->relationLoaded('actor') ? $fulfillment->actor?->name : null,
            'store_shipment_id' => $fulfillment->store_shipment_id,
            'version' => $fulfillment->version,
            'created_at' => $fulfillment->created_at,
            'updated_at' => $fulfillment->updated_at,
        ];
    }

    /** Customer view: shipments that are (or were) on their way. */
    public function forCustomer(StoreOrder $order): array
    {
        $names = $this->items($order)->keyBy('id');

        return $order->fulfillments()->whereIn('status', StoreOrderFulfillment::ACTIVE)->get()
            ->map(fn (StoreOrderFulfillment $row) => $this->present($row, $names, false))->values()->all();
    }

    /** First shipment: the order moves to shipped through the lifecycle (timeline, webhooks, digital lines). */
    private function shipOrder(StoreOrder $order, ?int $actorId, StoreOrderFulfillment $fulfillment): void
    {
        $orders = app(StoreOrderService::class);
        if ($order->status === 'confirmed') {
            $orders->transition($order, 'processing', $actorId, null, 'fulfillment', true, true);
        }
        if (in_array($order->status, ['processing', 'waiting_for_pickup'], true)) {
            $orders->transition($order, 'shipped', $actorId, 'Fulfillment #'.$fulfillment->id, 'fulfillment', true, true);
        }
    }

    private function deliverOrderWhenComplete(StoreOrder $order, ?int $actorId): void
    {
        $order->refresh();
        if ($order->status !== 'shipped' || $order->fulfillment_status !== 'fulfilled') {
            return;
        }
        $undelivered = StoreOrderFulfillment::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $order->store_id)
            ->where('store_order_id', $order->id)->whereIn('status', ['pending', 'in_transit', 'failed'])->exists();
        if (! $undelivered) {
            app(StoreOrderService::class)->transition($order, 'delivered', $actorId, 'All shipments delivered', 'fulfillment', true, true);
        }
    }

    /** Every shipment was cancelled: the order goes back to where it was before it shipped. */
    private function unship(StoreOrder $order, ?int $actorId): void
    {
        $previous = $order->statusChanges()->where('to_status', 'shipped')->where('from_status', '!=', 'shipped')->reorder('id', 'desc')->value('from_status');
        $previous = in_array($previous, ['processing', 'waiting_for_pickup'], true) ? $previous : 'processing';
        $order->status = $previous;
        $order->save();
        $change = $order->statusChanges()->create(['store_id' => $order->store_id, 'from_status' => 'shipped', 'to_status' => $previous,
            'actor_id' => $actorId, 'notes' => 'All shipments cancelled', 'source' => 'fulfillment']);
        app(StoreOrderWebhooks::class)->statusChanged($order, 'shipped', (int) $change->id);
        DB::afterCommit(fn () => StoreAnalyticsService::forget($order->store_id));
    }

    private function announce(StoreOrder $order, StoreOrderFulfillment $fulfillment, string $action): void
    {
        if ($fulfillment->notify_customer) {
            app(StoreNotificationSettings::class)->schedule($order, 'shipping_confirmation', null, false, $fulfillment);
        }
        app(StoreOrderWebhooks::class)->fulfillmentChanged($order, $fulfillment, $action);
    }

    private function lockOrder(StoreOrder $order): void
    {
        $current = StoreOrder::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $order->store_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
        $order->setRawAttributes($current->getAttributes(), true);
    }

    private function lockedFulfillment(StoreOrderFulfillment $fulfillment): StoreOrderFulfillment
    {
        return StoreOrderFulfillment::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $fulfillment->store_id)
            ->whereKey($fulfillment->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Current lines, locked in the product/variant order every inventory path uses.
     *
     * @param  list<int>|null  $ids
     * @return Collection<int, StoreOrderItem>
     */
    private function lockedLines(StoreOrder $order, ?array $ids = null): Collection
    {
        return StoreOrderItem::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $order->store_id)->where('store_order_id', $order->id)
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))->whereNull('digital_delivery')
            ->orderBy('store_product_id')->orderBy('variant_id')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /** @return Collection<int, StoreOrderItem> */
    private function items(StoreOrder $order): Collection
    {
        return StoreOrderItem::query()->withoutGlobalScope(StoreScope::class)->where('store_id', $order->store_id)
            ->where('store_order_id', $order->id)->orderBy('id')->get();
    }

    private function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
