<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Services\Commerce\ProductPreferences;
use App\Services\StoreCatalog\ProductLandingPages;
use App\Services\StoreCatalog\StorefrontProductService;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontService;
use App\Support\ProductDescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreProductLandingController extends Controller
{
    public function upload(Request $request, Store $store, int $product): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'extensions:jpg,jpeg,png,gif,webp', 'max:10240']]);
        $media = DB::transaction(function () use ($request, $store, $product) {
            $row = Product::withoutGlobalScopes()->where('store_id', $store->id)->lockForUpdate()->findOrFail($product);
            app(StorefrontProductService::class)->update($row, [], null, [$request->file('file')]);

            return $row->media()->latest('id')->firstOrFail();
        });

        return response()->json(['data' => ['id' => $media->id, 'url' => $media->url(), 'type' => 'image', 'alt' => $media->alt, 'mime' => $media->mime, 'position' => $media->position]], 201, ['Cache-Control' => 'private, no-store']);
    }

    public function show(Store $store, int $product): JsonResponse
    {
        return $this->response(Product::withoutGlobalScopes()->where('store_id', $store->id)->findOrFail($product));
    }

    public function update(Request $request, Store $store, int $product): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'document' => ['required', 'array']]);
        $row = DB::transaction(function () use ($data, $store, $product): Product {
            $row = Product::withoutGlobalScopes()->where('store_id', $store->id)->lockForUpdate()->findOrFail($product);
            ProductLandingPages::save($row, $data['document'], $data['version']);
            DB::afterCommit(function () use ($store) {
                StorefrontService::forgetHomepage($store->id);
                app(StorefrontPageCache::class)->flushStore($store->id);
            });

            return $row;
        });

        return $this->response($row);
    }

    private function response(Product $product): JsonResponse
    {
        return response()->json(['data' => ProductLandingPages::document($product), 'version' => ProductLandingPages::version($product), 'product_id' => $product->id,
            'product' => ['id' => $product->id, 'name' => $product->name, 'slug' => $product->slug, 'price' => $product->price,
                'description' => ProductDescription::clean($product->long_description ?? $product->description ?? ''), 'product_preferences' => ProductPreferences::publicPayload($product),
                'media' => $product->media()->orderBy('position')->get()->map(fn ($media) => ['id' => $media->id, 'url' => $media->url(), 'type' => $media->type === 'video' ? 'video' : 'image', 'alt' => $media->alt])]], 200, ['Cache-Control' => 'private, no-store']);
    }
}
