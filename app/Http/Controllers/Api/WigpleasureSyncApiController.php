<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Wigpleasure\WigpleasureCatalogSyncService;
use App\Services\Wigpleasure\WigpleasureExportClient;
use App\Services\Wigpleasure\WigpleasureOrdersBulkSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WigpleasureSyncApiController extends Controller
{
    /** @var array<string, array<int, string>> */
    private const WIGPLEASURE_BASE_URL_OVERRIDE = [
        'wigpleasure_pull_base_url' => ['nullable', 'string', 'max:2048'],
    ];

    public function showSettings(): JsonResponse
    {
        return response()->json($this->settingsPayload());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wigpleasure_pull_base_url' => ['required', 'string', 'max:2048'],
            'wigpleasure_storefront_url' => ['nullable', 'string', 'max:2048'],
            'order_sync_statuses' => ['required', 'array', 'min:1'],
            'order_sync_statuses.*' => ['string', Rule::in(WigpleasureOrdersBulkSyncService::VALID_STATUSES)],
        ]);

        $base = rtrim(trim($validated['wigpleasure_pull_base_url']), '/');
        Setting::set('wigpleasure_pull_base_url', $base);

        $storeRaw = $validated['wigpleasure_storefront_url'] ?? null;
        if (is_string($storeRaw) && trim($storeRaw) !== '') {
            Setting::set('wigpleasure_storefront_url', rtrim(trim($storeRaw), '/'));
        } else {
            Setting::set('wigpleasure_storefront_url', '');
        }

        Setting::set('wigpleasure_sync_order_statuses', json_encode(array_values($validated['order_sync_statuses'])));
        Setting::clearCache();

        return response()->json(array_merge(
            ['message' => __('Wigpleasure sync settings saved.')],
            $this->settingsPayload()
        ));
    }

    public function ping(Request $request): JsonResponse
    {
        $validated = $request->validate(self::WIGPLEASURE_BASE_URL_OVERRIDE);
        $client = $this->wigpleasureClientFromValidated($validated);
        if (! $client->isConfigured()) {
            return response()->json([
                'ok' => false,
                'message' => __('Enter the Wigpleasure base URL above (or save settings), then try again.'),
            ], 422);
        }
        try {
            $client->get('categories', ['per_page' => 1, 'page' => 1]);

            return response()->json(['ok' => true, 'message' => __('Connection OK.')]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    public function syncCatalog(Request $request, WigpleasureCatalogSyncService $catalogSync): JsonResponse
    {
        $validated = $request->validate(self::WIGPLEASURE_BASE_URL_OVERRIDE);
        $client = $this->wigpleasureClientFromValidated($validated);
        if (! $client->isConfigured()) {
            return response()->json([
                'message' => __('Enter the Wigpleasure base URL above (or save settings), then try again.'),
            ], 422);
        }

        $result = $catalogSync->syncAll($client);

        return response()->json([
            'message' => __('Catalog sync finished.'),
            'result' => $result,
        ]);
    }

    public function syncOrders(Request $request, WigpleasureOrdersBulkSyncService $ordersSync): JsonResponse
    {
        $validated = $request->validate(array_merge(self::WIGPLEASURE_BASE_URL_OVERRIDE, [
            'order_sync_statuses' => ['nullable', 'array'],
            'order_sync_statuses.*' => ['string', Rule::in(WigpleasureOrdersBulkSyncService::VALID_STATUSES)],
        ]));

        $client = $this->wigpleasureClientFromValidated($validated);
        if (! $client->isConfigured()) {
            return response()->json([
                'message' => __('Enter the Wigpleasure base URL above (or save settings), then try again.'),
            ], 422);
        }

        $override = $validated['order_sync_statuses'] ?? null;

        try {
            $result = $ordersSync->sync($override, $client);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __('Order sync finished.'),
            'result' => $result,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(): array
    {
        $base = (string) (Setting::get('wigpleasure_pull_base_url') ?: config('services.wigpleasure_pull_base_url', ''));

        $storeSaved = Setting::get('wigpleasure_storefront_url');
        $storeInput = is_string($storeSaved) ? trim($storeSaved) : '';

        $statusesJson = Setting::get('wigpleasure_sync_order_statuses');
        $statuses = [];
        if (is_string($statusesJson) && $statusesJson !== '') {
            $decoded = json_decode($statusesJson, true);
            $statuses = is_array($decoded) ? $decoded : [];
        }

        return [
            'wigpleasure_pull_base_url' => $base,
            'wigpleasure_storefront_url' => $storeInput,
            'order_sync_statuses' => $statuses,
            'valid_order_statuses' => WigpleasureOrdersBulkSyncService::VALID_STATUSES,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function wigpleasureClientFromValidated(array $validated): WigpleasureExportClient
    {
        $baseRaw = $validated['wigpleasure_pull_base_url'] ?? '';
        $baseOverride = is_string($baseRaw) && trim($baseRaw) !== '' ? rtrim(trim($baseRaw), '/') : null;

        return new WigpleasureExportClient($baseOverride);
    }
}
