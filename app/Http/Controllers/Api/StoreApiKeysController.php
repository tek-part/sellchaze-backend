<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreApiKeysController extends Controller
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404);
        $user = $request->user();
        abort_unless($user && ((int) $user->id === (int) $store->owner_user_id || $user->can('stores-edit')), 403);

        return $store;
    }

    private function input(Request $request, bool $update = false): array
    {
        $data = $request->validate(['description' => ['nullable', 'string', 'max:200'], 'permissions' => ['present', 'array', 'max:12'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(StoreApiKey::PERMISSIONS)], 'enabled' => ['required', 'boolean'],
            'request_key' => [$update ? 'sometimes' : 'required', 'uuid'], 'version' => [$update ? 'required' : 'sometimes', 'integer', 'min:1']]);
        sort($data['permissions']);

        return $data;
    }

    private function row(Store $store, string $key): StoreApiKey
    {
        return StoreApiKey::where('store_id', $store->id)->where('user_id', $store->owner_user_id)->findOrFail($key);
    }

    public function index(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $rows = StoreApiKey::where('store_id', $store->id)->where('user_id', $store->owner_user_id)->latest('created_at')->paginate(20);

        return response()->json(['data' => $rows->items(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
    }

    private function lockStore(Store $store): void
    {
        $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
        abort_unless((int) $locked->owner_user_id === (int) $store->owner_user_id, 409, 'Store ownership changed.');
    }

    public function create(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $data = $this->input($request);
        $row = DB::transaction(function () use ($store, $data) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->owner_user_id === (int) $store->owner_user_id, 409, 'Store ownership changed.');
            $existing = StoreApiKey::withTrashed()->where('store_id', $store->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless(! $existing->trashed() && (int) $existing->user_id === (int) $store->owner_user_id && $existing->version === 1
                    && ($existing->description ?? '') === ($data['description'] ?? '') && $existing->permissions === $data['permissions'] && $existing->enabled === (bool) $data['enabled'], 409, 'Creation request changed.');

                return $existing;
            }
            abort_if(StoreApiKey::where('store_id', $store->id)->where('user_id', $store->owner_user_id)->count() >= 50, 422, 'A store may configure up to 50 API keys.');
            $token = 'sc_'.Str::random(64);

            return StoreApiKey::create(['store_id' => $store->id, 'user_id' => $store->owner_user_id, 'request_key' => $data['request_key'], 'token_hash' => hash('sha256', $token), 'token_prefix' => substr($token, 0, 11),
                'creation_receipt' => $token, 'receipt_expires_at' => now()->addMinutes(10), 'description' => $data['description'] ?? null, 'permissions' => $data['permissions'], 'enabled' => $data['enabled']]);
        });

        return response()->json(['data' => $row, 'secret' => $row->receipt_expires_at?->isFuture() ? $row->creation_receipt : null], 201);
    }

    public function show(Request $request, Store $store, string $key): JsonResponse
    {
        return response()->json(['data' => $this->row($this->store($request), $key)]);
    }

    public function update(Request $request, Store $store, string $key): JsonResponse
    {
        $store = $this->store($request);
        $data = $this->input($request, true);
        $row = DB::transaction(function () use ($store, $key, $data) {
            $this->lockStore($store);
            $row = $this->row($store, $key);
            $row = StoreApiKey::whereKey($row->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->version === (int) $data['version'], 409, 'API key changed. Reload before saving.');
            $row->update(['description' => $data['description'] ?? null, 'permissions' => $data['permissions'], 'enabled' => $data['enabled'], 'version' => $row->version + 1, 'creation_receipt' => null, 'receipt_expires_at' => null]);

            return $row;
        });

        return response()->json(['data' => $row]);
    }

    public function destroy(Request $request, Store $store, string $key): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($store, $key, $data) {
            $this->lockStore($store);
            $row = StoreApiKey::where('store_id', $store->id)->where('user_id', $store->owner_user_id)->whereKey($key)->lockForUpdate()->firstOrFail();
            abort_unless($row->version === (int) $data['version'], 409, 'API key changed. Reload before deleting.');
            $row->update(['enabled' => false, 'version' => $row->version + 1, 'creation_receipt' => null, 'receipt_expires_at' => null]);
            $row->delete();
        });

        return response()->json(['deleted' => true]);
    }
}
