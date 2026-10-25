<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCollectionRequest;
use App\Http\Resources\StoreCatalogCollectionResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreBrand;
use App\Models\StoreCollection;
use App\Services\StoreCatalog\StoreCollectionProducts;
use App\Services\StoreCatalog\StoreCollectionService;
use App\Support\Catalog\CollectionRules;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Merchant management of a store's collections (manual + automated). Mounted under
 * /my-store/catalog/collections and /stores/{store}/catalog/collections; StoreScope keeps every
 * query inside the current store, so another store's collection is simply not found.
 */
class StoreCollectionsApiController extends Controller
{
    private const PREVIEW_LIMIT = 50;

    public function __construct(
        private readonly StoreCollectionService $service,
        private readonly StoreCollectionProducts $products,
    ) {}

    public function index(Request $request, Store $store): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'in:manual,automated'],
            'published' => ['nullable', 'in:yes,no'],
            'sort_by' => ['sometimes', 'in:title,position,created_at,updated_at'],
            'sort_direction' => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = StoreCollection::query()->withCount('products');
        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($filters['search'])).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('slug', 'like', $term));
        }
        if (isset($filters['type'])) {
            $query->where('is_automated', $filters['type'] === 'automated');
        }
        if (isset($filters['published'])) {
            $query->where('is_active', $filters['published'] === 'yes');
        }
        $column = ['title' => 'name'][$filters['sort_by'] ?? 'position'] ?? ($filters['sort_by'] ?? 'position');
        $paginator = $query->orderBy($column, $filters['sort_direction'] ?? 'asc')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 15));
        // Rule-driven collections count by query (bounded by the page size).
        $paginator->getCollection()->each(function (StoreCollection $collection) {
            if ($collection->usesRules()) {
                $collection->setAttribute('products_count', $this->products->count($collection));
            }
        });

        return response()->json([
            'data' => StoreCatalogCollectionResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(StoreCollectionRequest $request, Store $store): JsonResponse
    {
        $collection = $this->service->create($request->validated(), $request->file('image'));

        return $this->respond($collection, 201);
    }

    public function show(Request $request, Store $store, int $collection): JsonResponse
    {
        return $this->respond($this->find($collection));
    }

    public function update(StoreCollectionRequest $request, Store $store, int $collection): JsonResponse
    {
        $model = $this->service->update($this->find($collection), $request->validated(), $request->file('image'));

        return $this->respond($model);
    }

    public function destroy(Request $request, Store $store, int $collection): JsonResponse
    {
        $this->service->deleteMany([$this->find($collection)->id]);

        return response()->json(['message' => 'Deleted.']);
    }

    /** POST {collection}/products — replace a manual collection's products in the given order. */
    public function products(Request $request, Store $store, int $collection): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['present', 'array', 'max:500'],
            'product_ids.*' => ['integer', 'distinct'],
        ]);
        $model = $this->find($collection);
        $this->service->syncProducts($model, $data['product_ids']);

        return $this->respond($model->refresh());
    }

    /** POST preview — products matching unsaved rules, for the editor's live preview. */
    public function preview(Request $request, Store $store): JsonResponse
    {
        CollectionRules::decodeRequest($request);
        $rules = CollectionRules::validationRules(true) + ['sort_order' => ['sometimes', 'in:'.implode(',', StoreCollection::SORT_ORDERS)]];
        $validator = validator($request->all(), $rules, ['rules.required' => 'Add at least one condition to preview matching products.']);
        $validator->after(fn ($v) => CollectionRules::validateRows($v, $request->input('rules')));
        $data = $validator->validate();

        $query = $this->products->preview((int) app(CurrentStore::class)->id(), $data['rules'], $data['match'] ?? 'all', $data['sort_order'] ?? 'best_selling');
        $total = (clone $query)->reorder()->count('products.id');

        return response()->json([
            'data' => $this->summaries($query->limit(self::PREVIEW_LIMIT)->get()),
            'meta' => ['total' => $total, 'limit' => self::PREVIEW_LIMIT],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** GET rule-options — the choices the rule builder offers (categories, brands, tags). */
    public function ruleOptions(Request $request, Store $store): JsonResponse
    {
        $tags = Product::query()->whereNotNull('products.tags')->orderByDesc('products.id')->limit(1000)->pluck('products.tags')
            ->flatMap(fn ($tags) => is_array($tags) ? $tags : [])
            ->filter(fn ($tag) => is_string($tag) && trim($tag) !== '')
            ->map(fn (string $tag) => trim($tag))->unique()->sort()->values()->take(200);

        return response()->json(['data' => [
            'fields' => CollectionRules::FIELDS,
            'sort_orders' => StoreCollection::SORT_ORDERS,
            'categories' => Category::query()->orderBy('name')->limit(500)->get(['id', 'name', 'parent_id'])->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name, 'parent_id' => $c->parent_id])->values(),
            'brands' => StoreBrand::query()->orderBy('name')->limit(500)->get(['id', 'name'])->map(fn (StoreBrand $b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'tags' => $tags,
        ]], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function bulkUpdate(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
            'published' => ['required', 'boolean'],
        ]);
        $ids = array_map('intval', $data['ids']);
        if (StoreCollection::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['ids' => 'A selected collection is unavailable. Refresh the list.']);
        }

        return response()->json(['meta' => ['updated' => $this->service->setPublished($ids, (bool) $data['published'])]]);
    }

    public function bulkDestroy(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        return response()->json(['meta' => ['deleted' => $this->service->deleteMany(array_map('intval', $data['ids']))]]);
    }

    private function respond(StoreCollection $collection, int $status = 200): JsonResponse
    {
        if ($collection->usesRules()) {
            $collection->setAttribute('products_count', $this->products->count($collection));
            $collection->setRelation('editorProducts', []);
        } else {
            $rows = $this->products->query($collection)->limit(500)->get();
            $collection->setAttribute('products_count', $rows->count());
            $collection->setRelation('editorProducts', $this->summaries($rows));
        }

        return response()->json(['data' => new StoreCatalogCollectionResource($collection)], $status, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  Collection<int, Product>  $rows
     * @return list<array<string, mixed>>
     */
    private function summaries($rows): array
    {
        return $rows->map(fn (Product $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'sku' => $p->sku,
            'price' => $p->price,
            'image_url' => $p->imageUrl(),
            'is_active' => (bool) $p->is_active,
            'stock_quantity' => (int) $p->stock_quantity,
        ])->values()->all();
    }

    private function find(int $id): StoreCollection
    {
        return StoreCollection::query()->findOrFail($id);
    }
}
