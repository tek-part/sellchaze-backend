<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreInventoryMovement;
use App\Services\Commerce\StoreInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreInventoryController extends Controller
{
    public function index(Request $request, Store $store): JsonResponse
    {
        $input = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1']]);
        $products = Product::query()->where('store_id', $store->id)->with('variants')
            ->when(! empty($input['search']), fn ($query) => $query->where('name', 'like', '%'.$input['search'].'%'))
            ->orderBy('id')->paginate(20);

        return response()->json(['data' => $products->getCollection()->map(function (Product $product) {
            return ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'track_inventory' => $product->track_inventory,
                'stock_quantity' => $product->stock_quantity, 'reserved_quantity' => $product->reserved_quantity,
                'variants' => $product->variants->map(fn ($variant) => $variant->only(['id', 'name', 'sku', 'track_inventory', 'stock_quantity', 'reserved_quantity']))];
        }), 'meta' => ['current_page' => $products->currentPage(), 'last_page' => $products->lastPage(), 'total' => $products->total()]]);
    }

    public function update(Request $request, Store $store, int $product, StoreInventory $inventory): JsonResponse
    {
        $input = $request->validate(['variant_id' => ['nullable', 'integer'], 'track_inventory' => ['required', 'boolean'],
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:100000000'],
            'expected_stock' => ['required', 'integer'], 'expected_reserved' => ['required', 'integer'], 'expected_tracking' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500']]);
        // Laravel accepts "0"/"1" for booleans and form-encoded integers.
        foreach (['stock_quantity', 'expected_stock', 'expected_reserved'] as $field) {
            $input[$field] = (int) $input[$field];
        }
        foreach (['track_inventory', 'expected_tracking'] as $field) {
            $input[$field] = (bool) $input[$field];
        }
        DB::transaction(function () use ($request, $store, $product, $input, $inventory) {
            $model = Product::query()->where('store_id', $store->id)->whereKey($product)->lockForUpdate()->firstOrFail();
            abort_unless($request->user()->can('update', $model), 403);
            if (! empty($input['variant_id'])) {
                $stock = $model->variants()->where('store_id', $store->id)->whereKey($input['variant_id'])->lockForUpdate()->firstOrFail();
            } else {
                abort_if($model->variants()->exists(), 422, 'Set inventory for each product option.');
                $stock = $model;
            }
            $inventory->adjust($stock, $input, (int) $request->user()->id);
        });

        return response()->json(['message' => 'Inventory updated.']);
    }

    public function history(Request $request, Store $store, int $product): JsonResponse
    {
        Product::query()->where('store_id', $store->id)->findOrFail($product);

        return response()->json(['data' => StoreInventoryMovement::where('store_id', $store->id)->where('product_id', $product)->latest('id')->limit(40)->get()]);
    }
}
