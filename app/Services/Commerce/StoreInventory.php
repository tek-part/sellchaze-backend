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

    /** Order editing locks the catalog rows before changing a retained snapshot quantity. */
    public function editQuantity(StoreOrderItem $item, Product|ProductVariant|null $stock, int $quantity, int $actorId): void
    {
        if ($quantity === $item->quantity) {
            return;
        }
        abort_unless(! in_array($item->inventory_status, ['committed', 'released'], true), 422, 'Settled inventory cannot be edited.');
        if ($item->inventory_status === 'reserved') {
            if ($stock) {
                abort_unless($stock->reserved_quantity >= $item->quantity, 422, 'Inventory reservations need reconciliation.');
                $delta = $quantity - $item->quantity;
                if ($delta > 0) {
                    $this->assertAvailable($stock, $delta);
                }
                $stock->reserved_quantity += $delta;
                $stock->save();
                $this->record($stock, 'order_edited', 0, $delta, $item->id, $actorId);
                $this->flushAfterCommit($item->store_id);
            } else {
                abort_unless($quantity === 0, 422, 'This reserved product is unavailable. Remove it or reconcile its inventory.');
            }
            if ($quantity === 0) {
                $item->inventory_status = 'released';
            }
        } elseif ($quantity > 0 && $stock?->track_inventory) {
            $this->assertAvailable($stock, $quantity);
            $stock->reserved_quantity += $quantity;
            $stock->save();
            $item->inventory_status = 'reserved';
            $this->record($stock, 'order_edited', 0, $quantity, $item->id, $actorId);
            $this->flushAfterCommit($item->store_id);
        }
    }

    /**
     * Settle every unit still held for the order: ship (commit) or release it. The order row is locked
     * by the caller. Units already fulfilled or released are never counted twice; `$digitalOnly`
     * settles digital lines alone (physical lines ship through fulfillments).
     */
    public function settle(StoreOrder $order, bool $shipped, bool $digitalOnly = false): void
    {
        $items = $order->items()->where(fn ($query) => $query->where('inventory_status', 'reserved')->orWhereNull('inventory_status'))
            ->when($digitalOnly, fn ($query) => $query->whereNotNull('digital_delivery'))
            ->orderBy('store_product_id')->orderBy('variant_id')->orderBy('id')->lockForUpdate()->get();
        $touched = false;
        foreach ($items as $item) {
            $units = max(0, $item->quantity - (int) $item->fulfilled_quantity - (int) $item->released_quantity);
            $tracked = $item->inventory_status === 'reserved';
            $stock = $tracked && $units > 0 ? $this->lockedStock($item) : null;
            if ($stock !== null) {
                if ($stock->reserved_quantity < $units || ($shipped && $stock->stock_quantity < $units)) {
                    throw ValidationException::withMessages(['inventory' => 'Inventory reservations need reconciliation before changing this order.']);
                }
                $stock->reserved_quantity -= $units;
                if ($shipped) {
                    $stock->stock_quantity -= $units;
                }
                $stock->save();
                $reason = $shipped ? ($item->digital_delivery !== null ? 'digital_committed' : 'shipped') : 'cancelled';
                $this->record($stock, $reason, $shipped ? -$units : 0, -$units, $item->id);
                $touched = true;
            }
            // Deletion cannot manufacture stock on a replacement product with the same SKU: counters still move.
            if ($shipped) {
                $item->fulfilled_quantity = (int) $item->fulfilled_quantity + $units;
            } else {
                $item->released_quantity = (int) $item->released_quantity + $units;
            }
            if ($tracked) {
                $item->inventory_status = $shipped || $item->fulfilled_quantity > 0 ? 'committed' : 'released';
            }
            if ($item->isDirty()) {
                $item->save();
            }
        }
        if ($touched) {
            $this->flushAfterCommit($order->store_id);
        }
    }

    /** Physical units of a line still waiting to ship. */
    public static function unfulfilled(StoreOrderItem $item): int
    {
        if ($item->digital_delivery !== null || in_array($item->inventory_status, ['committed', 'released'], true)) {
            return 0;
        }

        return max(0, $item->quantity - (int) $item->fulfilled_quantity - (int) $item->released_quantity);
    }

    /** Units whose stock left the shelf. Lines committed before fulfillments existed shipped in full. */
    public static function shippedUnits(StoreOrderItem $item): int
    {
        $fulfilled = (int) $item->fulfilled_quantity;

        return $fulfilled === 0 && $item->inventory_status === 'committed' ? $item->quantity : $fulfilled;
    }

    /**
     * Ship `$quantity` units of a line through a fulfillment: the reservation becomes a stock deduction.
     * The caller holds the order lock and checked the units are unfulfilled.
     */
    public function commitPartial(StoreOrderItem $item, int $quantity, ?int $actorId = null, ?string $note = null): void
    {
        if ($quantity < 1 || $quantity > self::unfulfilled($item)) {
            throw ValidationException::withMessages(['items' => 'You can fulfil at most '.self::unfulfilled($item)." of {$item->name}."]);
        }
        if ($item->inventory_status === 'reserved' && ($stock = $this->lockedStock($item)) !== null) {
            if ($stock->reserved_quantity < $quantity || $stock->stock_quantity < $quantity) {
                throw ValidationException::withMessages(['inventory' => 'Inventory reservations need reconciliation before fulfilling this order.']);
            }
            $stock->reserved_quantity -= $quantity;
            $stock->stock_quantity -= $quantity;
            $stock->save();
            $this->record($stock, 'shipped', -$quantity, -$quantity, $item->id, $actorId, $note);
            $this->flushAfterCommit($item->store_id);
        }
        $item->fulfilled_quantity = (int) $item->fulfilled_quantity + $quantity;
        if ($item->inventory_status === 'reserved' && self::unfulfilled($item) === 0) {
            $item->inventory_status = 'committed';
        }
        $item->save();
    }

    /**
     * Undo a fulfillment's units on a line: stock goes back on the shelf and is held for the order again.
     * Lines whose catalog row stopped tracking stock simply become untracked.
     */
    public function uncommitPartial(StoreOrderItem $item, int $quantity, ?int $actorId = null, ?string $note = null): void
    {
        if ($quantity < 1 || $quantity > (int) $item->fulfilled_quantity) {
            throw ValidationException::withMessages(['items' => 'This fulfillment no longer matches the order lines. Reload the order.']);
        }
        if (in_array($item->inventory_status, ['reserved', 'committed'], true)) {
            $stock = $this->lockedStock($item);
            if ($stock !== null && $stock->track_inventory) {
                $stock->stock_quantity += $quantity;
                $stock->reserved_quantity += $quantity;
                $stock->save();
                $this->record($stock, 'fulfillment_cancelled', $quantity, $quantity, $item->id, $actorId, $note);
                $this->flushAfterCommit($item->store_id);
                $item->inventory_status = 'reserved';
            } else {
                $item->inventory_status = $stock === null ? 'reserved' : null;
            }
        }
        $item->fulfilled_quantity = (int) $item->fulfilled_quantity - $quantity;
        $item->save();
    }

    /**
     * Refunded units come out of the units that have not shipped yet (their hold is released, they will
     * never ship), then out of shipped units, which go back on the shelf on request but never more than
     * `$restockable`. Returns the units restocked. The caller holds the order lock.
     */
    public function refundUnits(StoreOrderItem $item, int $quantity, bool $restock, int $restockable, ?int $actorId = null, ?string $note = null): int
    {
        $release = min($quantity, self::unfulfilled($item));
        if ($release > 0) {
            if ($item->inventory_status === 'reserved' && ($stock = $this->lockedStock($item)) !== null) {
                if ($stock->reserved_quantity < $release) {
                    throw ValidationException::withMessages(['inventory' => 'Inventory reservations need reconciliation before refunding this order.']);
                }
                $stock->reserved_quantity -= $release;
                $stock->save();
                $this->record($stock, 'refund_released', 0, -$release, $item->id, $actorId, $note);
                $this->flushAfterCommit($item->store_id);
            }
            $item->released_quantity = (int) $item->released_quantity + $release;
            if ($item->inventory_status === 'reserved' && self::unfulfilled($item) === 0) {
                $item->inventory_status = (int) $item->fulfilled_quantity > 0 ? 'committed' : 'released';
            }
            $item->save();
        }
        $returned = $quantity - $release;

        return $restock && $returned > 0 ? $this->restock($item, min($returned, max(0, $restockable)), $actorId, $note) : 0;
    }

    /** Lock product then variant (the order every settlement path uses); null when the catalog row is gone. */
    private function lockedStock(StoreOrderItem $item): Product|ProductVariant|null
    {
        if ($item->store_product_id === null) {
            return null;
        }
        $product = Product::query()->where('store_id', $item->store_id)->whereKey($item->store_product_id)->lockForUpdate()->first();

        return $item->variant_id === null ? $product : ProductVariant::query()->where('store_id', $item->store_id)
            ->where('store_product_id', $item->store_product_id)->whereKey($item->variant_id)->lockForUpdate()->first();
    }

    /**
     * Put refunded units of a shipped (committed) physical line back on the shelf. The caller holds
     * the order lock; this locks product then variant like settle(). Returns the units restocked:
     * 0 when the line was never deducted, is digital, or its catalog row/tracking no longer exists.
     */
    public function restock(StoreOrderItem $item, int $quantity, ?int $actorId = null, ?string $note = null): int
    {
        if ($quantity < 1 || $item->inventory_status !== 'committed' || $item->digital_delivery !== null || $item->store_product_id === null) {
            return 0;
        }
        $product = Product::query()->where('store_id', $item->store_id)->whereKey($item->store_product_id)->lockForUpdate()->first();
        $stock = $item->variant_id === null ? $product : ProductVariant::query()->where('store_id', $item->store_id)
            ->where('store_product_id', $item->store_product_id)->whereKey($item->variant_id)->lockForUpdate()->first();
        // Deleted or untracked catalog rows cannot manufacture stock on a replacement product.
        if ($stock === null || ! $stock->track_inventory) {
            return 0;
        }
        $stock->stock_quantity += $quantity;
        $stock->save();
        $this->record($stock, 'refund_restock', $quantity, 0, $item->id, $actorId, $note);
        $this->flushAfterCommit($item->store_id);

        return $quantity;
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
