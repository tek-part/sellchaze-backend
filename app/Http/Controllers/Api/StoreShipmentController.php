<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreCarrierConnection;
use App\Models\StoreOrder;
use App\Models\StoreShipment;
use App\Models\StoreShipmentEvent;
use App\Services\Shipping\BostaClient;
use App\Services\Shipping\CarrierException;
use App\Services\Shipping\StoreShipmentEvents;
use App\Services\Shipping\StoreShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

        return $this->present(StoreShipment::where('store_id', $order->store_id)->where('store_order_id', $order->id)->first());
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
            return $this->present($service->create($order, $input), 201);
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
            return $this->present($service->sync($shipment, $input['tracking_number'] ?? null));
        } catch (CarrierException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    private function present(?StoreShipment $shipment, int $status = 200): JsonResponse
    {
        if (! $shipment) {
            return response()->json(['data' => null], $status);
        }
        $events = StoreShipmentEvent::where('store_id', $shipment->store_id)->where('store_shipment_id', $shipment->id)->latest('id')->limit(30)->get();

        return response()->json(['data' => $shipment->toArray() + [
            'webhook_configured' => (bool) $shipment->webhook_secret && ! empty($shipment->request_snapshot['webhookUrl']),
            'events' => $events->map(fn (StoreShipmentEvent $event) => $event->toArray() + ['state_label' => StoreShipmentEvents::STATES[$event->carrier_state] ?? 'Carrier state '.$event->carrier_state]),
        ]], $status);
    }

    public function label(Request $request, Store $store, StoreCarrierController $access, BostaClient $client): Response|JsonResponse
    {
        $order = $this->order($request, $access);
        $input = $request->validate(['size' => ['required', Rule::in(['A4', 'A6'])], 'language' => ['required', Rule::in(['ar', 'en'])]]);
        $shipment = StoreShipment::where('store_id', $order->store_id)->where('store_order_id', $order->id)->firstOrFail();
        abort_unless($shipment->status === 'created' && $shipment->tracking_number && ! in_array($shipment->carrier_state, [45, 46, 48, 49, 60, 100, 101], true), 422, 'This shipment is not printable.');
        $connection = StoreCarrierConnection::where('store_id', $order->store_id)->whereKey($shipment->store_carrier_connection_id)->firstOrFail();
        abort_unless($connection->api_key, 422, 'Restore the carrier connection first.');
        try {
            $pdf = $client->airwayBill($connection, $shipment->tracking_number, $input['size'], $input['language']);
        } catch (CarrierException) {
            return response()->json(['message' => 'The carrier could not provide a printable PDF. Check the shipment status and try again.'], 502);
        }

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="bosta-'.$shipment->tracking_number.'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
    }
}
