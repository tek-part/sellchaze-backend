<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreOrderWebhook;
use App\Models\StoreOrderWebhookDelivery;
use App\Services\Commerce\StoreOrderWebhooks;
use App\Services\Commerce\WebhookDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreOrderWebhookController extends Controller
{
    public function __construct(private readonly StoreOrderWebhooks $webhooks, private readonly WebhookDestination $destination) {}

    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store, 404);
        abort_unless($user && ((int) $user->id === (int) $store->owner_user_id || $user->can('stores-edit')), 403);

        return $store;
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'private, no-store']);
    }

    private function row(Store $store, string $webhook): StoreOrderWebhook
    {
        return StoreOrderWebhook::where('store_id', $store->id)->where('user_id', $store->owner_user_id)->findOrFail($webhook);
    }

    private function data(Request $request, bool $update = false): array
    {
        return $request->validate(['url' => ['required', 'string', 'max:2048', function ($attribute, $value, $fail) {
            if (! $this->destination->validUrl($value)) {
                $fail('Use a public HTTPS URL without credentials or a fragment.');
            }
        }], 'description' => ['nullable', 'string', 'max:200'], 'type' => ['required', Rule::in(['orders', 'order-status-update'])],
            'enabled' => ['required', 'boolean'], 'version' => [$update ? 'required' : 'sometimes', 'integer', 'min:1'],
            'request_key' => [$update ? 'sometimes' : 'required', 'uuid']]);
    }

    public function index(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $rows = StoreOrderWebhook::where('store_id', $store->id)->where('user_id', $store->owner_user_id)->latest('created_at')->paginate(20);

        return $this->response(['data' => $rows->getCollection()->map(fn ($row) => $this->webhooks->present($row))->all(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
    }

    public function create(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $data = $this->data($request);
        $secret = Str::random(64);
        $row = DB::transaction(function () use ($store, $data, $secret) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->owner_user_id === (int) $store->owner_user_id, 409, 'Store ownership changed.');
            $existing = StoreOrderWebhook::withTrashed()->where('store_id', $store->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless(! $existing->trashed() && (int) $existing->user_id === (int) $store->owner_user_id && $existing->version === 1
                    && $existing->url === $data['url'] && ($existing->description ?? '') === ($data['description'] ?? '')
                    && $existing->event_type === $data['type'] && $existing->enabled === (bool) $data['enabled'], 409, 'Creation request changed.');

                return $existing;
            }
            abort_if(StoreOrderWebhook::where('store_id', $store->id)->count() >= 50, 422, 'A store may configure up to50 webhooks.');

            return StoreOrderWebhook::create(['store_id' => $store->id, 'user_id' => $store->owner_user_id, 'url' => $data['url'],
                'description' => $data['description'] ?? null, 'event_type' => $data['type'], 'enabled' => $data['enabled'], 'secret' => $secret, 'request_key' => $data['request_key']]);
        });

        return $this->response(['data' => $this->webhooks->present($row), 'secret' => $row->secret], 201);
    }

    public function show(Request $request, Store $store, string $webhook): JsonResponse
    {
        $row = $this->row($this->store($request), $webhook);
        $deliveries = StoreOrderWebhookDelivery::where('store_id', $row->store_id)->where('webhook_id', $row->id)->latest('created_at')->limit(20)->get();

        return $this->response(['data' => $this->webhooks->present($row), 'deliveries' => $deliveries->map(fn ($delivery) => $this->webhooks->deliveryMetadata($delivery))->all()]);
    }

    public function update(Request $request, Store $store, string $webhook): JsonResponse
    {
        $store = $this->store($request);
        $data = $this->data($request, true);
        $row = DB::transaction(function () use ($store, $webhook, $data) {
            $row = StoreOrderWebhook::where('store_id', $store->id)->where('user_id', $store->owner_user_id)->whereKey($webhook)->lockForUpdate()->firstOrFail();
            abort_unless($row->version === (int) $data['version'], 409, 'Webhook changed. Reload before saving.');
            $row->update(['url' => $data['url'], 'description' => $data['description'] ?? null, 'event_type' => $data['type'],
                'enabled' => $data['enabled'], 'version' => $row->version + 1]);

            return $row;
        });

        return $this->response(['data' => $this->webhooks->present($row)]);
    }

    public function destroy(Request $request, Store $store, string $webhook): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($store, $webhook, $data) {
            $row = StoreOrderWebhook::where('store_id', $store->id)->where('user_id', $store->owner_user_id)->whereKey($webhook)->lockForUpdate()->firstOrFail();
            abort_unless($row->version === (int) $data['version'], 409, 'Webhook changed. Reload before deleting.');
            $row->update(['enabled' => false, 'version' => $row->version + 1]);
            $row->delete();
            StoreOrderWebhookDelivery::where('webhook_id', $row->id)->whereIn('status', ['queued', 'retry', 'blocked'])
                ->update(['status' => 'skipped', 'payload' => null, 'updated_at' => now()]);
        });

        return $this->response(['deleted' => true]);
    }
}
