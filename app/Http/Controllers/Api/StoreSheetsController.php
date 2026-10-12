<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreSheetConnection;
use App\Models\StoreSheetDelivery;
use App\Services\Commerce\GoogleSheetsProvider;
use App\Services\Commerce\StoreSheets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreSheetsController extends Controller
{
    public function __construct(private readonly StoreSheets $sheets) {}

    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store && $user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.settings.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    public function index(Request $request): JsonResponse
    {
        $store = $this->store($request);

        return response()->json(['data' => StoreSheetConnection::forStore($store)->latest()->get()->map(fn ($row) => $this->sheets->present($row))], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function save(Request $request, Store $store, GoogleSheetsProvider $provider, ?string $connection = null): JsonResponse
    {
        $store = $this->store($request);
        if ($request->has('configuration')) {
            $encoded = $request->input('configuration');
            try {
                $parsed = is_string($encoded) && strlen($encoded) <= 3000 ? json_decode($encoded, true, 8, JSON_THROW_ON_ERROR) : null;
            } catch (\JsonException) {
                $parsed = null;
            }
            if (! is_array($parsed) || array_keys($parsed) !== ['product_ids']) {
                throw ValidationException::withMessages(['configuration' => 'Use a valid product selection.']);
            }
            $request->merge(['product_ids' => $parsed['product_ids']]);
        }
        $data = $request->validate(['request_key' => [$connection ? 'nullable' : 'required', 'uuid'], 'version' => [$connection ? 'required' : 'nullable', 'integer', 'min:1'],
            'spreadsheet_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{20,128}$/D'], 'enabled' => ['required', 'boolean'], 'all_products' => ['required', 'boolean'],
            'product_ids' => ['present', 'array', 'max:100'], 'product_ids.*' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where('store_id', $store->id)],
            'service_account' => [$connection ? 'nullable' : 'required', 'file', 'max:32']]);
        if (! $data['all_products'] && empty($data['product_ids'])) {
            throw ValidationException::withMessages(['product_ids' => 'Select a product or enable all products.']);
        }
        $data['enabled'] = (bool) $data['enabled'];
        $data['all_products'] = (bool) $data['all_products'];
        $data['product_ids'] = $data['all_products'] ? [] : array_map('intval', $data['product_ids']);
        sort($data['product_ids']);
        $credentials = null;
        if ($file = $request->file('service_account')) {
            try {
                $json = json_decode($file->get(), true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['service_account' => 'Upload the Google service-account JSON file.']);
            }
            if (! is_array($json)) {
                throw ValidationException::withMessages(['service_account' => 'Upload the Google service-account JSON file.']);
            }
            $credentials = $provider->credentials($json);
        }
        $row = DB::transaction(function () use ($store, $data, $credentials, $connection) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            if ($connection) {
                $row = StoreSheetConnection::forStore($store)->whereKey($connection)->lockForUpdate()->firstOrFail();
                abort_unless((int) $row->version === (int) $data['version'], 409, 'Sheet connection changed. Reload before saving.');
                abort_unless($row->spreadsheet_id === $data['spreadsheet_id'] && (! $credentials || $credentials['client_email'] === $row->service_email), 409, 'Create a separate connection for another spreadsheet or service account.');
                if ($credentials) {
                    $row->credentials = $credentials;
                }
                if (! $row->enabled && $data['enabled']) {
                    $row->starts_at = now();
                }
                $row->version++;
            } else {
                $id = strtolower($data['request_key']);
                $hash = hash('sha256', json_encode([(int) $store->id, $data['spreadsheet_id'], $credentials, $data['enabled'], $data['all_products'], $data['product_ids']], JSON_THROW_ON_ERROR));
                $existing = StoreSheetConnection::withoutGlobalScopes()->withTrashed()->whereKey($id)->first();
                if ($existing) {
                    abort_unless((int) $existing->store_id === (int) $store->id && ! $existing->trashed() && hash_equals($existing->creation_hash, $hash), 409, 'Sheet request key already used.');

                    return $existing;
                }
                abort_if(StoreSheetConnection::forStore($store)->count() >= 20, 422, 'Use at most 20 active sheet connections.');
                $row = new StoreSheetConnection(['id' => $id, 'store_id' => $store->id, 'creation_hash' => $hash, 'spreadsheet_id' => $data['spreadsheet_id'], 'service_email' => $credentials['client_email'], 'credentials' => $credentials,
                    'sheet_id' => ((int) sprintf('%u', crc32($id)) % 2000000000) + 1, 'sheet_title' => 'Sellchaze-'.$id, 'starts_at' => now()]);
            }
            $row->fill(['enabled' => $data['enabled'], 'all_products' => $data['all_products'], 'product_ids' => $data['product_ids']]);
            $row->save();

            return $row;
        });

        return response()->json(['data' => $this->sheets->present($row->refresh())], $connection ? 200 : 201, ['Cache-Control' => 'private, no-store']);
    }

    public function destroy(Request $request, Store $store, string $connection): JsonResponse
    {
        $store = $this->store($request);
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        DB::transaction(function () use ($store, $version, $connection) {
            $row = StoreSheetConnection::forStore($store)->whereKey($connection)->lockForUpdate()->firstOrFail();
            abort_unless((int) $row->version === $version, 409);
            $row->enabled = false;
            $row->save();
            $row->delete();
            StoreSheetDelivery::forStore($store)->where('connection_id', $row->id)->whereIn('status', ['pending', 'retry', 'blocked'])->update(['status' => 'cancelled', 'error_code' => 'connection_archived']);
        });

        return response()->json(['archived' => true]);
    }

    public function retry(Request $request, Store $store, int $delivery): JsonResponse
    {
        $store = $this->store($request);
        DB::transaction(function () use ($store, $delivery) {
            $row = StoreSheetDelivery::forStore($store)->whereKey($delivery)->lockForUpdate()->firstOrFail();
            $connection = StoreSheetConnection::forStore($store)->whereKey($row->connection_id)->firstOrFail();
            abort_unless($connection->enabled, 422, 'Enable this sheet connection first.');
            abort_unless(in_array($row->status, ['failed', 'cancelled'], true), 409, 'This delivery is already pending or completed.');
            $row->update(['status' => 'pending', 'attempts' => 0, 'error_code' => null, 'lease' => null, 'claimed_until' => null, 'available_at' => now()]);
        });

        return response()->json(['queued' => true]);
    }
}
