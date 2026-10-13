<?php

namespace App\Services\Commerce;

use App\Models\Order;
use App\Models\OrderSuppliers;
use App\Models\Product;
use App\Models\Scopes\ProductScope;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\User;
use App\Notifications\OrderAssignedToSupplierNotification;
use App\Services\Orders\SupplierRoutingService;
use App\Services\Outbox\OutboxRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors a hosted-storefront StoreOrder into the B2B `orders` pipeline so the
 * store owner's supplier network can fulfil it through the existing orders
 * in/out, quotation and delivery tooling.
 *
 * Idempotent on orders.store_order_id (row lock + unique index). Routing:
 *  - owner is a Supplier  -> self-routed pivot {customer: owner, supplier: owner}
 *    (an unrouted order would be invisible to a supplier: orders-in is empty for them);
 *  - owner is a Merchant  -> fan out to accepted partners (SupplierRoutingService),
 *    else stays unrouted and shows in the merchant's orders-in for manual assignment.
 *
 * Status mirroring is one-way and minimal: a cancelled StoreOrder cancels the B2B row.
 * The reverse direction (B2B status -> storefront status) is intentionally out of scope.
 */
class StorefrontOrderBridge
{
    public function __construct(
        private readonly SupplierRoutingService $routing,
        private readonly OutboxRecorder $outbox,
    ) {}

