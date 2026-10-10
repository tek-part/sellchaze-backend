<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoreShipment;
use App\Services\Shipping\CarrierException;
use App\Services\Shipping\StoreShipmentEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BostaWebhookController extends Controller
{
    public function __invoke(Request $request, string $reference, StoreShipmentEvents $events): JsonResponse
    {
        $shipment = StoreShipment::where('carrier', 'bosta')->where('business_reference', $reference)->first();
        abort_unless($shipment && $shipment->webhook_secret && hash_equals($shipment->webhook_secret, (string) $request->bearerToken()), 401);
        $input = $request->validate([
            '_id' => ['required', 'string', 'max:255'], 'trackingNumber' => ['required', 'regex:/^[0-9]{1,30}$/'],
            'state' => ['required', 'integer', Rule::in(array_keys(StoreShipmentEvents::STATES))],
            'type' => ['required', Rule::in(['SEND'])],
            'timeStamp' => ['required', 'integer', 'min:946684800000', 'max:'.(now()->getTimestampMs() + 300000)],
            'businessReference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        abort_if(isset($input['businessReference']) && $input['businessReference'] !== $shipment->business_reference, 409, 'Shipment identity mismatch.');
        try {
            $events->apply($shipment, $input['_id'], (string) $input['trackingNumber'], (int) $input['state'], (int) $input['timeStamp'], 'webhook');
        } catch (CarrierException) {
            return response()->json(['message' => 'Shipment identity mismatch.'], 409);
        }

        return response()->json(['received' => true]);
    }
}
