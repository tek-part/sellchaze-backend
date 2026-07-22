<?php

namespace App\Services\Wigpleasure;

use App\Models\Order;
use App\Models\Setting;
use App\Services\OrderSyncService;
use Illuminate\Support\Facades\Log;

class WigpleasureOrdersBulkSyncService
{
    public const VALID_STATUSES = [
        'pending',
        'pending_payment',
        'processing',
        'on_hold',
        'completed',
        'canceled',
        'refunded',
    ];

    public function __construct(
        private readonly WigpleasureExportClient $client,
        private readonly OrderSyncService $orderSync
    ) {}

    /**
     * @param  list<string>|null  $statuses  Uses saved settings when null/empty.
     * @return array{created: int, updated: int, skipped: int, errors: list<array{wigpleasure_order_id: int, message: string}>}
     */
    public function sync(?array $statuses = null, ?WigpleasureExportClient $clientOverride = null): array
    {
        $client = $clientOverride ?? $this->client;

        $statuses = $this->normalizeStatuses($statuses);
        if ($statuses === []) {
            throw new \InvalidArgumentException('No valid order statuses to sync. Save statuses in settings or pass statuses in the request.');
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        $page = 1;
        do {
            $json = $client->get('orders', [
                'per_page' => 50,
                'page' => $page,
                'status' => $statuses,
            ]);

            foreach ($json['data'] ?? [] as $payload) {
                if (! is_array($payload)) {
                    continue;
                }
                $wid = (int) ($payload['wigpleasure_order_id'] ?? 0);
                if ($wid <= 0) {
                    $skipped++;

                    continue;
                }
                try {
                    $exists = Order::query()->where('wigpleasure_order_id', $wid)->exists();
                    if ($exists) {
                        $this->orderSync->updateSyncedOrder($payload, $wid);
                        $updated++;
                    } else {
                        $this->orderSync->sync($payload, null);
                        $created++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Wigpleasure bulk order sync row failed', [
                        'wigpleasure_order_id' => $wid,
                        'error' => $e->getMessage(),
                    ]);
                    $errors[] = [
                        'wigpleasure_order_id' => $wid,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            $last = (int) ($json['meta']['last_page'] ?? 1);
            $page++;
        } while ($page <= $last);

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<string>|null  $statuses
     * @return list<string>
     */
    private function normalizeStatuses(?array $statuses): array
    {
        if ($statuses === null || $statuses === []) {
            $raw = Setting::get('wigpleasure_sync_order_statuses');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $statuses = is_array($decoded) ? $decoded : [];
            } else {
                $statuses = [];
            }
        }

        $allowed = array_flip(self::VALID_STATUSES);
        $out = [];
        foreach ($statuses as $s) {
            if (! is_string($s)) {
                continue;
            }
            $s = trim($s);
            if ($s !== '' && isset($allowed[$s])) {
                $out[$s] = true;
            }
        }

        return array_keys($out);
    }
}
