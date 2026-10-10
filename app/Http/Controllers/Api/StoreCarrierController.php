<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreCarrierConnection;
use App\Services\Shipping\BostaClient;
use App\Services\Shipping\CarrierException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreCarrierController extends Controller
{
    public function store(Request $request, bool $settings = true): Store
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404);
        $user = $request->user();
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can($settings ? 'store.settings.manage' : 'store.orders.manage') || ($settings && $user->can('stores-edit'))), 403);

        return $store;
    }

    public function show(Request $request, Store $store): JsonResponse
    {
        return $this->present(StoreCarrierConnection::where('store_id', $this->store($request)->id)->where('carrier', 'bosta')->first());
    }

    public function update(Request $request, Store $store): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'min:10', 'max:4096', 'regex:/^[^\r\n]+$/'],
            'enabled' => ['required', 'boolean'], 'pickup_location_id' => ['nullable', 'string', 'max:120'],
        ]);
        $connection = DB::transaction(function () use ($store, $data) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $connection = StoreCarrierConnection::firstOrNew(['store_id' => $store->id, 'carrier' => 'bosta']);
            if (! empty($data['api_key']) && $data['api_key'] !== $connection->api_key) {
                $connection->fill(['api_key' => $data['api_key'], 'verified_at' => null, 'pickup_locations' => [], 'pickup_location_id' => null, 'enabled' => false]);
            } else {
                $connection->fill(['enabled' => (bool) $data['enabled'], 'pickup_location_id' => $data['pickup_location_id'] ?? null]);
                if ($connection->enabled && (! $connection->api_key || ! $connection->verified_at || ! collect($connection->pickup_locations)->contains('id', $connection->pickup_location_id))) {
                    throw ValidationException::withMessages(['enabled' => 'Verify the connection and select a pickup location before enabling shipping.']);
                }
            }
            $connection->save();

            return $connection;
        });

        return $this->present($connection);
    }

    public function verify(Request $request, Store $store, BostaClient $client): JsonResponse
    {
        $store = $this->store($request);
        $connection = StoreCarrierConnection::where('store_id', $store->id)->where('carrier', 'bosta')->firstOrFail();
        if (! $connection->api_key) {
            throw ValidationException::withMessages(['api_key' => 'Save an API key first.']);
        }
        try {
            $locations = $client->pickupLocations($connection);
        } catch (CarrierException) {
            return response()->json(['message' => 'Unable to verify the carrier connection. Check the API key and try again.'], 502);
        }
        $connection = DB::transaction(function () use ($connection, $store, $locations) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $current = StoreCarrierConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->api_key === $connection->api_key, 409, 'Connection changed. Verify again.');
            $pickup = collect($locations)->contains('id', $current->pickup_location_id) ? $current->pickup_location_id : (collect($locations)->firstWhere('is_default', true)['id'] ?? null);
            $current->update(['pickup_locations' => $locations, 'pickup_location_id' => $pickup, 'verified_at' => now(), 'enabled' => $current->enabled && $pickup !== null]);

            return $current;
        });

        return $this->present($connection);
    }

    private function present(?StoreCarrierConnection $connection): JsonResponse
    {
        return response()->json(['data' => [
            'carrier' => 'bosta', 'has_api_key' => (bool) $connection?->api_key,
            'enabled' => (bool) $connection?->enabled, 'verified_at' => $connection?->verified_at?->toIso8601String(),
            'pickup_location_id' => $connection?->pickup_location_id, 'pickup_locations' => $connection?->pickup_locations ?? [],
        ]]);
    }

    public function cities(Request $request, Store $store, BostaClient $client): JsonResponse
    {
        $this->store($request, false);
        try {
            return response()->json(['data' => $client->cities()]);
        } catch (CarrierException) {
            return response()->json(['message' => 'Carrier delivery areas are temporarily unavailable. Try again.'], 502);
        }
    }

    public function districts(Request $request, Store $store, BostaClient $client): JsonResponse
    {
        $this->store($request, false);
        $city = $request->validate(['city_id' => ['required', 'string', 'max:120']])['city_id'];
        try {
            abort_unless(collect($client->cities())->contains('id', $city), 422, 'Choose an available carrier city.');

            return response()->json(['data' => $client->districts($city)]);
        } catch (CarrierException) {
            return response()->json(['message' => 'Carrier delivery areas are temporarily unavailable. Try again.'], 502);
        }
    }
}
