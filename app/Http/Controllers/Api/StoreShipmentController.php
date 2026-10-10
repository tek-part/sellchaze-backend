<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreShipment;
use App\Services\Shipping\CarrierException;
use App\Services\Shipping\StoreShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreShipmentController extends Controller
{
    private function order(Request $request, StoreCarrierController $access): StoreOrder
    {
        $store = $access->store($request, false);

        return StoreOrder::where('store_id', $store->id)->whereKey((int) $request->route('order'))->firstOrFail();
    }

    public function show(Request $request, Store $store, StoreCarrierController $access): JsonResponse
    {
        $order = $this->order($request, $access);

        return response()->json(['data' => StoreShipment::where('store_id', $order->store_id)->where('store_order_id', $order->id)->first()]);
    }

    public function create(Request $request, Store $store, StoreCarrierController $access, StoreShipmentService $service): JsonResponse
    {
        $order = $this->order($request, $access);
        $input = $request->validate([
            'city_id' => ['required', 'string', 'max:120'], 'district_id' => ['required', 'string', 'max:120'],
            'address_line' => ['required', 'string', 'min:6', 'max:500'],
            'package_size' => ['required', Rule::in(['SMALL', 'MEDIUM', 'LARGE'])],
            'description' => ['required', 'string', 'max:500'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            return response()->json(['data' => $service->create($order, $input)], 201);
        } catch (CarrierException $e) {
            return response()->json(['message' => $e->getMessage(), 'ambiguous' => $e->ambiguous], 502);
        }
    }

    public function sync(Request $request, Store $store, StoreCarrierController $access, StoreShipmentService $service): JsonResponse
    {
        $order = $this->order($request, $access);
        $input = $request->validate(['tracking_number' => ['nullable', 'string', 'regex:/^[0-9]{1,30}$/']]);
        $shipment = StoreShipment::where('store_id', $order->store_id)->where('store_order_id', $order->id)->firstOrFail();
        try {
            return response()->json(['data' => $service->sync($shipment, $input['tracking_number'] ?? null)]);
        } catch (CarrierException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
