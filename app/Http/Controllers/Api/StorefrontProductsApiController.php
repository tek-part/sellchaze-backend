<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorefrontProductStoreRequest;
use App\Http\Requests\StorefrontProductUpdateRequest;
use App\Http\Resources\Storefront\StorefrontProductResource;
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
        $query = Product::query()->with('category:id,name,slug'); // StoreScope -> this store only

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('slug', 'like', $term));
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->get('category_id'));
        }

        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        $paginator = $query->orderBy('position')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => StorefrontProductResource::collection($paginator->getCollection()),
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
        $product = $this->service->create($request->validated(), $request->file('image'));

        return response()->json(['data' => new StorefrontProductResource($product->load('category:id,name,slug'))], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, Store $store, int $product): JsonResponse
    {
        $model = $this->find($product);

        return response()->json(['data' => new StorefrontProductResource($model->load(['category:id,name,slug', 'variants']))], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(StorefrontProductUpdateRequest $request, Store $store, int $product): JsonResponse
    {
        $model = $this->find($product);
        $model = $this->service->update($model, $request->validated(), $request->file('image'));

        return response()->json(['data' => new StorefrontProductResource($model->fresh()->load('category:id,name,slug'))], 200, [], JSON_UNESCAPED_UNICODE);
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
