<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorefrontCategoryStoreRequest;
use App\Http\Requests\StorefrontCategoryUpdateRequest;
use App\Http\Resources\StoreCatalogCategoryResource;
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
        $filters = $request->validate([
            'sort_by' => ['sometimes', 'in:name,position,show_in_header,products_count,created_at'], 'sort_direction' => ['sometimes', 'in:asc,desc'],
            'visibility' => ['nullable', 'in:visible,hidden'], 'in_header' => ['nullable', 'in:yes,no'],
            'level' => ['nullable', 'in:top,sub'], 'products' => ['nullable', 'in:with,without'],
        ]);
        $query = Category::query()->withCatalogProductCount()->withCount('children'); // StoreScope -> this store only

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('slug', 'like', $term));
        }

        if (isset($filters['visibility'])) {
            $query->where('is_hidden', $filters['visibility'] === 'hidden');
        }
        if (isset($filters['in_header'])) {
            $query->where('show_in_header', $filters['in_header'] === 'yes');
        }
        if (isset($filters['level'])) {
            $filters['level'] === 'top' ? $query->whereNull('parent_id') : $query->whereNotNull('parent_id');
        }
        if (isset($filters['products'])) {
            // products_count is a select subquery: filter it in HAVING-free form by wrapping the query.
            $query = Category::query()->fromSub($query, 'categories')->where('products_count', $filters['products'] === 'with' ? '>' : '=', 0);
        }

        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        $paginator = $query->orderBy($filters['sort_by'] ?? 'position', $filters['sort_direction'] ?? 'asc')->orderBy('id')->paginate($perPage);

        return response()->json([
            'data' => StoreCatalogCategoryResource::collection($paginator->getCollection()),
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

        return response()->json(['data' => new StoreCatalogCategoryResource($category)], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, Store $store, int $category): JsonResponse
    {
        return response()->json(['data' => new StoreCatalogCategoryResource($this->find($category))], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(StorefrontCategoryUpdateRequest $request, Store $store, int $category): JsonResponse
    {
        $model = $this->service->update($this->find($category), $request->validated(), $request->file('image'));

        return response()->json(['data' => new StoreCatalogCategoryResource($model->fresh())], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(Request $request, Store $store, int $category): JsonResponse
    {
        $this->service->delete($this->find($category));

        return response()->json(['message' => 'Deleted.'], 200);
    }

    public function bulkDestroy(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate([
            'categories' => ['required', 'array', 'min:1', 'max:100'],
            'categories.*' => ['required', 'array:id,version'],
            'categories.*.id' => ['required', 'integer', 'distinct'],
            'categories.*.version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);

        return response()->json(['meta' => ['deleted' => $this->service->deleteMany($data['categories'])]]);
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
