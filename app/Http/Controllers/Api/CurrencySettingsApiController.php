<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurrencyRate;
use App\Services\CurrencyRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencySettingsApiController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = CurrencyRate::query()
            ->orderBy('currency_code')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'currency_code' => $row->currency_code,
                'rate_to_usd' => (float) $row->rate_to_usd,
                'source' => $row->source,
                'is_manual_override' => (bool) $row->is_manual_override,
                'updated_at' => optional($row->updated_at)?->toISOString(),
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function store(Request $request, CurrencyRateService $service): JsonResponse
    {
        $validated = $request->validate([
            'currency_code' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'rate_to_usd' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $rate = array_key_exists('rate_to_usd', $validated)
            ? (float) $validated['rate_to_usd']
            : null;
        $row = $service->createCurrency((string) $validated['currency_code'], $rate);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $row->id,
                'currency_code' => $row->currency_code,
                'rate_to_usd' => (float) $row->rate_to_usd,
                'source' => $row->source,
                'is_manual_override' => (bool) $row->is_manual_override,
                'updated_at' => optional($row->updated_at)?->toISOString(),
            ],
        ], 201);
    }

    public function update(string $currencyCode, Request $request, CurrencyRateService $service): JsonResponse
    {
        $validated = $request->validate([
            'rate_to_usd' => ['required', 'numeric', 'gt:0'],
        ]);

        $row = $service->upsertManualRate($currencyCode, (float) $validated['rate_to_usd']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $row->id,
                'currency_code' => $row->currency_code,
                'rate_to_usd' => (float) $row->rate_to_usd,
                'source' => $row->source,
                'is_manual_override' => (bool) $row->is_manual_override,
                'updated_at' => optional($row->updated_at)?->toISOString(),
            ],
        ]);
    }

    public function refresh(CurrencyRateService $service): JsonResponse
    {
        $result = $service->refreshFromApi();

        return response()->json([
            'success' => true,
            ...$result,
        ]);
    }
}
