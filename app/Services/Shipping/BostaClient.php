<?php

namespace App\Services\Shipping;

use App\Models\StoreCarrierConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Official v2 contract: https://docs.bosta.co/api/ . No automatic mutation retries. */
class BostaClient
{
    private const BASE = 'https://app.bosta.co/api/v2';

    public function request(string $method, string $path, array $data = [], ?StoreCarrierConnection $connection = null): mixed
    {
        $http = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => false]);
        if ($connection !== null) {
            $http = $http->withHeaders(['Authorization' => $connection->api_key]);
        }
        try {
            $response = $http->send($method, self::BASE.$path, [$method === 'GET' ? 'query' : 'json' => $data]);
        } catch (ConnectionException) {
            throw new CarrierException(true, 'connection');
        }
        if (! $response->successful()) {
            // A timeout, gateway failure or unexpected redirect may follow a successful creation.
            $definiteRejection = in_array($response->status(), [400, 401, 403, 404, 422, 429], true);
            throw new CarrierException(! $definiteRejection, 'http_'.$response->status());
        }
        $body = $response->json();
        if (! is_array($body) || ($body['success'] ?? null) !== true || ! array_key_exists('data', $body)) {
            throw new CarrierException(true, 'invalid_response');
        }

        return $body['data'];
    }

    public function pickupLocations(StoreCarrierConnection $connection): array
    {
        $data = $this->request('GET', '/pickup-locations', [], $connection);
        if (! is_array($data) || ! is_array($data['list'] ?? null)) {
            throw new CarrierException(true, 'invalid_locations');
        }

        return collect($data['list'])->filter(fn ($row) => is_array($row) && is_string($row['_id'] ?? null))
            ->map(fn ($row) => ['id' => $row['_id'], 'name' => (string) ($row['locationName'] ?? $row['_id']), 'is_default' => (bool) ($row['isDefault'] ?? false)])
            ->values()->all();
    }

    public function cities(): array
    {
        return Cache::remember('shipping:bosta:eg:cities:v1', 3600, function () {
            $data = $this->request('GET', '/cities', ['countryId' => '60e4482c7cb7d4bc4849c4d5']);
            if (! is_array($data) || ! is_array($data['list'] ?? null)) {
                throw new CarrierException(true, 'invalid_cities');
            }

            return collect($data['list'])->filter(fn ($row) => is_array($row) && isset($row['_id'], $row['name']) && ($row['dropOffAvailability'] ?? true))
                ->map(fn ($row) => ['id' => (string) $row['_id'], 'name' => (string) $row['name'], 'name_ar' => (string) ($row['nameAr'] ?? $row['name'])])->values()->all();
        });
    }

    public function districts(string $city): array
    {
        return Cache::remember('shipping:bosta:districts:v1:'.hash('sha256', $city), 3600, function () use ($city) {
            $data = $this->request('GET', '/cities/'.rawurlencode($city).'/districts');
            if (! is_array($data) || ! array_is_list($data)) {
                throw new CarrierException(true, 'invalid_districts');
            }

            return collect($data)->filter(fn ($row) => is_array($row) && isset($row['districtId'], $row['districtName']) && ($row['dropOffAvailability'] ?? false))
                ->map(fn ($row) => ['id' => (string) $row['districtId'], 'name' => (string) $row['districtName'], 'name_ar' => (string) ($row['districtOtherName'] ?? $row['districtName'])])->values()->all();
        });
    }
}
