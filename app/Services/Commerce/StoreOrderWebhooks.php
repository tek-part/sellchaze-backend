<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderWebhook;
use App\Models\StoreOrderWebhookDelivery;
use App\Services\PlatformWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class StoreOrderWebhooks
{
    public function __construct(private readonly StoreWebhookTransport $transport) {}

    /** Database-only: called inside the checkout transaction, after immutable items exist. */
    public function created(StoreOrder $order): void
    {
        $this->record($order, 'orders', 'order:'.$order->id, $this->orderPayload($order), true);
    }

    public function resend(StoreOrder $order, string $eventKey): int
    {
        $this->record($order, 'orders', $eventKey, $this->orderPayload($order));

        return StoreOrderWebhookDelivery::where('store_id', $order->store_id)->where('store_order_id', $order->id)->where('event_key', $eventKey)->count();
    }

    private function orderPayload(StoreOrder $order): array
    {
        $order->loadMissing(['items' => fn ($query) => $query->withoutGlobalScopes()->where('store_id', $order->store_id)]);
        $address = $order->shipping_address ?? [];
        $payload = ['id' => $order->id, 'store_id' => $order->store_id, 'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(), 'cost' => (float) $order->subtotal, 'shipping_cost' => (float) $order->shipping_total,
            'total_cost' => (float) $order->grand_total, 'payment_adjustment_total' => $order->payment_adjustment['amount'] ?? '0.00',
            'currency' => $order->currency, 'status' => $order->status, 'full_name' => $order->customer_name,
            'phone' => $order->customer_phone, 'email' => $order->customer_email, 'government' => $address['region'] ?? $address['governorate'] ?? '',
            'address' => implode(', ', array_filter([$address['line1'] ?? '', $address['line2'] ?? '', $address['city'] ?? '', $address['country'] ?? ''], fn ($part) => is_string($part) && $part !== '')),
            'shipping_address' => $address, 'payment_method' => $order->payment_method,
            'cart_items' => $order->items->map(fn ($item) => ['id' => $item->id, 'product_id' => $item->store_product_id,
                'variant_id' => $item->variant_id, 'store_id' => $order->store_id, 'price' => (float) $item->unit_price,
                'quantity' => $item->quantity, 'product' => ['id' => $item->store_product_id, 'name' => $item->name, 'sku' => $item->sku],
                'variant' => $item->variant_id ? ['id' => $item->variant_id, 'name' => $item->variant_name, 'variation_props' => $item->variant_options] : null])->all()];

        return $payload;
    }

    public function statusChanged(StoreOrder $order, string $oldStatus, int $changeId): void
    {
        $this->record($order, 'order-status-update', 'status:'.$changeId, ['event_type' => 'order-status-update',
            'order_id' => $order->id, 'old_status' => $oldStatus, 'new_status' => $order->status, 'payment_ref_id' => $order->payment_reference]);
    }

    private function record(StoreOrder $order, string $type, string $eventKey, array $payload, bool $created = false): void
    {
        if ($order->trashed()) {
            return;
        }
        $store = Store::find($order->store_id);
        if (! $store) {
            return;
        }
        $hooks = StoreOrderWebhook::where('store_id', $store->id)->where('user_id', $store->owner_user_id)
            ->where('event_type', $type)->where('enabled', true)->orderBy('id')->lockForUpdate()->get();
        foreach ($hooks as $hook) {
            if (($created && $order->created_at && $order->created_at->lessThan($hook->created_at))
                || StoreOrderWebhookDelivery::where('webhook_id', $hook->id)->where('event_key', $eventKey)->exists()) {
                continue;
            }
            StoreOrderWebhookDelivery::create(['store_id' => $store->id, 'webhook_id' => $hook->id, 'store_order_id' => $order->id,
                'webhook_version' => $hook->version, 'event_key' => $eventKey, 'payload' => $payload,
                'next_attempt_at' => now(), 'expires_at' => now()->addDay()]);
        }
    }

    /** @return array<string,mixed> */
    public function present(StoreOrderWebhook $hook): array
    {
        $latest = StoreOrderWebhookDelivery::where('store_id', $hook->store_id)->where('webhook_id', $hook->id)->latest('created_at')->first();

        return ['id' => $hook->id, 'url' => $hook->url, 'description' => $hook->description, 'type' => $hook->event_type,
            'enabled' => $hook->enabled, 'version' => $hook->version, 'created_at' => $hook->created_at,
            'last_delivery' => $latest ? $this->deliveryMetadata($latest) : null];
    }

    public function deliveryMetadata(StoreOrderWebhookDelivery $row): array
    {
        $eventKey = $row->event_key;
        $order = StoreOrder::withoutGlobalScopes()->where('store_id', $row->store_id)->find($row->store_order_id);
        if ($order) {
            try {
                app(PlatformWallet::class)->assertOrderVisible($order);
            } catch (HttpException $error) {
                if ($error->getStatusCode() !== 402) {
                    throw $error;
                }
                $eventKey = null;
            }
        }

        return ['id' => $row->id, 'event_key' => $eventKey, 'status' => $row->status, 'attempts' => $row->attempts,
            'http_status' => $row->http_status, 'error_code' => $row->error_code, 'created_at' => $row->created_at, 'delivered_at' => $row->delivered_at];
    }

    public function deliver(int $limit = 50): int
    {
        $ids = StoreOrderWebhookDelivery::where(fn ($query) => $query->whereIn('status', ['queued', 'retry', 'blocked'])
            ->where('next_attempt_at', '<=', now())->orWhere(fn ($query) => $query->where('status', 'processing')->where('lease_until', '<=', now())))
            ->orderBy('created_at')->limit(max(1, min(100, $limit)))->pluck('id');
        $processed = 0;
        foreach ($ids as $id) {
            if ($this->send((string) $id)) {
                $processed++;
            }
        }

        return $processed;
    }

    private function send(string $id): bool
    {
        $claim = DB::transaction(function () use ($id) {
            $row = StoreOrderWebhookDelivery::whereKey($id)->lockForUpdate()->first();
            if (! $row || ! in_array($row->status, ['queued', 'retry', 'blocked', 'processing'], true)
                || ($row->status === 'processing' && $row->lease_until && $row->lease_until->isFuture())
                || ($row->next_attempt_at && $row->next_attempt_at->isFuture())) {
                return null;
            }
            if ($row->expires_at->isPast() || $row->attempts >= 8) {
                $row->update(['status' => $row->expires_at->isPast() ? 'expired' : 'failed', 'payload' => null, 'lease_token' => null, 'lease_until' => null]);

                return null;
            }
            $hook = StoreOrderWebhook::whereKey($row->webhook_id)->lockForUpdate()->first();
            $order = StoreOrder::withoutGlobalScopes()->where('store_id', $row->store_id)->find($row->store_order_id);
            $store = $order?->store;
            $valid = $hook && $hook->enabled && $hook->version === $row->webhook_version && $order && ! $order->trashed() && $store && $store->status === 'active'
                && (int) $hook->store_id === (int) $row->store_id && (int) $hook->user_id === (int) $store->owner_user_id
                && $store->owner && $store->owner->is_active && ! $store->owner->pending_approval;
            if (! $valid) {
                $row->update(['status' => 'skipped', 'payload' => null, 'lease_token' => null, 'lease_until' => null]);

                return null;
            }
            try {
                app(PlatformWallet::class)->assertOrderVisible($order);
            } catch (HttpException $error) {
                if ($error->getStatusCode() !== 402) {
                    throw $error;
                }
                $row->update(['status' => 'blocked', 'error_code' => 'wallet_funding_required', 'next_attempt_at' => now()->addMinutes(5)]);

                return null;
            }
            try {
                $secret = $hook->secret;
                $payload = $row->payload;
                if (! is_string($secret) || $secret === '' || ! is_array($payload)) {
                    throw new \RuntimeException('Invalid credentials');
                }
            } catch (Throwable) {
                $row->update(['status' => 'failed', 'payload' => null, 'error_code' => 'invalid_credentials']);

                return null;
            }
            $token = (string) Str::uuid();
            $row->update(['status' => 'processing', 'attempts' => $row->attempts + 1, 'lease_token' => $token,
                'lease_until' => now()->addMinute(), 'next_attempt_at' => null, 'error_code' => null]);

            return ['row' => $row, 'url' => $hook->url, 'secret' => $secret, 'payload' => $payload, 'token' => $token];
        });
        if (! $claim) {
            return false;
        }
        $row = $claim['row'];
        $http = null;
        $error = null;
        try {
            $http = $this->transport->send($claim['url'], $claim['secret'], $id, $claim['payload']);
        } catch (Throwable $failure) {
            $error = in_array($failure->getMessage(), ['unsafe_destination', 'dns_unavailable'], true) ? $failure->getMessage() : 'delivery_unavailable';
        }
        $success = $http !== null && $http >= 200 && $http < 300;
        $terminal = $success || $row->attempts >= 8 || $http === 410 || $error === 'unsafe_destination';
        StoreOrderWebhookDelivery::whereKey($id)->where('lease_token', $claim['token'])->update([
            'status' => $success ? 'sent' : ($terminal ? 'failed' : 'retry'), 'http_status' => $http,
            'error_code' => $success ? null : ($error ?? 'http_rejected'), 'payload' => $terminal ? null : $row->getRawOriginal('payload'),
            'delivered_at' => $success ? now() : null, 'lease_token' => null, 'lease_until' => null,
            'next_attempt_at' => $terminal ? null : now()->addSeconds(min(3600, 30 * (2 ** $row->attempts))), 'updated_at' => now()]);

        return true;
    }
}
