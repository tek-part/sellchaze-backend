<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreInventoryMovement;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Storefront stock only; warehouse/B2B inventory remains a separate ledger. */
class StoreInventory
{
    public function assertAvailable(Product|ProductVariant $stock, int $quantity): void
    {
        if ($quantity < 1 || $quantity > 999) {
            throw ValidationException::withMessages(['quantity' => 'Choose between 1 and 999 items.']);
        }
        if ($stock->track_inventory && $stock->stock_quantity - $stock->reserved_quantity < $quantity) {
            throw ValidationException::withMessages(['items' => 'The requested quantity is no longer available. Please review your cart.']);
        }
    }

    /** Called inside checkout's transaction with the stock row locked. */
    public function reserve(Product|ProductVariant $stock, StoreOrderItem $item): void
    {
        if (! $stock->track_inventory) {
            return;
        }
        $this->assertAvailable($stock, $item->quantity);
        // Conditional arithmetic guards the invariant even with a stale caller/model.
        $changed = $stock->newQuery()->where('store_id', $item->store_id)->whereKey($stock->id)
            ->where('track_inventory', true)->whereRaw('stock_quantity - reserved_quantity >= ?', [$item->quantity])
            ->increment('reserved_quantity', $item->quantity);
        if ($changed !== 1) {
            throw ValidationException::withMessages(['items' => 'The requested quantity is no longer available. Please review your cart.']);
        }
        $stock->refresh();
        $item->update(['inventory_status' => 'reserved']);
        $this->record($stock, 'reserved', 0, $item->quantity, $item->id);
        $this->flushAfterCommit($item->store_id);
    }

    /** The order row is locked by StoreOrderService before calling this method. */
    public function settle(StoreOrder $order, bool $shipped): void
    {
        $items = $order->items()->where('inventory_status', 'reserved')->orderBy('store_product_id')->orderBy('variant_id')->orderBy('id')->lockForUpdate()->get();
        foreach ($items as $item) {
            $product = Product::query()->where('store_id', $order->store_id)->whereKey($item->store_product_id)->lockForUpdate()->first();
            $stock = $item->variant_id === null ? $product : ProductVariant::query()->where('store_id', $order->store_id)
                ->where('store_product_id', $item->store_product_id)->whereKey($item->variant_id)->lockForUpdate()->first();
            if ($stock === null) {
                // Deletion cannot manufacture stock on a replacement product with the same SKU.
                $item->update(['inventory_status' => $shipped ? 'committed' : 'released']);

                continue;
            }
            if ($stock->reserved_quantity < $item->quantity || ($shipped && $stock->stock_quantity < $item->quantity)) {
                throw ValidationException::withMessages(['inventory' => 'Inventory reservations need reconciliation before changing this order.']);
            }
            $stock->reserved_quantity -= $item->quantity;
            if ($shipped) {
                $stock->stock_quantity -= $item->quantity;
            }
            $stock->save();
            $item->update(['inventory_status' => $shipped ? 'committed' : 'released']);
            $reason = $shipped ? ($item->digital_delivery !== null ? 'digital_committed' : 'shipped') : 'cancelled';
            $this->record($stock, $reason, $shipped ? -$item->quantity : 0, -$item->quantity, $item->id);
        }
        if ($items->isNotEmpty()) {
            $this->flushAfterCommit($order->store_id);
        }
    }

    public function adjust(Product|ProductVariant $stock, array $input, int $actorId, bool $flush = true): void
    {
        // Caller locks product then variant. Reject stale stock forms instead of losing changes.
        abort_if($stock->stock_quantity !== $input['expected_stock'] || $stock->reserved_quantity !== $input['expected_reserved'] || $stock->track_inventory !== $input['expected_tracking'], 409, 'Stock changed. Reload the inventory before saving.');
        if ($input['stock_quantity'] < $stock->reserved_quantity || (! $input['track_inventory'] && $stock->reserved_quantity > 0)) {
            throw ValidationException::withMessages(['stock_quantity' => 'Keep enough stock for reserved orders; fulfill or cancel those orders before disabling tracking.']);
        }
        $delta = $input['stock_quantity'] - $stock->stock_quantity;
        $stock->update(['track_inventory' => $input['track_inventory'], 'stock_quantity' => $input['stock_quantity']]);
        $this->record($stock, 'adjusted', $delta, 0, null, $actorId, $input['note'] ?? null);
        if ($flush) {
            $this->flushAfterCommit($stock->store_id);
        }
    }

    /** Caller holds the parent and variant locks. History keeps the removed identity. */
    public function recordVariantRemoval(ProductVariant $stock, int $actorId, ?string $note = null): void
    {
        if ($stock->reserved_quantity > 0) {
            throw ValidationException::withMessages(['inventory' => 'Fulfill or cancel reserved orders before deleting this option.']);
        }
        $quantity = $stock->stock_quantity;
        $stock->stock_quantity = 0;
        $this->record($stock, 'variant_deleted', -$quantity, 0, null, $actorId, $stock->name.($note ? ' — '.$note : ''));
    }

    private function record(Product|ProductVariant $stock, string $reason, int $delta, int $reservedDelta, ?int $itemId = null, ?int $actorId = null, ?string $note = null): void
    {
        StoreInventoryMovement::create(['store_id' => $stock->store_id, 'product_id' => $stock instanceof ProductVariant ? $stock->store_product_id : $stock->id,
            'variant_id' => $stock instanceof ProductVariant ? $stock->id : null, 'store_order_item_id' => $itemId, 'actor_id' => $actorId,
            'reason' => $reason, 'stock_delta' => $delta, 'reserved_delta' => $reservedDelta,
            'stock_after' => $stock->stock_quantity, 'reserved_after' => $stock->reserved_quantity, 'note' => $note]);
    }

    private function flushAfterCommit(int $storeId): void
    {
        DB::afterCommit(function () use ($storeId) {
            StorefrontService::forgetHomepage($storeId);
            app(StorefrontPageCache::class)->flushStore($storeId);
        });
    }
}
