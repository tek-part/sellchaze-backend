<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreTrackingDelivery;
use App\Models\StoreTrackingEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreTrackingDispatch
{
    public function __construct(private readonly StoreTrackingSettings $settings, private readonly StoreTrackingProvider $provider) {}

    public function deliver(int $id): string
    {
        $claim = DB::transaction(function () use ($id) {
            $delivery = StoreTrackingDelivery::whereKey($id)->lockForUpdate()->first();
            if (! $delivery || in_array($delivery->status, ['delivered', 'failed', 'cancelled'], true)
                || ($delivery->available_at && $delivery->available_at->isFuture())
                || ($delivery->status === 'sending' && $delivery->claimed_until?->isFuture())) {
                return null;
            }
            if ($delivery->attempts >= 5) {
                $delivery->update(['status' => 'failed', 'error_code' => 'retry_budget_exhausted', 'lease' => null, 'claimed_until' => null]);

                return null;
            }
            $event = StoreTrackingEvent::where('store_id', $delivery->store_id)->find($delivery->store_tracking_event_id);
            $store = Store::find($delivery->store_id);
            $row = $store ? collect($this->settings->configuration($store)['conversions'])->firstWhere('id', $delivery->destination_id) : null;
            if (! $event || ! $store || $store->status !== 'active' || ! $row || ! $row['enabled'] || $row['generation'] !== $delivery->generation
                || ! isset($store->tracking_credentials[$delivery->destination_id]) || $event->occurred_at->lessThan(now()->subDays(7))) {
                $delivery->update(['status' => 'cancelled', 'error_code' => 'destination_unavailable', 'lease' => null, 'claimed_until' => null]);

                return null;
            }
            $lease = (string) Str::uuid();
            $delivery->update(['status' => 'sending', 'attempts' => $delivery->attempts + 1, 'lease' => $lease, 'claimed_until' => now()->addMinutes(2)]);

            return [$delivery, $event, $store->tracking_credentials[$delivery->destination_id], $lease];
        });
        if (! $claim) {
            return 'skipped';
        }
        [$delivery, $event, $token, $lease] = $claim;
        $result = $this->provider->send($delivery->provider, $delivery->pixel_id, $token, $event->payload, $event->occurred_at->timestamp);
        if ($result['status'] === 'retry' && $delivery->attempts >= 5) {
            $result['status'] = 'failed';
        }
        $updated = StoreTrackingDelivery::whereKey($delivery->id)->where('lease', $lease)->update($result + ['lease' => null, 'claimed_until' => null,
            'delivered_at' => $result['status'] === 'delivered' ? now() : null, 'available_at' => $result['status'] === 'retry' ? now()->addSeconds(min(3600, 60 * (2 ** ($delivery->attempts - 1)))) : null]);

        return $updated ? $result['status'] : 'skipped';
    }

    public function run(int $limit): array
    {
        $ids = StoreTrackingDelivery::whereIn('status', ['pending', 'retry', 'sending'])
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<=', now()))->orderBy('id')->limit($limit)->pluck('id');
        $counts = ['delivered' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($ids as $id) {
            $counts[$this->deliver($id)]++;
        }

        return $counts;
    }
}
