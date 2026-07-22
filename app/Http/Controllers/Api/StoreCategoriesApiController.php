<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorefrontCategoryStoreRequest;
use App\Http\Requests\StorefrontCategoryUpdateRequest;
use App\Http\Resources\Storefront\StorefrontCategoryResource;
use App\Models\Category;
use App\Models\Store;
use App\Services\StoreCatalog\StorefrontCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner management of a store's categories. Mounted under
 * /stores/{store}/catalog/categories with the store.scope middleware.
 */
class StoreCategoriesApiController extends Controller
{
    public function __construct(private readonly StorefrontCategoryService $service) {}

    public function index(Request $request, Store $store): JsonResponse
    {
        $query = Category::query()->withCount('products'); // StoreScope -> this store only

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('slug', 'like', $term));
        }

        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        $paginator = $query->orderBy('position')->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => StorefrontCategoryResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(StorefrontCategoryStoreRequest $request, Store $store): JsonResponse
    {
        $category = $this->service->create($request->validated(), $request->file('image'));

        return response()->json(['data' => new StorefrontCategoryResource($category)], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, Store $store, int $category): JsonResponse
    {
        return response()->json(['data' => new StorefrontCategoryResource($this->find($category))], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(StorefrontCategoryUpdateRequest $request, Store $store, int $category): JsonResponse
    {
        $model = $this->service->update($this->find($category), $request->validated(), $request->file('image'));

        return response()->json(['data' => new StorefrontCategoryResource($model->fresh())], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(Request $request, Store $store, int $category): JsonResponse
    {
        $this->service->delete($this->find($category));

        return response()->json(['message' => 'Deleted.'], 200);
    }

    private function find(int $id): Category
    {
        $model = Category::query()->findOrFail($id);
        if (! request()->user()->can('update', $model)) {
            abort(403, 'Forbidden.');
        }

        return $model;
    }
}
