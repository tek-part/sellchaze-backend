<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreOrderExport;
use App\Models\User;
use App\Notifications\StoreOrderExportReady;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreOrderExports
{
    public const MAX_ROWS = 10000;

    public const MAX_BYTES = 16777216;

    /** @param array<string, mixed> $filters */
    public function request(Store $store, User $actor, array $filters, string $key): StoreOrderExport
    {
        return DB::transaction(function () use ($store, $actor, $filters, $key) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $existing = StoreOrderExport::where('user_id', $actor->id)->where('request_key', $key)->first();
            if ($existing) {
                abort_unless((int) $existing->store_id === (int) $store->id && (int) $existing->store_owner_id === (int) $store->owner_user_id, 409, 'Export request belongs to a different store context.');
                abort_unless($existing->filters === $filters, 409, 'Export request parameters changed.');

                return $existing;
            }
            $last = StoreOrderExport::where('user_id', $actor->id)->where('created_at', '>', now()->subMinutes(5))->latest('created_at')->first();
            if ($last) {
                abort(429, 'Please wait five minutes between order exports.', ['Retry-After' => (string) max(1, 300 - (int) $last->created_at->diffInSeconds(now()))]);
            }

            return StoreOrderExport::create(['id' => (string) Str::uuid(), 'store_id' => $store->id, 'store_owner_id' => $store->owner_user_id,
                'user_id' => $actor->id, 'request_key' => $key, 'filters' => $filters, 'status' => 'queued',
                'order_boundary' => (int) app(MerchantOrderQuery::class)->build($store, [])->max('store_orders.id'), 'expires_at' => now()->addDay()]);
        });
    }

    /** @return array<string, mixed> */
    public function present(StoreOrderExport $row): array
    {
        return ['id' => $row->id, 'status' => $row->expires_at->isPast() ? 'expired' : $row->status, 'row_count' => $row->row_count,
            'error_code' => $row->error_code, 'created_at' => $row->created_at, 'completed_at' => $row->completed_at, 'expires_at' => $row->expires_at];
    }

    public function process(int $limit = 5): int
    {
        $ids = StoreOrderExport::where('expires_at', '>', now())->where(fn ($query) => $query->where('status', 'queued')
            ->orWhere(fn ($query) => $query->where('status', 'processing')->where('lease_until', '<=', now())))
            ->orderBy('created_at')->limit(max(1, min(20, $limit)))->pluck('id');
        $processed = 0;
        foreach ($ids as $id) {
            if ($this->generate((string) $id)) {
                $processed++;
            }
        }
        $this->prune();

        return $processed;
    }

    public function pruneOrphanedFiles(): int
    {
        $removed = 0;
        foreach (Storage::disk('local')->allFiles('store-order-exports') as $path) {
            if ($removed >= 1000) {
                break;
            }
            if (preg_match('/^store-order-exports\/[a-f0-9-]{36}-[a-f0-9-]{36}\.enc$/', $path)
                && Storage::disk('local')->lastModified($path) < now()->subDays(2)->timestamp) {
                Storage::disk('local')->delete($path);
                $removed++;
            }
        }

        return $removed;
    }

    private function generate(string $id): bool
    {
        $claim = DB::transaction(function () use ($id) {
            $row = StoreOrderExport::whereKey($id)->lockForUpdate()->first();
            if (! $row || $row->expires_at->isPast() || ! in_array($row->status, ['queued', 'processing'], true)
                || ($row->status === 'processing' && $row->lease_until?->isFuture())) {
                return null;
            }
            if ($row->attempts >= 3) {
                $row->update(['status' => 'failed', 'error_code' => 'attempt_limit', 'filters' => null, 'lease_token' => null, 'lease_until' => null]);

                return null;
            }
            $row->update(['status' => 'processing', 'lease_token' => (string) Str::uuid(), 'lease_until' => now()->addMinutes(5), 'attempts' => $row->attempts + 1]);

            return $row;
        });
        if (! $claim) {
            return false;
        }
        $tenant = app(CurrentStore::class);
        $previous = $tenant->get();
        $path = null;
        try {
            $store = Store::find($claim->store_id);
            $actor = User::find($claim->user_id);
            if (! $store || ! $actor || ! $this->authorized($claim, $store, $actor)) {
                $this->finish($claim, ['status' => 'failed', 'error_code' => 'access_revoked', 'filters' => null]);

                return true;
            }
            $tenant->set($store);
            $query = app(MerchantOrderQuery::class)->build($store, $claim->filters ?? [])->where('store_orders.id', '<=', $claim->order_boundary);
            if ((clone $query)->count() > self::MAX_ROWS) {
                $this->finish($claim, ['status' => 'failed', 'error_code' => 'range_too_large']);

                return true;
            }
            $stream = fopen('php://temp', 'w+');
            if ($stream === false) {
                throw new \RuntimeException('Unable to initialize export.');
            }
            $ids = [];
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, ['ID', 'Order number', 'Customer', 'Phone', 'Email', 'Address', 'Status', 'Payment status', 'Currency', 'Subtotal', 'Shipping', 'Discount', 'Total', 'Order date', 'Products', 'Tax', 'Manual adjustment', 'Payment method adjustment'], ',', '"', '');
                foreach ($query->with('items')->orderBy('store_orders.id')->lazyById(200, 'store_orders.id', 'id') as $order) {
                    $values = [$order->id, $order->order_number, $order->customer_name, $order->customer_phone, $order->customer_email,
                        self::address($order->shipping_address), $order->status, $order->payment_status, $order->currency,
                        $order->subtotal, $order->shipping_total, $order->discount_total, $order->grand_total,
                        $order->placed_at?->toIso8601String(), $order->items->map(fn ($item) => $item->name.' × '.$item->quantity)->join('; '), $order->tax_total, $order->manual_adjustment_total ?? '0.00', $order->payment_adjustment['amount'] ?? '0.00'];
                    fputcsv($stream, array_map(self::cell(...), $values), ',', '"', '');
                    $ids[] = (int) $order->id;
                    if (count($ids) > self::MAX_ROWS || ftell($stream) > self::MAX_BYTES) {
                        throw ValidationException::withMessages(['export' => 'Export range is too large.']);
                    }
                }
                rewind($stream);
                $csv = stream_get_contents($stream);
                if ($csv === false) {
                    throw new \RuntimeException('Unable to read export.');
                }
            } finally {
                fclose($stream);
            }
            $path = 'store-order-exports/'.$claim->id.'-'.$claim->lease_token.'.enc';
            if (! Storage::disk('local')->put($path, Crypt::encryptString($csv), 'private')) {
                throw new \RuntimeException('Unable to save export.');
            }
            $saved = $this->finish($claim, ['status' => 'ready', 'artifact_path' => $path, 'order_ids' => $ids,
                'row_count' => count($ids), 'completed_at' => now(), 'error_code' => null], $actor);
            if (! $saved) {
                Storage::disk('local')->delete($path);
            }

            return true;
        } catch (\Throwable $error) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            $this->finish($claim, ['status' => 'failed', 'error_code' => $error instanceof ValidationException ? 'range_too_large' : 'generation_failed']);

            return true;
        } finally {
            $tenant->set($previous);
        }
    }

    /** @param array<string, mixed> $values */
    private function finish(StoreOrderExport $claim, array $values, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($claim, $values, $actor) {
            $current = StoreOrderExport::whereKey($claim->id)->where('status', 'processing')->where('lease_token', $claim->lease_token)->lockForUpdate()->first();
            if (! $current) {
                return false;
            }
            $current->update(array_merge($values, ['lease_token' => null, 'lease_until' => null]));
            if ($actor && $current->status === 'ready') {
                $actor->notify(new StoreOrderExportReady($current));
            }

            return true;
        });
    }

    public function authorized(StoreOrderExport $row, Store $store, User $actor): bool
    {
        return (int) $row->store_id === (int) $store->id && (int) $row->user_id === (int) $actor->id
            && (int) $row->store_owner_id === (int) $store->owner_user_id && $actor->is_active && ! $actor->pending_approval
            && $store->status === 'active' && $actor->can('store.orders.manage')
            && ((int) $store->owner_user_id === (int) $actor->id || (int) $actor->parent_user_id === (int) $store->owner_user_id || $actor->hasRole('Admin'));
    }

    public function download(StoreOrderExport $row, Store $store, User $actor): string
    {
        abort_unless($this->authorized($row, $store, $actor), 404);
        abort_unless($row->status === 'ready' && $row->expires_at->isFuture() && $row->artifact_path, 409, 'Export is not ready or has expired.');
        $ids = $row->order_ids ?? [];
        $visible = app(MerchantOrderQuery::class)->build($store, [])->whereIn('store_orders.id', $ids)->count();
        abort_unless($visible === count($ids), 409, 'Order access changed. Request a fresh export.');
        abort_unless(Storage::disk('local')->exists($row->artifact_path), 410, 'Export is no longer available.');

        return Crypt::decryptString(Storage::disk('local')->get($row->artifact_path));
    }

    private function prune(): void
    {
        foreach (StoreOrderExport::where('expires_at', '<=', now())->where('status', '!=', 'expired')->orderBy('expires_at')->limit(100)->get() as $row) {
            if ($row->artifact_path) {
                Storage::disk('local')->delete($row->artifact_path);
            }
            $row->update(['status' => 'expired', 'artifact_path' => null, 'filters' => null, 'order_ids' => null, 'lease_token' => null, 'lease_until' => null]);
        }
    }

    /** @param array<string, mixed>|null $address */
    public static function address(?array $address): string
    {
        if ($address === null) {
            return '';
        }
        $parts = [];
        foreach (['line1', 'line2', 'address', 'street', 'area', 'area_name', 'city', 'governorate', 'governorate_name', 'state', 'country', 'country_name', 'postal_code'] as $key) {
            if (isset($address[$key]) && is_scalar($address[$key]) && trim((string) $address[$key]) !== '') {
                $parts[] = trim((string) $address[$key]);
            }
        }

        return implode(', ', array_unique($parts));
    }

    public static function cell(mixed $value): string
    {
        $text = str_replace("\0", '', (string) ($value ?? ''));

        return preg_match('/^[\s]*[=+\-@]/u', $text) ? "'".$text : $text;
    }
}
