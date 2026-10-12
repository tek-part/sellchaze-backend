<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreSheetConnection;
use App\Models\StoreSheetDelivery;
use App\Services\PlatformWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StoreSheetsDispatch
{
    public function __construct(private readonly GoogleSheetsProvider $provider) {}

    public function deliver(int $id): string
    {
        $claim = DB::transaction(function () use ($id) {
            $row = StoreSheetDelivery::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->first();
            if (! $row || in_array($row->status, ['delivered', 'failed', 'cancelled'], true) || $row->available_at?->isFuture() || $row->claimed_until?->isFuture()) {
                return null;
            }
            $connection = StoreSheetConnection::forStore((int) $row->store_id)->whereKey($row->connection_id)->first();
            $store = Store::with('owner')->find($row->store_id);
            $order = StoreOrder::forStore((int) $row->store_id)->find($row->store_order_id);
            if (! $connection || ! $connection->enabled || ! $store || $store->status !== 'active' || ! $store->owner?->is_active || $store->owner->pending_approval || ! $order) {
                $row->update(['status' => 'cancelled', 'error_code' => 'connection_unavailable', 'lease' => null, 'claimed_until' => null]);

                return null;
            }
            try {
                app(PlatformWallet::class)->assertOrderVisible($order);
            } catch (HttpException) {
                $row->update(['status' => 'blocked', 'error_code' => 'wallet_debt', 'available_at' => now()->addMinute(), 'lease' => null, 'claimed_until' => null]);

                return null;
            }
            if ($row->attempts >= 8) {
                $row->update(['status' => 'failed', 'error_code' => 'retry_budget_exhausted', 'lease' => null, 'claimed_until' => null]);

                return null;
            }
            $lease = (string) Str::uuid();
            $row->update(['status' => 'sending', 'attempts' => $row->attempts + 1, 'lease' => $lease, 'claimed_until' => now()->addMinutes(5)]);

            return [$row, $connection, $lease];
        });
        if (! $claim) {
            return 'skipped';
        }
        [$row, $connection, $lease] = $claim;
        $allowed = function () use ($row, $connection, $lease): bool {
            $current = StoreSheetConnection::forStore((int) $row->store_id)->whereKey($connection->id)->first();
            $store = Store::with('owner')->find($row->store_id);
            $order = StoreOrder::forStore((int) $row->store_id)->find($row->store_order_id);
            if (! $current || ! $current->enabled || (int) $current->version !== (int) $connection->version || ! $store || $store->status !== 'active' || ! $store->owner?->is_active || $store->owner->pending_approval || ! $order
                || ! StoreSheetDelivery::forStore((int) $row->store_id)->whereKey($row->id)->where('lease', $lease)->exists()) {
                return false;
            }
            try {
                app(PlatformWallet::class)->assertOrderVisible($order);
            } catch (HttpException) {
                return false;
            }

            return true;
        };
        $result = $this->provider->send($connection, $row, $allowed);
        if ($result['status'] === 'retry' && $row->attempts >= 8) {
            $result['status'] = 'failed';
        }
        $changed = StoreSheetDelivery::forStore((int) $row->store_id)->whereKey($row->id)->where('lease', $lease)->update($result + ['lease' => null, 'claimed_until' => null,
            'delivered_at' => $result['status'] === 'delivered' ? now() : null, 'available_at' => $result['status'] === 'retry' ? now()->addSeconds(min(3600, 30 * (2 ** ($row->attempts - 1)))) : null]);

        return $changed ? $result['status'] : 'skipped';
    }

    public function run(int $limit): array
    {
        $ids = StoreSheetDelivery::withoutGlobalScopes()->whereIn('status', ['pending', 'retry', 'sending', 'blocked'])->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('claimed_until')->orWhere('claimed_until', '<=', now()))->orderBy('id')->limit($limit)->pluck('id');
        $counts = ['delivered' => 0, 'retry' => 0, 'failed' => 0, 'cancelled' => 0, 'skipped' => 0];
        foreach ($ids as $id) {
            $counts[$this->deliver($id)]++;
        }

        return $counts;
    }
}
