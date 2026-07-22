<?php

namespace App\Services\Wigpleasure;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WigpleasureOrderStatusClient
{
    public const STORE_STATUSES = [
        'pending',
        'pending_payment',
        'processing',
        'on_hold',
        'completed',
        'canceled',
        'refunded',
    ];

    public function isConfigured(): bool
    {
        $base = $this->baseUrl();

        return $base !== '' && trim((string) config('services.wigpleasure_inbound_key', '')) !== '';
    }

    public function pushStatus(int $wigpleasureOrderId, string $status, ?int $returnWarehouseId = null): bool
    {
        if (! $this->isConfigured()) {
            Log::warning('WigpleasureOrderStatusClient: missing base URL or WIGPLEASURE_INBOUND_KEY');

            return false;
        }

        $key = (string) config('services.wigpleasure_inbound_key', '');
        $url = $this->baseUrl().'/api/sellchase-inbound/orders/'.$wigpleasureOrderId.'/status';
        $body = ['status' => $status];
        if ($returnWarehouseId !== null && $returnWarehouseId > 0) {
            $body['return_warehouse_id'] = $returnWarehouseId;
        }

        try {
            $response = Http::withHeaders([
                'X-Sellchase-Inbound-Key' => $key,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
                ->timeout(25)
                ->patch($url, $body);

            if (! $response->successful()) {
                Log::warning('WigpleasureOrderStatusClient: inbound PATCH failed', [
                    'wigpleasure_order_id' => $wigpleasureOrderId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('WigpleasureOrderStatusClient: exception', [
                'wigpleasure_order_id' => $wigpleasureOrderId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function baseUrl(): string
    {
        $fromDb = Setting::get('wigpleasure_pull_base_url');

        return rtrim((string) ($fromDb ?: config('services.wigpleasure_pull_base_url', '')), '/');
    }
}
