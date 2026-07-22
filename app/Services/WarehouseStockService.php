<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\Rbac\UserScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseStockService
{
    public function assertCanManageProduct(User $user, int $productId): Product
    {
        $product = Product::query()->findOrFail($productId);
        if (! UserScope::isAdmin($user)
            && (int) $product->user_id !== UserScope::effectiveMerchantUserId($user)) {
            abort(403, 'Forbidden.');
        }

        return $product;
    }

    public function createLine(User $user, int $warehouseId, int $productId, int $qty = 0, int $reservedQty = 0): WarehouseInventory
    {
        $this->assertCanManageProduct($user, $productId);
        $warehouse = Warehouse::query()->active()->findOrFail($warehouseId);
        if ($qty < 0 || $reservedQty < 0 || $reservedQty > $qty) {
            throw ValidationException::withMessages([
                'qty' => $reservedQty > $qty ? ['Reserved quantity cannot exceed quantity.'] : ['Invalid quantities.'],
            ]);
        }

        if (WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $productId)->exists()) {
            throw ValidationException::withMessages([
                'product_id' => ['This product already has a row for this warehouse.'],
            ]);
        }

        $inv = WarehouseInventory::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $productId,
            'qty' => $qty,
            'reserved_qty' => $reservedQty,
        ]);
        InventoryAlertService::checkLine($inv->fresh(), null);

        return $inv->fresh();
    }

    public function updateLine(WarehouseInventory $inventory, User $user, int $qty, int $reservedQty): WarehouseInventory
    {
        $this->assertCanManageProduct($user, (int) $inventory->product_id);
        if ($qty < 0 || $reservedQty < 0) {
            throw ValidationException::withMessages(['qty' => ['Quantities must be non-negative.']]);
        }
        if ($reservedQty > $qty) {
            throw ValidationException::withMessages([
                'reserved_qty' => ['Reserved quantity cannot exceed quantity.'],
            ]);
        }
        $oldAvail = max(0, (int) $inventory->qty - (int) $inventory->reserved_qty);
        $inventory->update([
            'qty' => $qty,
            'reserved_qty' => $reservedQty,
        ]);
        $fresh = $inventory->fresh();
        InventoryAlertService::checkLine($fresh, $oldAvail);

        return $fresh;
    }

    /**
     * @param  array<int, array{product_id: int, qty: int}>  $lines
     */
    public function transfer(
        User $user,
        int $fromWarehouseId,
        int $toWarehouseId,
        array $lines,
        ?string $notes = null
    ): StockTransfer {
        if ($fromWarehouseId === $toWarehouseId) {
            throw ValidationException::withMessages([
                'to_warehouse_id' => ['Source and destination warehouses must differ.'],
            ]);
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => ['At least one line is required.'],
            ]);
        }

        foreach ($lines as $i => $line) {
            if (($line['qty'] ?? 0) < 1) {
                throw ValidationException::withMessages([
                    "lines.$i.qty" => ['Quantity must be at least 1.'],
                ]);
            }
            $this->assertCanManageProduct($user, (int) $line['product_id']);
        }

        Warehouse::query()->active()->findOrFail($fromWarehouseId);
        Warehouse::query()->active()->findOrFail($toWarehouseId);

        return DB::transaction(function () use ($user, $fromWarehouseId, $toWarehouseId, $lines, $notes) {
            $transfer = StockTransfer::query()->create([
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'status' => 'completed',
                'notes' => $notes,
                'created_by_id' => $user->id,
            ]);

            foreach ($lines as $line) {
                $productId = (int) $line['product_id'];
                $moveQty = (int) $line['qty'];

                $fromRow = WarehouseInventory::query()
                    ->where('warehouse_id', $fromWarehouseId)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();

                if (! $fromRow) {
                    throw ValidationException::withMessages([
                        'lines' => ["No inventory for product #{$productId} in source warehouse."],
                    ]);
                }

                $available = max(0, (int) $fromRow->qty - (int) $fromRow->reserved_qty);
                if ($moveQty > $available) {
                    throw ValidationException::withMessages([
                        'lines' => ["Insufficient available stock for product #{$productId} (available: {$available})."],
                    ]);
                }

                $oldFromAvail = max(0, (int) $fromRow->qty - (int) $fromRow->reserved_qty);
                $fromRow->update(['qty' => (int) $fromRow->qty - $moveQty]);
                InventoryAlertService::checkLine($fromRow->fresh(), $oldFromAvail);

                $toRow = WarehouseInventory::query()
                    ->where('warehouse_id', $toWarehouseId)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();

                if ($toRow) {
                    $oldToAvail = max(0, (int) $toRow->qty - (int) $toRow->reserved_qty);
                    $toRow->update(['qty' => (int) $toRow->qty + $moveQty]);
                    InventoryAlertService::checkLine($toRow->fresh(), $oldToAvail);
                } else {
                    WarehouseInventory::query()->create([
                        'warehouse_id' => $toWarehouseId,
                        'product_id' => $productId,
                        'qty' => $moveQty,
                        'reserved_qty' => 0,
                    ]);
                    $toInv = WarehouseInventory::query()
                        ->where('warehouse_id', $toWarehouseId)
                        ->where('product_id', $productId)
                        ->first();
                    if ($toInv) {
                        InventoryAlertService::checkLine($toInv, null);
                    }
                }

                StockTransferLine::query()->create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $productId,
                    'qty' => $moveQty,
                ]);
            }

            return $transfer->load(['fromWarehouse', 'toWarehouse', 'lines.product']);
        });
    }
}
