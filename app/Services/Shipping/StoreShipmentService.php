<?php

namespace App\Services\Shipping;

use App\Models\StoreCarrierConnection;
use App\Models\StoreOrder;
use App\Models\StoreShipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreShipmentService
{
    public function __construct(private readonly BostaClient $client, private readonly StoreShipmentEvents $events) {}

    public function create(StoreOrder $order, array $input): StoreShipment
    {
        if (! $order->requiresShipping()) {
            throw ValidationException::withMessages(['order' => 'This digital order does not require shipping.']);
        }
        $city = collect($this->client->cities())->firstWhere('id', $input['city_id']);
        if (! $city || ! collect($this->client->districts($input['city_id']))->contains('id', $input['district_id'])) {
            throw ValidationException::withMessages(['district_id' => 'Choose an available carrier city and district.']);
        }
        [$shipment, $connection] = DB::transaction(function () use ($order, $input, $city) {
            $order = StoreOrder::where('store_id', $order->store_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $physicalQuantity = (int) $order->physicalItems()->sum('quantity');
            if ($physicalQuantity < 1) {
                throw ValidationException::withMessages(['order' => 'Only physical order items can be shipped.']);
            }
            $existing = StoreShipment::where('store_id', $order->store_id)->where('store_order_id', $order->id)->first();
            abort_if($existing && $existing->status !== 'rejected', 409, 'A shipment already exists or its result is unknown. Refresh or reconcile it before proceeding.');
            $connection = StoreCarrierConnection::where('store_id', $order->store_id)->where('carrier', 'bosta')->first();
            if (! $connection || ! $connection->enabled || ! $connection->verified_at || ! $connection->api_key || ! $connection->pickup_location_id) {
                throw ValidationException::withMessages(['carrier' => 'Enable a verified Bosta connection in shipping settings first.']);
            }
            if (! in_array($order->status, ['confirmed', 'processing'], true)) {
                throw ValidationException::withMessages(['order' => 'Confirm the order before requesting shipping. Only confirmed or processing orders can be sent.']);
            }
            if ($order->currency !== 'EGP' || ($order->shipping_address['country'] ?? '') !== 'EG') {
                throw ValidationException::withMessages(['order' => 'This connection supports Egyptian addresses and EGP orders.']);
            }
            if ($order->payment_status !== 'paid' && ($order->payment_method !== 'cod' || ! in_array($order->payment_status, ['pending', 'unpaid'], true))) {
                throw ValidationException::withMessages(['order' => 'The order must be paid or awaiting cash on delivery.']);
            }
            $cod = $order->payment_status === 'paid' ? '0.00' : $order->grand_total;
            if (bccomp($cod, '30000', 2) > 0 || bccomp($cod, '0', 2) < 0 || ! $order->items()->exists()) {
                throw ValidationException::withMessages(['order' => 'Check the order items and cash collection amount (maximum EGP 30,000).']);
            }
            $reference = $existing?->business_reference ?? 'sc-'.Str::uuid();
            $name = preg_split('/\s+/u', trim((string) $order->customer_name), 2);
            $phone = preg_replace('/[\s()+-]/', '', (string) $order->customer_phone);
            if (str_starts_with($phone, '0020')) {
                $phone = substr($phone, 3);
            } elseif (str_starts_with($phone, '20')) {
                $phone = substr($phone, 1);
            }
            if (empty($name[0]) || ! preg_match('/^01[0125][0-9]{8}$/', $phone)) {
                throw ValidationException::withMessages(['order' => 'A customer name and valid Egyptian mobile number are required.']);
            }
            $payload = [
                'type' => 10, 'cod' => (float) $cod, 'businessReference' => $reference, 'uniqueBusinessReference' => $reference,
                'businessLocationId' => $connection->pickup_location_id, 'allowToOpenPackage' => false,
                'dropOffAddress' => ['city' => $city['name'], 'districtId' => $input['district_id'], 'firstLine' => $input['address_line']],
                'receiver' => ['firstName' => $name[0], 'lastName' => $name[1] ?? '', 'phone' => $phone],
                'specs' => ['packageType' => 'Parcel', 'size' => $input['package_size'], 'packageDetails' => [
                    'itemsCount' => $physicalQuantity, 'description' => $input['description'],
                ]], 'notes' => $input['notes'] ?? '',
            ];
            $shipment = $existing ?? new StoreShipment;
            $webhookUrl = $this->events->webhookUrl($reference);
            if ($webhookUrl !== null) {
                $shipment->webhook_secret = $shipment->webhook_secret ?: Str::random(64);
                $payload['webhookUrl'] = $webhookUrl;
                $payload['webhookCustomHeaders'] = ['Authorization' => 'Bearer '.$shipment->webhook_secret];
            }
            $shipment->fill(['store_id' => $order->store_id, 'store_order_id' => $order->id, 'store_carrier_connection_id' => $connection->id,
                'carrier' => 'bosta', 'business_reference' => $reference, 'status' => 'submitting', 'error_code' => null,
                'request_snapshot' => $payload, 'submitted_at' => now()])->save();

            return [$shipment, $connection];
        });
        // Commit the claim before the network call. A crash/timeout must never silently retry a delivery.
        try {
            $data = $this->client->request('POST', '/deliveries?apiVersion=1', $shipment->request_snapshot, $connection);
            $this->accept($shipment, $data, 'creation');
        } catch (CarrierException $e) {
            // A webhook can confirm delivery while the original POST is still awaiting its response.
            StoreShipment::where('store_id', $shipment->store_id)->whereKey($shipment->id)->where('status', 'submitting')
                ->where('carrier_revision', (int) $shipment->carrier_revision)->update(['status' => $e->ambiguous ? 'unknown' : 'rejected', 'error_code' => $e->reason]);
            $current = $shipment->fresh();
            if ($current->status === 'created') {
                return $current;
            }
            throw $e;
        }

        return $shipment->fresh();
    }

    public function sync(StoreShipment $shipment, ?string $tracking = null): StoreShipment
    {
        $tracking = $shipment->tracking_number ?: $tracking;
        if (! $tracking) {
            throw ValidationException::withMessages(['tracking_number' => 'Enter the tracking number from the carrier dashboard to reconcile this request.']);
        }
        $connection = StoreCarrierConnection::where('store_id', $shipment->store_id)->whereKey($shipment->store_carrier_connection_id)->firstOrFail();
        if (! $connection->api_key) {
            throw ValidationException::withMessages(['carrier' => 'Restore the carrier connection before refreshing.']);
        }
        $data = $this->client->request('GET', '/deliveries/business/'.rawurlencode($tracking), [], $connection);
        if (! is_array($data) || (string) ($data['trackingNumber'] ?? '') !== $tracking) {
            throw new CarrierException(true, 'tracking_mismatch');
        }
        $this->accept($shipment, $data, 'refresh');

        return $shipment->fresh();
    }

    private function accept(StoreShipment $shipment, mixed $data, string $source): void
    {
        if (! is_array($data) || ! is_string($data['_id'] ?? null) || empty($data['_id']) || ! is_scalar($data['trackingNumber'] ?? null)
            || ! preg_match('/^[0-9]{1,30}$/', (string) $data['trackingNumber'])
            || ($data['businessReference'] ?? null) !== $shipment->business_reference
            || ! is_numeric($data['state']['code'] ?? null)
            || ($shipment->external_id && $shipment->external_id !== $data['_id'])) {
            throw new CarrierException(true, 'shipment_mismatch');
        }
        $timeMs = null;
        if (is_string($data['updatedAt'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $data['updatedAt'])) {
            try {
                $timeMs = Carbon::parse($data['updatedAt'])->getTimestampMs();
            } catch (\Exception) {
                throw new CarrierException(true, 'invalid_carrier_time');
            }
            if ($timeMs > now()->getTimestampMs() + 300000 || $timeMs < 946684800000) {
                throw new CarrierException(true, 'invalid_carrier_time');
            }
        }
        $this->events->apply($shipment, $data['_id'], (string) $data['trackingNumber'], (int) $data['state']['code'], $timeMs, $source, (int) $shipment->carrier_revision);
    }
}
