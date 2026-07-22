<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStoreRequest;
use App\Http\Requests\UpdateStoreRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\Rbac\UserScope;
use App\Services\StoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoresApiController extends Controller
{
    public function __construct(private readonly StoreService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Store::query();

        // Ownership scoping: non-admins only ever see their own tenant's stores.
        if (! UserScope::isAdmin($request->user())) {
            $query->where('owner_user_id', UserScope::effectiveMerchantUserId($request->user()));
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('slug', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('owner_type')) {
            $query->where('owner_type', $request->string('owner_type'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        $paginator = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => StoreResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * The authenticated owner's single store (single-store model). The store is
     * resolved and injected by the store.own middleware (auto-provisioned if
     * missing), so this simply serialises the current tenant.
     */
    public function current(Request $request): JsonResponse
    {
        $store = $request->attributes->get('store');

        return response()->json(['data' => new StoreResource($store)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, Store $store): JsonResponse
    {
        // 404 (not 403) so we never leak the existence of another tenant's store.
        if (! $request->user()->can('view', $store)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(['data' => new StoreResource($store)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(StoreStoreRequest $request): JsonResponse
    {
        if (! $request->user()->can('create', Store::class)) {
            abort(403, 'Forbidden.');
        }

        $store = $this->service->create(
            $request->user(),
            $request->validated(),
            $request->file('logo'),
            $request->file('banner'),
        );

        return response()->json(['data' => new StoreResource($store)], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        if (! $request->user()->can('update', $store)) {
            abort(403, 'Forbidden.');
        }

        $store = $this->service->update(
            $store,
            $request->validated(),
            $request->file('logo'),
            $request->file('banner'),
        );

        return response()->json(['data' => new StoreResource($store->fresh())], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(Request $request, Store $store): JsonResponse
    {
        if (! $request->user()->can('delete', $store)) {
            abort(403, 'Forbidden.');
        }

        $store->delete();

        return response()->json(['message' => 'Deleted.'], 200);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = 0;
        foreach ($validated['ids'] as $id) {
            $store = Store::query()->find($id);
            if (! $store || ! $request->user()->can('delete', $store)) {
                continue;
            }
            $store->delete();
            $deleted++;
        }

        return response()->json(['message' => 'OK', 'deleted' => $deleted], 200);
    }
}
