<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorefrontProductVariantRequest;
use App\Http\Resources\Storefront\StorefrontProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\StoreCatalog\StorefrontProductVariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner management of product variants, mounted under
 * /stores/{store}/catalog/products/{product}/variants with store.scope.
 * The product is resolved under StoreScope + ownership policy; the variant is
 * resolved via the product relation, so it can only ever belong to that product
 * (and thus the current store) — double tenant protection.
 */
class StorefrontProductVariantsApiController extends Controller
{
    public function __construct(private readonly StorefrontProductVariantService $service) {}

    public function index(Request $request, Store $store, int $product): JsonResponse
    {
        $model = $this->product($product);
        $variants = $model->variants()->get()->each->setRelation('product', $model);

        return response()->json(['data' => StorefrontProductVariantResource::collection($variants)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(StorefrontProductVariantRequest $request, Store $store, int $product): JsonResponse
    {
        $model = $this->product($product);
        $variant = $this->service->create($model, $request->validated())->setRelation('product', $model);

        return response()->json(['data' => new StorefrontProductVariantResource($variant)], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(StorefrontProductVariantRequest $request, Store $store, int $product, int $variant): JsonResponse
    {
        $model = $this->product($product);
        $updated = $this->service->update($this->variant($model, $variant), $request->validated())->setRelation('product', $model);

        return response()->json(['data' => new StorefrontProductVariantResource($updated)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(Request $request, Store $store, int $product, int $variant): JsonResponse
    {
        $model = $this->product($product);
        $this->service->delete($this->variant($model, $variant));

        return response()->json(['message' => 'Deleted.'], 200);
    }

    /** Scoped product fetch (StoreScope => 404 cross-store) + ownership policy. */
    private function product(int $id): Product
    {
        $model = Product::query()->findOrFail($id);
        if (! request()->user()->can('update', $model)) {
            abort(403, 'Forbidden.');
        }

        return $model;
    }

    /** Variant must belong to this product (=> current store), else 404. */
    private function variant(Product $product, int $id): ProductVariant
    {
        return $product->variants()->findOrFail($id);
    }
}