    /**
     * Create (or return the existing) B2B order for a storefront order.
     * Returns null when nothing can be bridged (no owner / no product-backed line).
     */
    public function bridge(StoreOrder $storeOrder, Store $store): ?Order
    {
        if ((int) $storeOrder->store_id !== (int) $store->id) {
            throw ValidationException::withMessages(['store' => 'The order belongs to another store.']);
        }
        // Digital delivery is handled by the store's private receipt/mail flow.
        // Only immutable physical lines enter supplier procurement and shipping.
        $physicalItems = $storeOrder->physicalItems()->get();
        if ($physicalItems->isEmpty()) {
            return null;
        }

        $owner = $store->owner ?: User::query()->find($store->owner_user_id);
        if ($owner === null) {
            Log::warning('StorefrontOrderBridge: store has no owner; skipping', ['store_id' => $store->id, 'store_order_id' => $storeOrder->id]);

            return null;
        }

        $productIds = $physicalItems->pluck('store_product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($productIds->isEmpty()) {
            Log::warning('StorefrontOrderBridge: no product-backed lines; skipping', ['store_order_id' => $storeOrder->id]);

            return null;
        }

        $products = Product::query()->withoutGlobalScope(ProductScope::class)
            ->where('store_id', $store->id)
            ->whereIn('id', $productIds->all())
            ->get()
            ->keyBy('id');
        $firstProductId = $productIds->first(fn (int $id) => $products->has($id));
        if ($firstProductId === null) {
            Log::warning('StorefrontOrderBridge: products no longer exist; skipping', ['store_order_id' => $storeOrder->id]);

            return null;
        }

        $supplierIds = $this->resolveSupplierIds($owner);
        $notify = [];

        $order = DB::transaction(function () use ($storeOrder, $store, $owner, $products, $firstProductId, $supplierIds, $physicalItems, &$notify) {
            // Match cancellation's storefront-before-B2B lock order and use the
            // live payment/status if the bridge job runs after confirmation.
            $storeOrder = StoreOrder::forStore($store->id)->whereKey($storeOrder->id)->lockForUpdate()->firstOrFail();
            $existing = Order::query()->where('store_order_id', $storeOrder->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            $items = $physicalItems->map(function ($item) use ($products) {
                $product = $item->store_product_id ? $products->get((int) $item->store_product_id) : null;

                return [
                    'store_order_item_id' => $item->id,
                    'product_id' => $item->store_product_id ? (int) $item->store_product_id : null,
                    'variant_id' => $item->variant_id,
                    'variant_name' => $item->variant_name,
                    'variant_options' => $item->variant_options,
                    'personalization' => $item->personalization ?? [],
                    'fulfillment_type' => 'physical',
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'slug' => $product?->slug,
                    'image' => $product?->image,
                    'unit_price' => (string) $item->unit_price,
                    'quantity' => (int) $item->quantity,
                    'line_total' => (string) $item->line_total,
                ];
            })->values()->all();

            $firstProduct = $products->get($firstProductId);
            $shipping = is_array($storeOrder->shipping_address) ? $storeOrder->shipping_address : null;

            $order = Order::create([
                'code' => $this->uniqueCode($storeOrder, $store),
                'source' => Order::SOURCE_STOREFRONT,
                'store_id' => $store->id,
                'store_order_id' => $storeOrder->id,
                'storefront_items' => $items,
                'quantity' => (int) $physicalItems->sum('quantity'),
                'image' => $firstProduct?->image,
                'product_id' => $firstProductId,
                'user_id' => $owner->getKey(),
                'attributes' => 'a:0:{}',
                'status' => $storeOrder->status === 'cancelled' ? 'cancelled' : 'pending',
                'ref_number' => $storeOrder->order_number,
                'notes' => '',
                'customer_name' => $storeOrder->customer_name,
                'customer_email' => $storeOrder->customer_email,
                'customer_phone' => $storeOrder->customer_phone,
                'shipping_address' => $shipping ? $this->formatShippingAddress($shipping) : null,
                'shipping_address_json' => $shipping ? json_encode($shipping, JSON_UNESCAPED_UNICODE) : null,
                'shipping_type' => 'customer_direct',
                'delivery_path' => 'customer_direct',
                'currency' => $storeOrder->currency ?: 'AED',
                'payment_method' => $storeOrder->payment_method,
                'payment_type' => 'full',
                'paid_amount' => $storeOrder->payment_status === 'paid' ? $storeOrder->grand_total : null,
                'payment_transaction_id' => $storeOrder->payment_reference,
            ]);

            foreach ($supplierIds as $sid) {
                OrderSuppliers::create([
                    'order_id' => $order->id,
                    'customer' => $owner->getKey(),
                    'supplier' => $sid,
                ]);
                if ($sid !== (int) $owner->getKey()) {
                    $notify[] = $sid;
                }
            }

            $this->outbox->record('StorefrontOrderBridged', 'order', $order->id, [
                'order_id' => $order->id,
                'order_code' => $order->code,
                'store_id' => $store->id,
                'store_order_id' => $storeOrder->id,
                'store_order_number' => $storeOrder->order_number,
                'owner_user_id' => $owner->getKey(),
                'supplier_ids' => array_values($supplierIds),
                'routed' => $supplierIds !== [],
            ]);

            return $order;
        });

        // Third-party suppliers only (self-routed owners already know about their own order).
        foreach (array_unique($notify) as $sid) {
            try {
                $supplier = User::query()->find($sid);
                $supplier?->notify(new OrderAssignedToSupplierNotification($order));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $order;
    }

    /**
     * Mirror a storefront status onto the bridged B2B row. Only `cancelled` is mirrored:
     * fulfilment (confirmed/processing/shipped/delivered) is driven on the B2B side by the
     * supplier and must not be overwritten from the storefront.
     */
    public function syncStatus(StoreOrder $storeOrder): ?Order
    {
        if ($storeOrder->status !== 'cancelled') {
            return null;
        }

        $order = Order::query()->where('store_order_id', $storeOrder->id)->first();
        if ($order === null || $order->status === 'cancelled') {
            return $order;
        }

        $order->status = 'cancelled';
        $order->save();

        return $order;
    }

    /** Payment metadata belongs to the full customer order, not a supplier quote.
     * Called inside the payment transaction after the storefront row is locked.
     */
    public function syncPayment(StoreOrder $storeOrder): void
    {
        if ($storeOrder->payment_status === 'paid') {
            Order::query()->where('store_id', $storeOrder->store_id)->where('store_order_id', $storeOrder->id)
                ->update(['paid_amount' => $storeOrder->grand_total, 'payment_transaction_id' => $storeOrder->payment_reference]);
        }
    }

    /** Editor holds both parent rows and rejects edits once supplier quotations exist. */
    public function syncEdit(StoreOrder $storeOrder, Order $order): void
    {
        abort_unless((int) $order->store_id === (int) $storeOrder->store_id && (int) $order->store_order_id === (int) $storeOrder->id, 422);
        $physical = $storeOrder->physicalItems()->get();
        $address = $storeOrder->shipping_address ?? [];
        $previous = collect($order->storefront_items ?? [])->keyBy('store_order_item_id');
        $wasEmptyBridge = $order->status === 'cancelled' && (int) $order->quantity === 0 && $previous->isEmpty();
        $products = Product::withoutGlobalScope(ProductScope::class)->where('store_id', $storeOrder->store_id)
            ->whereIn('id', $physical->pluck('store_product_id')->filter())->get()->keyBy('id');
        $items = $physical->map(fn ($item) => ['store_order_item_id' => $item->id, 'product_id' => $item->store_product_id,
            'variant_id' => $item->variant_id, 'variant_name' => $item->variant_name, 'variant_options' => $item->variant_options,
            'personalization' => $item->personalization ?? [], 'fulfillment_type' => 'physical', 'sku' => $item->sku,
            'name' => $item->name, 'slug' => $previous->get($item->id)['slug'] ?? $products->get($item->store_product_id)?->slug,
            'image' => $previous->get($item->id)['image'] ?? $products->get($item->store_product_id)?->image,
            'unit_price' => $item->unit_price, 'quantity' => $item->quantity, 'line_total' => $item->line_total])->all();
        $order->fill(['storefront_items' => $items, 'quantity' => (int) $physical->sum('quantity'),
            'customer_name' => $storeOrder->customer_name, 'customer_phone' => $storeOrder->customer_phone, 'customer_email' => $storeOrder->customer_email,
            'shipping_address' => $address ? $this->formatShippingAddress($address) : null,
            'shipping_address_json' => $address ? json_encode($address, JSON_UNESCAPED_UNICODE) : null]);
        if ($physical->isEmpty()) {
            $order->status = 'cancelled';
        } elseif ($physical->first()->store_product_id) {
            if ($wasEmptyBridge) {
                $order->status = 'pending';
            }
            $order->product_id = $physical->first()->store_product_id;
            $order->image = $items[0]['image'];
        }
        $order->save();
    }

    /** @return int[] */
    private function resolveSupplierIds(User $owner): array
    {
        if ($owner->hasRole('Supplier')) {
            return [(int) $owner->getKey()];
        }

        if ($owner->hasRole('Merchant')) {
            return array_map('intval', $this->routing->resolveForMerchant($owner));
        }

        return [];
    }

    private function uniqueCode(StoreOrder $storeOrder, Store $store): string
    {
        $code = 'SF-'.$storeOrder->order_number;
        if (! Order::query()->where('code', $code)->exists()) {
            return $code;
        }

        // order_number is unique per store only; disambiguate cross-store collisions.
        return 'SF-'.$store->id.'-'.$storeOrder->order_number;
    }

    /** @param array<string,mixed> $address */
    private function formatShippingAddress(array $address): string
    {
        $parts = [];
        foreach (['name', 'line1', 'line2', 'city', 'state', 'postal_code', 'country', 'national_address', 'phone_alt', 'delivery_option'] as $key) {
            $v = trim((string) ($address[$key] ?? ''));
            if ($v !== '') {
                $parts[] = $v;
            }
        }

        return implode(', ', $parts);
    }
}
