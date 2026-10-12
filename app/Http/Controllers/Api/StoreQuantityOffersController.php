<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Services\Commerce\QuantityOffers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreQuantityOffersController extends Controller
{
    public function show(Store $store, int $product): JsonResponse
    {
        return $this->response(Product::withoutGlobalScopes()->where('store_id', $store->id)->findOrFail($product));
    }

    public function update(Request $request, Store $store, int $product, QuantityOffers $offers): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483646'],
            'offers' => ['present', 'array', 'max:10'], 'offers.*' => ['required', 'array:id,quantity,unit_price,enabled,free_shipping,title,subtitle'],
            'offers.*.id' => ['required', 'uuid', 'distinct:ignore_case'], 'offers.*.quantity' => ['required', 'integer', 'between:1,999', 'distinct'],
            'offers.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'offers.*.enabled' => ['sometimes', 'boolean'], 'offers.*.free_shipping' => ['sometimes', 'boolean'],
            'offers.*.title' => ['sometimes', 'array:ar,en'], 'offers.*.title.ar' => ['nullable', 'string', 'max:160'], 'offers.*.title.en' => ['nullable', 'string', 'max:160'],
            'offers.*.subtitle' => ['sometimes', 'array:ar,en'], 'offers.*.subtitle.ar' => ['nullable', 'string', 'max:300'], 'offers.*.subtitle.en' => ['nullable', 'string', 'max:300']]);
        $row = DB::transaction(function () use ($data, $store, $product, $offers): Product {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $row = Product::withoutGlobalScopes()->where('store_id', $store->id)->lockForUpdate()->findOrFail($product);
            abort_unless($row->quantity_offers_version === (int) $data['version'], 409, 'Quantity offers changed. Reload before saving.');
            $canonical = $offers->canonical($data['offers']);
            foreach ($canonical as $index => $offer) {
                if (bccomp($offer['unit_price'], (string) $row->price, 2) > 0) {
                    throw ValidationException::withMessages(['offers.'.$index.'.unit_price' => 'An offer price cannot exceed the current product price.']);
                }
            }
            $row->update(['quantity_offers' => $canonical ?: null, 'quantity_offers_version' => $row->quantity_offers_version + 1]);

            return $row;
        });

        return $this->response($row);
    }

    private function response(Product $product): JsonResponse
    {
        return response()->json(['data' => $product->quantity_offers ?? [], 'version' => $product->quantity_offers_version,
            'product_price' => $product->price], 200, ['Cache-Control' => 'private, no-store']);
    }
}
