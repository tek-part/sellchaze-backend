<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreTrackingDelivery;
use App\Models\StoreTrackingEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreTrackingEvents
{
    public function __construct(private readonly StoreTrackingSettings $settings) {}

    public function allowed(Request $request): bool
    {
        return $request->boolean('advertising_allowed') && $request->header('DNT') !== '1' && $request->header('Sec-GPC') !== '1';
    }

    public function key(Store $store, string $key): string
    {
        return hash_hmac('sha256', 'tracking:'.$store->id.':'.$key, (string) config('app.key'));
    }

    /** Catalog values come from the current store; clients supply selections, never prices or contacts. */
    public function capture(Store $store, Request $request, array $data): ?array
    {
        if (! $this->allowed($request) || ! $this->settings->enabled($store)) {
            return null;
        }
        $key = $this->key($store, strtolower($data['event_id']));
        $existing = StoreTrackingEvent::where('store_id', $store->id)->where('event_key', $key)->first();
        if ($existing) {
            return $this->browser($store, $existing);
        }
        $items = [];
        $value = '0.00';
        $groupView = false;
        foreach ($data['items'] ?? [] as $line) {
            if ($data['event'] === 'view_item' && ! isset($line['variant_id'])) {
                $product = Product::withoutGlobalScopes()->where('store_id', $store->id)->where('is_active', true)->findOrFail($line['product_id']);
                $price = (string) $product->price;
                $groupView = $product->variants()->where('store_id', $store->id)->where('is_active', true)->exists();
            } else {
                $selection = app(PurchasableSelection::class)->resolve($store, (int) $line['product_id'], $line['variant_id'] ?? null, false,
                    $line['quantity_offer_id'] ?? null, (int) $line['quantity'], $line['quantity_offer_version'] ?? null);
                $product = $selection['product'];
                $price = $selection['price'];
            }
            $variantId = isset($selection) ? $selection['variant']?->id : null;
            if ($data['event'] === 'add_to_cart' || ($data['event'] === 'begin_checkout' && ($data['surface'] ?? null) === 'product' && ProductPreferences::onePage($product))) {
                $price = $product->product_preferences['pixel_price'] ?? $price;
            }
            $items[] = ['item_id' => CatalogItemId::selection($product->id, $variantId), 'item_name' => $product->name, 'price' => $price, 'quantity' => (int) $line['quantity']];
            unset($selection);
            $value = bcadd($value, bcmul($price, (string) $line['quantity'], 2), 2);
        }
        $payload = ['event' => $data['event'], 'event_id' => $key, 'currency' => $store->currency ?: 'USD', 'value' => $value, 'items' => $items,
            'page_location' => $request->getSchemeAndHttpHost().($data['page_path'] ?? '/'), 'user_data' => $this->userData($request)];
        if ($groupView) {
            $payload['content_type'] = 'product_group';
        }
        $pixels = app(FunnelPixelSettings::class);
        $productIds = array_column($data['items'] ?? [], 'product_id');
        $funnel = $pixels->forPath($store, $data['page_path'] ?? null, $productIds);
        $payload += $pixels->snapshot($funnel);
        $payload += app(ProductPixelSettings::class)->snapshot($store, $productIds ?: ($funnel ? [$funnel->product_id] : []), $data['page_path'] ?? null);
        if (! isset($payload['funnel_pixels']) && ! isset($payload['product_pixels']) && ! $this->settings->storeEnabled($store)) {
            return null;
        }

        return $this->browser($store, $this->persist($store, $payload));
    }

    /** Optional advertising cannot roll back a customer's accepted business order. No provider I/O here. */
    public function purchase(Store $store, StoreOrder $order, Request $request): void
    {
        if (! $this->allowed($request) || ! $this->settings->enabled($store)) {
            return;
        }
        try {
            $lines = $order->items()->get();
            $products = $request->input('checkout_surface') === 'product' ? Product::withoutGlobalScopes()->where('store_id', $store->id)->whereIn('id', $lines->pluck('store_product_id'))->get()->keyBy('id') : collect();
            $customValue = '0.00';
            $custom = false;
            $items = [];
            foreach ($lines as $line) {
                $product = $products->get($line->store_product_id);
                $override = $product && ProductPreferences::onePage($product) ? $product->product_preferences['pixel_price'] ?? null : null;
                $custom = $custom || $override !== null;
                $price = $override ?? $line->unit_price;
                $customValue = bcadd($customValue, bcmul((string) $price, (string) $line->quantity, 2), 2);
                $items[] = ['item_id' => CatalogItemId::selection((int) $line->store_product_id, $line->variant_id), 'item_name' => $line->name, 'price' => $price, 'quantity' => (int) $line->quantity];
            }
            $advertised = $custom ? max('0.00', bcsub($customValue, (string) $order->discount_total, 2)) : bcsub((string) $order->subtotal, (string) $order->discount_total, 2);
            // Inclusive tax is already inside unit prices. Use the accepted order's added charges.
            $addedTax = bcsub(bcsub((string) $order->grand_total, bcsub((string) $order->subtotal, (string) $order->discount_total, 2), 2), (string) $order->shipping_total, 2);
            $user = $this->userData($request);
            if ($order->customer_email) {
                $user['em'] = hash('sha256', strtolower(trim($order->customer_email)));
            }
            if ($order->customer_phone_normalized) {
                $user['ph'] = hash('sha256', preg_replace('/[^0-9]/', '', $order->customer_phone_normalized));
            }
            $payload = ['event' => 'purchase', 'event_id' => $this->key($store, 'order:'.$order->id), 'currency' => $order->currency,
                // GA4 merchandise value excludes shipping and tax; other providers receive the accepted gross total.
                'value' => $advertised, 'gross_value' => $custom ? bcadd(bcadd($advertised, (string) $order->shipping_total, 2), $addedTax, 2) : (string) $order->grand_total,
                'shipping' => (string) $order->shipping_total, 'tax' => (string) $order->tax_total, 'transaction_id' => $order->order_number,
                'items' => $items, 'page_location' => $request->getSchemeAndHttpHost().'/checkout', 'user_data' => $user];
            if ($request->input('checkout_surface') === 'funnel' && $request->input('cart_mode') === 'direct') {
                $pixels = app(FunnelPixelSettings::class);
                $funnel = $pixels->forPath($store, $request->input('funnel_path'), $lines->pluck('store_product_id')->all());
                $payload += $pixels->snapshot($funnel);
            }
            $payload += app(ProductPixelSettings::class)->snapshot($store, $lines->pluck('store_product_id')->all());
            if (! isset($payload['funnel_pixels']) && ! isset($payload['product_pixels']) && ! $this->settings->storeEnabled($store)) {
                return;
            }
            $this->persist($store, $payload, $order->id);
        } catch (\Throwable) { /* No raw token/contact/provider logging; checkout succeeds independently. */
        }
    }

    public function purchaseForBrowser(StoreOrder $order, Request $request): ?array
    {
        if (! $this->allowed($request) || $order->status === 'cancelled') {
            return null;
        }
        $store = Store::find($order->store_id);
        if (! $store || ! $this->settings->enabled($store)) {
            return null;
        }
        $event = StoreTrackingEvent::where('store_id', $store->id)->where('store_order_id', $order->id)->where('event_name', 'purchase')
            ->where('occurred_at', '>=', now()->subDays(2))->first();

        return $event ? $this->browser($store, $event) : null;
    }

    private function userData(Request $request): array
    {
        $data = ['client_user_agent' => substr((string) $request->userAgent(), 0, 512)];
        if (filter_var($request->ip(), FILTER_VALIDATE_IP)) {
            $data['client_ip_address'] = $request->ip();
        }

        return $data;
    }

    private function persist(Store $store, array $payload, ?int $orderId = null): StoreTrackingEvent
    {
        return DB::transaction(function () use ($store, $payload, $orderId) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $existing = StoreTrackingEvent::where('store_id', $store->id)->where('event_key', $payload['event_id'])->first();
            if ($existing) {
                return $existing;
            }
            $rows = array_filter($this->settings->configuration($store)['conversions'], fn ($row) => $row['enabled']);
            // Matching data is needed only by server pixels and never leaves in browser payloads.
            if (! $rows) {
                $payload['user_data'] = [];
            }
            $event = StoreTrackingEvent::create(['store_id' => $store->id, 'event_key' => $payload['event_id'], 'event_name' => $payload['event'],
                'store_order_id' => $orderId, 'payload' => $payload, 'occurred_at' => now()]);
            foreach ($rows as $row) {
                StoreTrackingDelivery::create(['store_id' => $store->id, 'store_tracking_event_id' => $event->id,
                    'destination_id' => $row['id'], 'generation' => $row['generation'], 'provider' => $row['provider'], 'pixel_id' => $row['pixel_id'], 'available_at' => now()]);
            }

            return $event;
        });
    }

    private function browser(Store $store, StoreTrackingEvent $event): ?array
    {
        $store = Store::findOrFail($store->id);
        if (! $this->settings->enabled($store)) {
            return null;
        }
        $payload = $event->payload;
        $configuration = app(FunnelPixelSettings::class)->forEvent($store, $payload);
        $configuration = app(ProductPixelSettings::class)->forEvent($store, $payload, $configuration);
        unset($payload['user_data'], $payload['funnel_id'], $payload['funnel_pixels'], $payload['product_pixels']);

        return ['configuration' => $configuration, 'event' => $payload];
    }
}
