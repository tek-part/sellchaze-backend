<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreOrderExport;
use App\Services\Commerce\MerchantOrderQuery;
use App\Services\Commerce\StoreOrderExports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StoreOrderExportController extends Controller
{
    public function index(Request $request, Store $store, StoreOrderExports $exports): JsonResponse
    {
        $rows = StoreOrderExport::where('store_id', $store->id)->where('store_owner_id', $store->owner_user_id)->where('user_id', $request->user()->id)->latest()->limit(20)->get();

        return response()->json(['data' => $rows->map(fn ($row) => $exports->present($row))]);
    }

    public function store(Request $request, Store $store, StoreOrderExports $exports, MerchantOrderQuery $query): JsonResponse
    {
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'date_from' => ['required', 'date_format:Y-m-d'], 'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from']]);
        $row = $exports->request($store, $request->user(), $query->filters($request), $data['request_key']);

        return response()->json(['data' => $exports->present($row)], 202);
    }

    public function show(Request $request, Store $store, string $export, StoreOrderExports $exports): JsonResponse
    {
        $row = StoreOrderExport::where('store_id', $store->id)->where('store_owner_id', $store->owner_user_id)->where('user_id', $request->user()->id)->findOrFail($export);

        return response()->json(['data' => $exports->present($row)]);
    }

    public function download(Request $request, Store $store, string $export, StoreOrderExports $exports): Response
    {
        $row = StoreOrderExport::where('store_id', $store->id)->where('user_id', $request->user()->id)->findOrFail($export);
        $csv = $exports->download($row, $store, $request->user());

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="orders-'.$row->id.'.csv"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
