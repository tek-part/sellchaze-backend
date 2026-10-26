<?php

namespace App\Support\Commerce;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fills the fulfillment counters for orders placed before fulfillments existed. Walks store_orders by
 * id in small chunks (archived orders included) and updates their lines by store_order_id, so every
 * statement touches a few hundred indexed rows instead of locking the whole items table.
 *
 * Idempotent: only counters that are still zero are written, and orders that already have fulfillment
 * rows (the new code owns them) are left alone, so a failed or repeated run is safe.
 */
class FulfillmentBackfill
{
    /** Statuses meaning the old all-at-once flow already shipped everything that was going to ship. */
    public const SHIPPED = ['shipped', 'delivered', 'returning_from_delivery', 'request_refund', 'refund_in_progress', 'refunded'];

    /** @return array{orders:int, fulfilled_lines:int, released_lines:int} */
    public function run(int $chunk = 500): array
    {
        $totals = ['orders' => 0, 'fulfilled_lines' => 0, 'released_lines' => 0];
        DB::table('store_orders')->select(['id', 'status'])->orderBy('id')->chunkById(max(1, $chunk), function (Collection $orders) use (&$totals) {
            $ids = $orders->pluck('id')->all();
            $owned = DB::table('store_order_fulfillments')->whereIn('store_order_id', $ids)->distinct()->pluck('store_order_id')->all();
            $shipped = $orders->filter(fn ($order) => in_array($order->status, self::SHIPPED, true) && ! in_array($order->id, $owned))->pluck('id')->all();
            $cancelled = $orders->where('status', 'cancelled')->pluck('id')->all();
            $lines = fn (array $orderIds) => DB::table('store_order_items')->whereIn('store_order_id', $orderIds)->whereNull('superseded_at')
                ->where('quantity', '>', 0)->where('fulfilled_quantity', 0)->where('released_quantity', 0);

            if ($shipped !== []) {
                $totals['fulfilled_lines'] += $lines($shipped)->whereNull('digital_delivery')->update(['fulfilled_quantity' => DB::raw('quantity')]);
                DB::table('store_orders')->whereIn('id', $shipped)->where('fulfillment_status', '!=', 'fulfilled')->update(['fulfillment_status' => 'fulfilled']);
            }
            if ($cancelled !== []) {
                $totals['released_lines'] += $lines($cancelled)->update(['released_quantity' => DB::raw('quantity')]);
            }
            // Lines an order edit removed (quantity 0) or released outside a cancellation.
            $totals['released_lines'] += $lines($ids)->where('inventory_status', 'released')->update(['released_quantity' => DB::raw('quantity')]);
            $totals['orders'] += count($ids);
        }, 'id');

        return $totals;
    }
}
