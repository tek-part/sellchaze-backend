<?php

namespace App\Services\Commerce;

use App\Models\AuthSession;
use App\Models\MerchantPushSubscription;
use App\Models\Store;
use App\Models\StoreOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class MerchantOrderPush
{
    public function __construct(private readonly MerchantPushSettings $settings, private readonly MerchantPushTransport $transport) {}

    /** Database-only, inside the checkout transaction; never invokes a push vendor. */
    public function record(StoreOrder $order): void
    {
        if (! $this->settings->ready()) {
            return;
        }
        $store = Store::find($order->store_id);
        if (! $store) {
            return;
        }
        $subscriptions = MerchantPushSubscription::where('store_id', $store->id)->where('user_id', $store->owner_user_id)->where('enabled', true)
            ->where('vapid_fingerprint', $this->settings->fingerprint())->get();
        foreach ($subscriptions as $subscription) {
            if (! AuthSession::whereKey($subscription->auth_session_id)->where('user_id', $subscription->user_id)->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
                continue;
            }
            $exists = DB::table('merchant_order_push_deliveries')->where('subscription_id', $subscription->id)->where('store_order_id', $order->id)->exists();
            if (! $exists) {
                DB::table('merchant_order_push_deliveries')->insert(['id' => (string) Str::uuid(), 'subscription_id' => $subscription->id, 'store_order_id' => $order->id, 'subscription_version' => $subscription->version,
                    'status' => 'queued', 'attempts' => 0, 'next_attempt_at' => now(), 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function deliver(int $limit = 50): int
    {
        if (! $this->settings->ready()) {
            return 0;
        }
        $ids = DB::table('merchant_order_push_deliveries')->where(function ($query) {
            $query->whereIn('status', ['queued', 'retry'])->where('next_attempt_at', '<=', now())->orWhere(function ($expired) {
                $expired->where('status', 'processing')->where('lease_until', '<', now());
            });
        })
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
            $row = DB::table('merchant_order_push_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (! $row || in_array($row->status, ['sent', 'skipped', 'failed', 'expired'], true)) {
                return null;
            }
            if ($row->status === 'processing' && $row->lease_until !== null && $row->lease_until >= now()->toDateTimeString()) {
                return null;
            }
            if ($row->next_attempt_at !== null && $row->next_attempt_at > now()->toDateTimeString()) {
                return null;
            }
            if ($row->expires_at <= now()->toDateTimeString()) {
                DB::table('merchant_order_push_deliveries')->where('id', $id)->update(['status' => 'expired', 'updated_at' => now()]);

                return null;
            }
            if ((int) $row->attempts >= 8) {
                DB::table('merchant_order_push_deliveries')->where('id', $id)->update(['status' => 'failed', 'lease_token' => null, 'lease_until' => null, 'updated_at' => now()]);

                return null;
            }
            $subscription = MerchantPushSubscription::whereKey($row->subscription_id)->lockForUpdate()->first();
            $device = null;
            try {
                $device = $subscription?->subscription;
            } catch (Throwable) {
                // A damaged credential must not stop deliveries to other devices.
            }
            $order = StoreOrder::find($row->store_order_id);
            $store = $order?->store;
            $valid = $subscription && $subscription->enabled && $subscription->version === (int) $row->subscription_version && $subscription->vapid_fingerprint === $this->settings->fingerprint()
                && $store && (int) $subscription->store_id === (int) $store->id && (int) $subscription->user_id === (int) $store->owner_user_id
                && $store->owner && $store->owner->is_active && ! $store->owner->pending_approval
                && AuthSession::whereKey($subscription->auth_session_id)->where('user_id', $subscription->user_id)->whereNull('revoked_at')->where('expires_at', '>', now())->exists()
                && is_array($device) && $this->settings->endpointAllowed((string) ($device['endpoint'] ?? ''));
            if (! $valid) {
                DB::table('merchant_order_push_deliveries')->where('id', $id)->update(['status' => 'skipped', 'updated_at' => now()]);

                return null;
            }
            $token = (string) Str::uuid();
            DB::table('merchant_order_push_deliveries')->where('id', $id)->update(['status' => 'processing', 'lease_token' => $token, 'lease_until' => now()->addSeconds(60), 'attempts' => (int) $row->attempts + 1, 'updated_at' => now()]);

            // Generic text avoids exposing locked/private order contents on an OS notification.
            return ['token' => $token, 'subscription' => $device, 'subscription_id' => $subscription->id, 'version' => $subscription->version,
                'payload' => ['title' => 'طلب جديد', 'body' => 'لديك طلب جديد في متجرك.', 'url' => '/store/orders', 'tag' => 'sellchaze-order-'.$id],
                'attempts' => (int) $row->attempts + 1];
        });
        if (! $claim) {
            return false;
        }
        $status = 0;
        $error = null;
        try {
            $status = $this->transport->send($claim['subscription'], $claim['payload'], substr(hash('sha256', $id), 0, 32));
        } catch (Throwable) {
            $error = 'transport_failed';
        }
        $sent = $status >= 200 && $status < 300;
        $retry = in_array($status, [0, 408, 425, 429], true) || $status >= 500;
        $next = $sent ? 'sent' : ($retry && $claim['attempts'] < 8 ? 'retry' : 'failed');
        $update = ['status' => $next, 'lease_token' => null, 'lease_until' => null, 'last_http_status' => $status ?: null, 'last_error_code' => $sent ? null : ($error ?? 'provider_rejected'),
            'next_attempt_at' => $next === 'retry' ? now()->addSeconds(min(300, 15 * (2 ** ($claim['attempts'] - 1)))) : null, 'sent_at' => $sent ? now() : null, 'updated_at' => now()];
        $updated = DB::table('merchant_order_push_deliveries')->where('id', $id)->where('lease_token', $claim['token'])->update($update);
        if ($updated && in_array($status, [404, 410], true)) {
            MerchantPushSubscription::whereKey($claim['subscription_id'])->where('version', $claim['version'])->update(['enabled' => false, 'version' => $claim['version'] + 1]);
        }

        return true;
    }
}
