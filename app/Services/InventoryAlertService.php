<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Models\WarehouseInventory;
use App\Notifications\InventoryThresholdNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class InventoryAlertService
{
    /**
     * Fire low/out-stock notifications when available quantity crosses thresholds.
     *
     * @param  ?int  $oldAvailable  null when the line was just created
     */
    public static function checkLine(WarehouseInventory $line, ?int $oldAvailable): void
    {
        $line->refresh();
        $threshold = (int) config('warehouse.low_stock_threshold', 5);
        $newAvail = max(0, (int) $line->qty - (int) $line->reserved_qty);

        $crossedToOut = $newAvail === 0 && ($oldAvailable === null || $oldAvailable > 0);
        $crossedToLow = $newAvail > 0 && $newAvail <= $threshold
            && ($oldAvailable === null || $oldAvailable > $threshold);

        if (! $crossedToOut && ! $crossedToLow) {
            return;
        }

        $type = $crossedToOut ? 'inventory_out' : 'inventory_low';

        $cacheKey = sprintf(
            'inv_alert:%d:%d:%s:%s',
            (int) $line->product_id,
            (int) $line->warehouse_id,
            $type,
            now()->toDateString()
        );
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, now()->endOfDay());

        $data = [
            'type' => $type,
            'product_id' => (int) $line->product_id,
            'warehouse_id' => (int) $line->warehouse_id,
            'qty_available' => $newAvail,
        ];

        foreach (self::recipientsForProduct((int) $line->product_id) as $user) {
            $user->notify(new InventoryThresholdNotification($data));
        }
    }

    /**
     * @return Collection<int, User>
     */
    private static function recipientsForProduct(int $productId): Collection
    {
        $product = Product::query()->find($productId);
        $users = User::role('Admin')->get();
        if ($product && $product->user_id) {
            $owner = User::query()->find((int) $product->user_id);
            if ($owner) {
                $users = $users->push($owner);
            }
        }

        return $users->unique('id')->values();
    }
}
