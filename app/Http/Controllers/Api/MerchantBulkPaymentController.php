<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\MerchantBulkPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MerchantBulkPaymentController extends Controller
{
    public function preview(Request $request, Store $store, MerchantBulkPayments $payments): JsonResponse
    {
        $data = $request->validate(['target' => ['required', Rule::in(MerchantBulkPayments::TARGETS)],
            'ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'integer', 'min:1', 'distinct']]);

        return response()->json(['data' => $payments->preview($store, $data['ids'], $data['target'])])->header('Cache-Control', 'private, no-store');
    }

    public function apply(Request $request, Store $store, MerchantBulkPayments $payments): JsonResponse
    {
        $data = $request->validate(['target' => ['required', Rule::in(MerchantBulkPayments::TARGETS)], 'request_id' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted'],
            'orders' => ['required', 'array', 'min:1', 'max:100'], 'orders.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'orders.*.version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'orders.*.reference' => ['nullable', 'string', 'max:200'],
            'orders.*.amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999.99'], 'orders.*.currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'orders.*.acknowledged' => ['sometimes', 'boolean'], 'orders.*.note' => ['nullable', 'string', 'max:2000']]);

        return response()->json($payments->apply($store, $data, (int) $request->user()->id))->header('Cache-Control', 'private, no-store');
    }
}
