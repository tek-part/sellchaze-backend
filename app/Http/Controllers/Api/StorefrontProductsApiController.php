<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorefrontProductBulkUpdateRequest;
use App\Http\Requests\StorefrontProductStoreRequest;
use App\Http\Requests\StorefrontProductUpdateRequest;
use App\Http\Resources\StoreCatalogProductResource;
use App\Models\Product;
use App\Models\Store;
use App\Services\StoreCatalog\StorefrontProductService;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontService;
use App\Support\CatalogProductVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owner management of a store's products. Mounted under
 * /stores/{store}/catalog/products with the store.scope middleware, so
 * StoreScope isolates every query to the authorized store.
 */
class StorefrontProductsApiController extends Controller
{
    public function __construct(private readonly StorefrontProductService $service) {}

    private const DIGITAL_TYPES = ['codes', 'link'];

    /**
     * Shopify-style inventory filter. A product with variants is judged by its variants; "available"
     * is on-hand minus reserved, and "low" is at or under the reorder level (5 when none is set).
     */
    private static function applyStockFilter(Builder $query, string $stock): void
    {
        $available = '(stock_quantity - reserved_quantity)';
        $sellable = fn ($q) => $q->where('track_inventory', false)->orWhereRaw($available.' > 0');
        $variants = fn ($q) => $q->where('is_active', true);
        match ($stock) {
            'untracked' => $query->where('track_inventory', false)->whereDoesntHave('variants', fn ($v) => $variants($v)->where('track_inventory', true)),
            'in_stock' => $query->where(fn ($q) => $q
                ->where(fn ($simple) => $simple->whereDoesntHave('variants', $variants)->where($sellable))
                ->orWhereHas('variants', fn ($v) => $variants($v)->where($sellable))),
            'out_of_stock' => $query->where(fn ($q) => $q
                ->where(fn ($simple) => $simple->whereDoesntHave('variants', $variants)->where('track_inventory', true)->whereRaw($available.' <= 0'))
                ->orWhere(fn ($withVariants) => $withVariants->whereHas('variants', $variants)->whereDoesntHave('variants', fn ($v) => $variants($v)->where($sellable)))),
            'low' => $query->where(fn ($q) => $q
                ->where(fn ($simple) => $simple->whereDoesntHave('variants', $variants)->where('track_inventory', true)
                    ->whereRaw($available.' > 0')->whereRaw($available.' <= COALESCE(NULLIF(reorder_level, 0), 5)'))
                ->orWhereHas('variants', fn ($v) => $variants($v)->where('track_inventory', true)->whereRaw($available.' > 0')->whereRaw($available.' <= 5'))),
            default => null,
        };
    }

    public function index(Request $request, Store $store): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'in:active,draft'],
            'search' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:120'],
            'sort_by' => ['nullable', 'in:position,name,price,created_at,updated_at,stock_quantity'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'stock' => ['nullable', 'in:in_stock,out_of_stock,low,untracked'],
            'type' => ['nullable', 'in:physical,digital'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
        ]);
        $query = Product::query()->with(['category:id,name,slug', 'additionalCategories', 'variants', 'media']); // StoreScope -> this store only

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('slug', 'like', $term)->orWhere('sku', 'like', $term));
        }
        if ($request->filled('sku')) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $request->string('sku')->trim()).'%';
            $query->where(fn ($q) => $q->whereRaw("sku LIKE ? ESCAPE '!'", [$term])
                ->orWhereHas('variants', fn ($variants) => $variants->whereRaw("sku LIKE ? ESCAPE '!'", [$term])));
        }
        if ($request->filled('category_id')) {
            $query->inCategories([(int) $request->get('category_id')]);
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->get('status') === 'active');
        }
        if ($request->filled('type')) {
            $request->get('type') === 'digital'
                ? $query->whereIn('digital_type', self::DIGITAL_TYPES)
                : $query->where(fn ($q) => $q->whereNull('digital_type')->orWhereNotIn('digital_type', self::DIGITAL_TYPES));
        }
        if ($request->filled('price_min')) {
            $query->where('price', '>=', (float) $request->input('price_min'));
        }
        if ($request->filled('price_max')) {
            $query->where('price', '<=', (float) $request->input('price_max'));
        }
        if ($request->filled('created_from')) {
            $query->where('created_at', '>=', Carbon::parse($request->input('created_from'))->startOfDay());
        }
        if ($request->filled('created_to')) {
            $query->where('created_at', '<=', Carbon::parse($request->input('created_to'))->endOfDay());
        }
        if ($request->filled('stock')) {
            self::applyStockFilter($query, (string) $request->input('stock'));
        }

        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        $sort = $request->input('sort_by') ?: 'position';
        $direction = $request->input('sort_direction') ?: 'asc';
        if (! $request->filled('sort_by')) {
            $query->orderByDesc('display_priority');
        }
        $paginator = $query->orderBy($sort, $direction)->orderByDesc('id')->paginate($perPage);

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

    public function bulkUpdate(StorefrontProductBulkUpdateRequest $request, Store $store): JsonResponse
    {
        $data = $request->validated();
        $updated = DB::transaction(function () use ($data, $request, $store) {
            $targets = collect($data['products'])->keyBy('id');
            $products = Product::query()->whereIn('id', $targets->keys())->orderBy('id')->lockForUpdate()->get();
            if ($products->count() !== $targets->count()) {
                throw ValidationException::withMessages(['products' => 'Refresh the list: a selected product is unavailable.']);
            }
            foreach ($products as $product) {
                abort_unless($request->user()->can('update', $product), 403);
                if (! hash_equals(CatalogProductVersion::for($product), $targets[$product->id]['version'])) {
                    throw ValidationException::withMessages(['products' => 'A selected product has changed. Refresh the list and review your selection before saving again.']);
                }
            }
            foreach ($products as $product) {
                $product->fill($data['changes'])->save();
                if (array_key_exists('category_id', $data['changes'])) {
                    $product->additionalCategories()->sync($product->category_id ? [$product->category_id => ['store_id' => $store->id]] : []);
                }
            }
            DB::afterCommit(function () use ($store) {
                StorefrontService::forgetHomepage((int) $store->id);
                app(StorefrontPageCache::class)->flushStore((int) $store->id);
            });

            return $products->count();
        });

        return response()->json(['meta' => ['updated' => $updated]], 200);
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
