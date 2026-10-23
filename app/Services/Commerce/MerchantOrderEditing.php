<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\ProductDigitalCode;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderEdit;
use App\Models\StoreOrderItem;
use App\Models\StorePaymentTransaction;
use App\Models\StoreShipment;
use App\Models\User;
use App\Services\PlatformWallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MerchantOrderEditing
{
    public function capabilities(Store $store, StoreOrder $order): array
    {
        $shipment = StoreShipment::where('store_id', $store->id)->where('store_order_id', $order->id)->exists();
        $contact = ! $order->trashed() && ! in_array($order->status, ['cancelled', 'delivered', ...StoreOrder::RETURN_STATUSES], true) && ! $shipment;
        $financial = $contact && in_array($order->status, ['pending', 'confirmed'], true) && $order->payment_status === 'pending'
            && in_array($order->payment_method, ['cod', 'bank_transfer'], true) && strtoupper($order->currency) === strtoupper($store->currency ?: 'USD')
            && ! StorePaymentTransaction::where('store_id', $store->id)->where('store_order_id', $order->id)
                ->where(fn ($query) => $query->whereNotIn('status', ['created', 'pending', 'failed'])->orWhereNotIn('gateway', ['cod', 'bank_transfer']))->exists()
            && ! $order->items()->whereIn('inventory_status', ['committed', 'released'])->exists();

        return ['contact' => $contact, 'financial' => $financial,
            'reason' => $shipment ? 'shipment_exists' : (! $contact ? 'order_closed' : (! $financial ? 'payment_or_inventory_locked' : null))];
    }

    public function snapshot(Store $store, StoreOrder $order): array
    {
        return ['owner_id' => $store->owner_user_id, 'store_updated_at' => $store->getRawOriginal('updated_at'),
            'pricing' => ['currency' => $store->currency, 'tax_enabled' => (bool) $store->tax_enabled, 'tax_rate' => (string) $store->tax_rate,
                'tax_prices_include' => (bool) $store->tax_prices_include, 'limits' => app(OrderLimits::class)->configured($store)], 'order' => $order->getAttributes(),
            'items' => $order->items()->orderBy('id')->get()->map(fn ($item) => $item->getAttributes())->all(),
            'payments' => StorePaymentTransaction::where('store_id', $store->id)->where('store_order_id', $order->id)->orderBy('id')->get()->map(fn ($row) => $row->getAttributes())->all(),
            'capabilities' => $this->capabilities($store, $order), 'revision' => (int) StoreOrderEdit::where('store_order_id', $order->id)->max('revision')];
    }

    public function token(Store $store, StoreOrder $order): string
    {
        return $this->hash($this->snapshot($store, $order));
    }

    public function apply(Store $store, User $actor, int $id, array $data): StoreOrder
    {
        return DB::transaction(function () use ($store, $actor, $id, $data) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($actor->id);
            abort_unless(app(ManualStoreOrders::class)->authorized($store, $actor), 403);
            // Payment confirmation locks transaction before order. Keep the same order.
            StorePaymentTransaction::where('store_id', $store->id)->where('store_order_id', $id)->orderBy('id')->lockForUpdate()->get();
            $order = StoreOrder::forStore($store)->whereKey($id)->lockForUpdate()->firstOrFail();
            app(PlatformWallet::class)->assertOrderVisible($order);
            $key = Str::lower($data['request_key']);
            unset($data['request_key']);
            $hash = $this->hash($data);
            $existing = StoreOrderEdit::where('store_id', $store->id)->where('actor_id', $actor->id)->where('request_key', $key)->first();
            if ($existing) {
                abort_unless((int) $existing->store_order_id === $id && (int) $existing->owner_id === (int) $store->owner_user_id && hash_equals($existing->request_hash, $hash), 409, 'This edit request changed or belongs to another order.');

                return $order;
            }
            $before = $this->snapshot($store, $order);
            abort_unless(hash_equals($this->hash($before), $data['expected_token']), 409, 'Order changed. Reload it before saving this edit.');
            $caps = $before['capabilities'];
            abort_unless($caps['contact'], 422, 'This order has closed or entered shipping.');
            $old = $order->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $retained = [];
            $shippingChanged = bccomp($data['shipping_total'], $order->shipping_total, 2) !== 0;
            $financial = count($data['items']) !== $old->count() || $shippingChanged || isset($data['grand_total']);
            foreach ($data['items'] as $line) {
                if (isset($line['item_id'])) {
                    $item = $old->get($line['item_id']);
                    abort_unless($item && ! isset($retained[$item->id]), 422, 'An order item is unavailable or duplicated.');
                    abort_unless((! isset($line['product_id']) || (int) $line['product_id'] === (int) $item->store_product_id)
                        && (! isset($line['variant_id']) || (int) $line['variant_id'] === (int) $item->variant_id)
                        && empty($line['personalization']), 422, 'Retained snapshots cannot change product identity or personalization. Remove the line and select its replacement.');
                    $retained[$item->id] = true;
                    $financial = $financial || $item->quantity !== (int) $line['quantity'] || bccomp($item->unit_price, $line['unit_price'], 2) !== 0;
                } else {
                    abort_unless(isset($line['product_id']), 422, 'Choose a product for every new line.');
                    $financial = true;
                }
            }
            abort_unless(! $financial || $caps['financial'], 422, 'Captured payments, online invoices and settled inventory require their own adjustment workflow.');
            $phone = app(OrderLimits::class)->normalizePhone($data['customer_phone'], app(OrderLimits::class)->configured($store)['phone_country']);
            abort_unless($phone, 422, 'Enter a valid local or international phone number.');
            if ($phone !== $order->customer_phone_normalized) {
                app(PhoneBlocking::class)->assertAllowed($store, $phone);
            }
            $address = $order->shipping_address ?? [];
            foreach (['country', 'region', 'city', 'line1', 'line2', 'phone_alt'] as $field) {
                $address[$field] = $data['shipping_address'][$field] ?? null;
            }
            if ($shippingChanged) {
                $address['shipping_details'] = ['mode' => 'manual', 'base_rate' => $data['shipping_total']];
            }
            $order->fill(['customer_name' => $data['customer_name'], 'customer_phone' => $data['customer_phone'], 'customer_phone_normalized' => $phone,
                'customer_email' => $data['customer_email'] ?? null, 'shipping_address' => $address]);
            if ($financial) {
                $this->editItems($store, $order, $old, $data['items'], (int) $actor->id);
                $subtotal = '0.00';
                foreach ($order->items()->get() as $item) {
                    $subtotal = bcadd($subtotal, $item->line_total, 2);
                }
                $physical = $order->physicalItems()->exists();
                abort_unless($physical || $order->payment_method !== 'cod', 422, 'An entirely digital order cannot use cash on delivery.');
                abort_unless($physical || bccomp($data['shipping_total'], '0', 2) === 0, 422, 'An entirely digital order has no shipping fee.');
                $discount = bccomp($order->discount_total, $subtotal, 2) > 0 ? $subtotal : $order->discount_total;
                app(OrderLimits::class)->assertMinimum($store, $subtotal, $discount);
                $totals = app(PricingCalculator::class)->forStore($store, $subtotal, $discount, [], false);
                $totals['grand_total'] = bcadd($totals['grand_total'], $data['shipping_total'], 2);
                $totals = app(PaymentPriceAdjustment::class)->apply($totals, $order->currency, $order->payment_method, $order->payment_adjustment['rule'] ?? null);
                $normal = $totals['grand_total'];
                $total = $data['grand_total'] ?? bcadd($normal, $order->manual_adjustment_total ?? '0.00', 2);
                abort_unless(bccomp($total, '0', 2) >= 0 && bccomp($total, ManualStoreOrders::MAX_MONEY, 2) <= 0 && bccomp($normal, ManualStoreOrders::MAX_MONEY, 2) <= 0, 422, 'The edited total is outside the supported amount.');
                $order->fill(['subtotal' => $subtotal, 'discount_total' => $discount, 'shipping_total' => $data['shipping_total'], 'tax_total' => $totals['tax_total'],
                    'grand_total' => $total, 'payment_adjustment' => $totals['payment_adjustment'], 'manual_adjustment_total' => bcsub($total, $normal, 2), 'manual_tax_included' => (bool) $store->tax_prices_include]);
                StorePaymentTransaction::where('store_id', $store->id)->where('store_order_id', $order->id)->whereIn('status', ['created', 'pending'])->update(['amount' => $total, 'updated_at' => now()]);
            }
            if ($order->physicalItems()->exists()) {
                abort_unless(filled($address['line1'] ?? null), 422, 'Enter the full shipping address.');
            }
            if ($order->items()->whereNotNull('digital_delivery')->exists()) {
                abort_unless(filter_var($order->customer_email, FILTER_VALIDATE_EMAIL), 422, 'Enter an email for digital delivery.');
            }
            $order->save();
            $order->refresh();
            $edit = StoreOrderEdit::create(['store_id' => $store->id, 'store_order_id' => $order->id, 'actor_id' => $actor->id, 'owner_id' => $store->owner_user_id,
                'request_key' => $key, 'request_hash' => $hash, 'revision' => $before['revision'] + 1, 'before_snapshot' => $before,
                'after_snapshot' => array_replace($this->snapshot($store, $order), ['revision' => $before['revision'] + 1])]);
            $order->statusChanges()->create(['store_id' => $store->id, 'from_status' => $order->status, 'to_status' => $order->status, 'actor_id' => $actor->id, 'source' => 'merchant_edit', 'notes' => $data['note'] ?? null]);
            app(StoreOrderWebhooks::class)->resend($order, 'edit:'.$edit->id);
            DB::afterCommit(fn () => StoreAnalyticsService::forget($store->id));

            return $order;
        });
    }

    /** @param Collection<int, StoreOrderItem> $old */
    private function editItems(Store $store, StoreOrder $order, Collection $old, array $lines, int $actorId): void
    {
        $ids = $old->pluck('store_product_id')->filter()->merge(array_column($lines, 'product_id'))->unique()->sort()->values();
        $products = Product::withoutGlobalScopes()->where('store_id', $store->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $variants = ProductVariant::withoutGlobalScopes()->where('store_id', $store->id)->whereIn('store_product_id', $ids)->orderBy('store_product_id')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $retained = array_map('intval', array_filter(array_column($lines, 'item_id')));
        foreach ($old as $item) {
            $line = collect($lines)->firstWhere('item_id', $item->id);
            $quantity = $line ? (int) $line['quantity'] : 0;
            if ($item->digital_delivery !== null && $quantity !== $item->quantity) {
                ProductDigitalCode::where('store_id', $store->id)->where('store_order_item_id', $item->id)->update(['store_order_item_id' => null]);
            }
            $stock = $item->variant_id ? $variants->get($item->variant_id) : $products->get($item->store_product_id);
            abort_unless(! $item->variant_id || ! $stock || ($stock instanceof ProductVariant && (int) $stock->store_product_id === (int) $item->store_product_id), 422, 'The retained inventory identity needs reconciliation.');
            app(StoreInventory::class)->editQuantity($item, $stock, $quantity, $actorId);
            if (! in_array($item->id, $retained, true)) {
                $item->superseded_at = now();
                $item->save();
            }
        }
        $counts = [];
        foreach ($lines as $line) {
            if (isset($line['item_id'])) {
                $item = $old->get($line['item_id']);
                $product = $products->get($item->store_product_id);
                if ($item->quantity !== (int) $line['quantity']) {
                    if ((int) $line['quantity'] > $item->quantity) {
                        $variant = $item->variant_id ? $variants->get($item->variant_id) : null;
                        abort_unless($product && $product->is_active && (! $item->variant_id || $variant?->is_active), 422, 'This product cannot increase quantity because it is unavailable.');
                    }
                    if ($item->digital_delivery !== null) {
                        abort_unless($product, 422, 'This digital product is unavailable for code reallocation.');
                        $item->quantity = (int) $line['quantity'];
                        if ($item->digital_delivery['type'] === 'codes') {
                            app(DigitalProducts::class)->reserve($product, $item);
                        }
                    }
                }
                $item->fill(['quantity' => (int) $line['quantity'], 'unit_price' => $line['unit_price'], 'line_total' => bcmul($line['unit_price'], (string) $line['quantity'], 2)])->save();
            } else {
                $selection = app(PurchasableSelection::class)->resolve($store, (int) $line['product_id'], $line['variant_id'] ?? null, true);
                abort_unless(! isset($line['expected_price']) || ($line['price_overridden'] ?? false) || bccomp($line['expected_price'], $selection['price'], 2) === 0, 422, 'The selected product price changed. Select it again or explicitly review its override.');
                $product = $selection['product'];
                $variant = $selection['variant'];
                $custom = app(ProductPersonalization::class)->resolve($product, $line['personalization'] ?? [], true);
                $item = $order->items()->create(['store_id' => $store->id, 'store_product_id' => $product->id, 'variant_id' => $variant?->id, 'variant_name' => $variant?->name, 'variant_options' => $variant?->options,
                    'name' => $selection['name'], 'sku' => $variant?->sku ?? $product->sku, 'unit_cost' => $variant?->cost ?? $product->cost, 'personalization' => $custom['snapshot'] ?: null,
                    'unit_price' => $line['unit_price'], 'quantity' => (int) $line['quantity'], 'line_total' => bcmul($line['unit_price'], (string) $line['quantity'], 2)]);
                app(StoreInventory::class)->reserve($variant ?? $product, $item);
                app(DigitalProducts::class)->reserve($product, $item);
            }
            if ($item->store_product_id) {
                $counts[$item->store_product_id] = ($counts[$item->store_product_id] ?? 0) + $item->quantity;
            }
        }
        foreach ($counts as $quantity) {
            app(OrderLimits::class)->assertQuantity($store, $quantity);
        }
        $order->unsetRelation('items');
    }

    private function hash(array $data): string
    {
        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
