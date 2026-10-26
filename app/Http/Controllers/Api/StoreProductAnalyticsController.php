<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\ProductAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** G4: per-product funnel (#49) and the daily ordered-products panel (#7). Store-scoped, store.analytics.view. */
class StoreProductAnalyticsController extends Controller
{
    public function __construct(private readonly ProductAnalytics $analytics) {}

    /** GET analytics/product-funnel?from&to&utm_source&utm_medium&utm_campaign */
    public function funnel(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'utm_source' => ['nullable', 'string', 'max:100'], 'utm_medium' => ['nullable', 'string', 'max:100'], 'utm_campaign' => ['nullable', 'string', 'max:200']]);
        $today = now($this->analytics->timezone($store))->toDateString();
        $from = $data['from'] ?? Carbon::parse($today)->subDays(6)->toDateString();
        $to = $data['to'] ?? $today;
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to), false);
        if ($days < 0 || $days > 365) {
            throw ValidationException::withMessages(['to' => 'Choose an inclusive date range of at most 366 days.']);
        }

        return response()->json(['data' => $this->analytics->funnel($store, $from, $to, $data)], 200, ['Cache-Control' => 'private, no-store'], JSON_UNESCAPED_UNICODE);
    }

    /** GET analytics/ordered-products?date=YYYY-MM-DD (default: today in the store timezone) */
    public function ordered(Request $request, Store $store): JsonResponse
    {
        return response()->json(['data' => $this->analytics->orderedOn($store, $this->date($request, $store))], 200, ['Cache-Control' => 'private, no-store'], JSON_UNESCAPED_UNICODE);
    }

    /** GET analytics/ordered-products/export?date&locale — UTF-8 CSV with a BOM so spreadsheets read Arabic names. */
    public function export(Request $request, Store $store): StreamedResponse
    {
        $date = $this->date($request, $store);
        $ar = $request->validate(['locale' => ['nullable', 'in:ar,en']])['locale'] ?? null;
        $report = $this->analytics->orderedOn($store, $date);
        $headers = $ar === 'ar' ? ['المنتج', 'المتغير', 'الخيارات', 'SKU', 'الكمية', 'الطلبات'] : ['Product', 'Variant', 'Options', 'SKU', 'Quantity', 'Orders'];

        return response()->streamDownload(function () use ($report, $headers) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ',', '"', '');
            foreach ($report['items'] as $row) {
                $options = implode(' · ', array_map(fn ($option) => trim($option['name'].': '.$option['value'], ': '), $row['options']));
                fputcsv($out, array_map(fn ($value) => $this->cell($value), [$row['name'], $row['variant_name'] ?? '', $options, $row['sku'] ?? '', $row['quantity'], $row['orders']]), ',', '"', '');
            }
            fclose($out);
        }, 'ordered-products-'.$date.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function date(Request $request, Store $store): string
    {
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? null;

        return $date ?? now($this->analytics->timezone($store))->toDateString();
    }

    /** Neutralises spreadsheet formulas in merchant- or customer-entered text. */
    private function cell(mixed $value): string|int
    {
        if (is_int($value)) {
            return $value;
        }
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
