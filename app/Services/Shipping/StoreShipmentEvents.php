<?php

namespace App\Services\Shipping;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreShipment;
use App\Models\StoreShipmentEvent;
use App\Services\Commerce\StoreOrderService;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Support\Facades\DB;

class StoreShipmentEvents
{
    public const STATES = [10 => 'Pickup requested', 11 => 'Waiting for route', 20 => 'Route assigned', 21 => 'Picked up from business', 22 => 'Picking up from consignee', 23 => 'Picked up from consignee', 24 => 'Received at warehouse', 25 => 'Fulfilled', 30 => 'In transit', 40 => 'Picking up cash', 41 => 'Out for delivery', 45 => 'Delivered', 46 => 'Returned to business', 47 => 'Delivery exception', 48 => 'Terminated', 49 => 'Cancelled', 60 => 'Returned to stock', 100 => 'Lost', 101 => 'Damaged', 102 => 'Investigation', 103 => 'Awaiting action', 104 => 'Archived', 105 => 'On hold'];

    /** Credentials are unique to this shipment and remain hidden/encrypted. */
    public function webhookUrl(string $reference): ?string
    {
        $base = rtrim((string) config('services.bosta.webhook_base_url'), '/');
        $parts = parse_url($base);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return $base.'/api/v1/carriers/bosta/webhook/'.rawurlencode($reference);
    }

    /** Identity has been authenticated by the endpoint/client before applying any state. */
    public function apply(StoreShipment $shipment, string $externalId, string $tracking, int $state, ?int $timeMs, string $source, ?int $expectedRevision = null): StoreShipment
    {
        return DB::transaction(function () use ($shipment, $externalId, $tracking, $state, $timeMs, $source, $expectedRevision) {
            // Match dispatch lock order to avoid order/shipment lock inversion.
            $order = StoreOrder::withoutGlobalScopes()->where('store_id', $shipment->store_id)->whereKey($shipment->store_order_id)->lockForUpdate()->firstOrFail();
            $current = StoreShipment::where('store_id', $shipment->store_id)->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if (($current->external_id && $current->external_id !== $externalId) || ($current->tracking_number && $current->tracking_number !== $tracking)) {
                throw new CarrierException(true, 'shipment_mismatch');
            }
            $eventKey = hash('sha256', implode('|', [$current->id, $externalId, $tracking, $state, $timeMs ?? 'untimed', $timeMs === null ? $current->carrier_revision : '']));
            if (StoreShipmentEvent::where('store_id', $current->store_id)->where('event_key', $eventKey)->exists()) {
                return $current;
            }
            $ignored = null;
            if (in_array($current->carrier_state, [45, 46, 48, 49, 60, 100, 101], true) && $current->carrier_state !== $state) {
                $ignored = 'terminal_state';
            } elseif ($timeMs !== null && $current->last_event_at_ms !== null && $timeMs <= $current->last_event_at_ms) {
                $ignored = 'older_event';
            } elseif ($source !== 'webhook' && $expectedRevision !== null && $current->carrier_revision !== $expectedRevision) {
                $ignored = 'newer_event_received';
            } elseif ($source !== 'webhook' && $timeMs === null && $current->last_event_at_ms !== null) {
                $ignored = 'missing_carrier_time';
            }
            // Untimed reads of an unchanged state do not inflate the timeline.
            if ($timeMs === null && $current->carrier_state === $state && $current->status === 'created') {
                $current->update(['synced_at' => now()]);

                return $current;
            }
            StoreShipmentEvent::create(['store_id' => $current->store_id, 'store_shipment_id' => $current->id, 'event_key' => $eventKey,
                'carrier_state' => $state, 'carrier_time_ms' => $timeMs, 'source' => $source, 'applied' => $ignored === null, 'ignored_reason' => $ignored]);
            if ($ignored !== null) {
                return $current;
            }
            $current->update(['status' => 'created', 'external_id' => $externalId, 'tracking_number' => $tracking, 'carrier_state' => $state,
                'carrier_state_label' => self::STATES[$state] ?? 'Carrier state '.$state, 'last_event_at_ms' => $timeMs,
                'carrier_revision' => $current->carrier_revision + 1, 'error_code' => null, 'synced_at' => now()]);
            $this->advanceOrder($order, $state);

            return $current;
        });
    }

    private function advanceOrder(StoreOrder $order, int $state): void
    {
        // Only SEND states with unambiguous physical progress advance fulfillment.
        // Payment settlement, exceptions and returns are separate workflows.
        $target = $state === 45 ? 'delivered' : (in_array($state, [21, 24, 30, 41], true) ? 'shipped' : null);
        if ($target === null || ! in_array($order->status, ['confirmed', 'processing', 'shipped'], true)) {
            return;
        }
        $tenant = app(CurrentStore::class);
        $previous = $tenant->get();
        $tenant->set(Store::findOrFail($order->store_id));
        try {
            $sequence = ['confirmed', 'processing', 'shipped', 'delivered'];
            $orders = app(StoreOrderService::class);
            while (array_search($order->status, $sequence, true) < array_search($target, $sequence, true)) {
                $next = $sequence[array_search($order->status, $sequence, true) + 1];
                $orders->transition($order, $next, null, 'Bosta: '.(self::STATES[$state] ?? (string) $state), 'carrier');
            }
        } finally {
            $tenant->set($previous);
        }
    }
}
