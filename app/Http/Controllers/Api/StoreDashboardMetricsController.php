<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\StoreDashboardMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class StoreDashboardMetricsController extends Controller
{
    public function __invoke(Request $request, Store $store, StoreDashboardMetrics $metrics): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $from = $data['from'] ?? now()->subDays(7)->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to), false);
        if ($days < 0 || $days > 365) {
            throw ValidationException::withMessages(['to' => 'Choose an inclusive date range of at most 366 days.']);
        }

        return response()->json(['data' => $metrics->build($store, $from, $to)], 200, ['Cache-Control' => 'private, no-store']);
    }
}
