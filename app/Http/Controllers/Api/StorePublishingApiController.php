<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\Outbox\OutboxRecorder;
use App\Services\Stores\StorePublishingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Store-scoped publishing surface shared by the owner (/my-store/*) and the
 * admin (/stores/{store}/*) prefixes. Owners change a store's status only
 * through publish/unpublish (UpdateStoreRequest strips `status` for them),
 * so the readiness gate in StorePublishingService is always enforced.
 */
class StorePublishingApiController extends Controller
{
    public function readiness(Request $request, Store $store, StorePublishingService $publishing): JsonResponse
    {
        $store = $this->resolveStore($request, $store);

        return response()->json(['data' => $publishing->readiness($store)]);
    }

    public function publish(Request $request, Store $store, StorePublishingService $publishing, OutboxRecorder $outbox): JsonResponse
    {
        $store = $this->resolveStore($request, $store);

        $published = DB::transaction(function () use ($store, $publishing, $outbox) {
            $published = $publishing->publish($store);
            $outbox->record('StorePublished', 'store', (string) $store->id, $this->payload($store));

            return $published;
        });

        return response()->json(['data' => new StoreResource($published)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function unpublish(Request $request, Store $store, StorePublishingService $publishing, OutboxRecorder $outbox): JsonResponse
    {
        $store = $this->resolveStore($request, $store);

        $draft = DB::transaction(function () use ($store, $publishing, $outbox) {
            $draft = $publishing->unpublish($store);
            $outbox->record('StoreUnpublished', 'store', (string) $store->id, $this->payload($store));

            return $draft;
        });

        return response()->json(['data' => new StoreResource($draft)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** @return array{store_id: int, organization_id: int|null} */
    private function payload(Store $store): array
    {
        return [
            'store_id' => (int) $store->id,
            'organization_id' => $store->organization_id !== null ? (int) $store->organization_id : null,
        ];
    }

    /**
     * The typed `Store $store` parameter is what triggers implicit binding on
     * /stores/{store}; on /my-store the middleware injects the same binding.
     * The request attribute (set by both scope middlewares) wins when present.
     */
    private function resolveStore(Request $request, ?Store $bound): Store
    {
        $store = $request->attributes->get('store') ?? $bound ?? $request->route('store');
        abort_unless($store instanceof Store, 404, 'Store not found.');
        abort_unless($request->user()?->can('update', $store), 403, 'Forbidden.');

        return $store;
    }
}
