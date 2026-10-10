<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorefrontProductStoreRequest;
use App\Http\Requests\StorefrontProductUpdateRequest;
use App\Http\Resources\StoreCatalogProductResource;
use App\Models\Product;
use App\Models\Store;
use App\Services\StoreCatalog\StorefrontProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner management of a store's products. Mounted under
 * /stores/{store}/catalog/products with the store.scope middleware, so
 * StoreScope isolates every query to the authorized store.
 */
class StorefrontProductsApiController extends Controller
{
    public function __construct(private readonly StorefrontProductService $service) {}

    public function index(Request $request, Store $store): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:active,draft']]);
        $query = Product::query()->with(['category:id,name,slug', 'variants', 'media']); // StoreScope -> this store only

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('slug', 'like', $term));
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->get('category_id'));
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->get('status') === 'active');
        }

        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        $paginator = $query->orderBy('position')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => StoreCatalogProductResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(StorefrontProductStoreRequest $request, Store $store): JsonResponse
    {
        $product = $this->service->create($request->validated(), $request->file('image'), $request->file('gallery', []));

        return response()->json(['data' => new StoreCatalogProductResource($product->load(['category:id,name,slug', 'variants', 'media']))], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, Store $store, int $product): JsonResponse
    {
        $model = $this->find($product);

        return response()->json(['data' => new StoreCatalogProductResource($model->load(['category:id,name,slug', 'variants', 'media']))], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(StorefrontProductUpdateRequest $request, Store $store, int $product): JsonResponse
    {
        $model = $this->find($product);
        $model = $this->service->update($model, $request->validated(), $request->file('image'), $request->file('gallery', []));

        return response()->json(['data' => new StoreCatalogProductResource($model->fresh()->load(['category:id,name,slug', 'variants', 'media']))], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(Request $request, Store $store, int $product): JsonResponse
    {
        $model = $this->find($product);
        $this->service->delete($model);

        return response()->json(['message' => 'Deleted.'], 200);
    }

    /** Scoped fetch (StoreScope => 404 if the id belongs to another store) + policy defense-in-depth. */
    private function find(int $id): Product
    {
        $model = Product::query()->findOrFail($id);
        if (! request()->user()->can('update', $model)) {
            abort(403, 'Forbidden.');
        }

        return $model;
    }
}
