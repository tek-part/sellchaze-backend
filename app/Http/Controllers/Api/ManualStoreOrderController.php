<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantOrderResource;
use App\Models\Product;
use App\Models\ProductPersonalizationUpload;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Services\Commerce\ManualStoreOrders;
use App\Services\Commerce\ProductPersonalization;
use App\Services\PlatformWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ManualStoreOrderController extends Controller
{
    public function products(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:200'], 'sku' => ['nullable', 'string', 'max:200'], 'per_page' => ['nullable', 'integer', 'in:10,25,50,100']]);
        $rows = Product::where('store_id', $store->id)->where('is_active', true)->withCount('variants')
            ->when(isset($data['name']) && $data['name'] !== '', fn ($query) => $query->where('name', 'like', '%'.$data['name'].'%'))
            ->when(isset($data['sku']) && $data['sku'] !== '', fn ($query) => $query->where(function ($inner) use ($data, $store) {
                $inner->where('sku', 'like', '%'.$data['sku'].'%')->orWhereHas('variants', fn ($variant) => $variant->where('store_id', $store->id)->where('is_active', true)->where('sku', 'like', '%'.$data['sku'].'%'));
            }))->orderByDesc('id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $rows->getCollection()->map(fn ($product) => [
            'id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'price' => $product->price,
            'image_url' => $product->imageUrl(), 'created_at' => $product->created_at, 'variants_count' => $product->variants_count,
            'digital_type' => $product->digital_type, 'personalization_fields' => ProductPersonalization::fields($product),
        ]), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'per_page' => $rows->perPage()],
            'store' => ['currency' => $store->currency ?: 'USD', 'shipping_total' => $store->shipping_enabled ? (string) ($store->shipping_flat_rate ?: '0.00') : '0.00',
                'tax_enabled' => (bool) $store->tax_enabled, 'tax_rate' => (string) ($store->tax_rate ?: '0'), 'tax_prices_include' => (bool) $store->tax_prices_include]]);
    }

    public function variants(Request $request, Store $store, int $product): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $model = Product::where('store_id', $store->id)->where('is_active', true)->findOrFail($product);
        $rows = $model->variants()->where('store_id', $store->id)->where('is_active', true)->orderBy('position')->orderBy('id')->paginate(50);

        return response()->json(['data' => $rows->getCollection()->map(fn ($variant) => [
            'id' => $variant->id, 'name' => $variant->name, 'sku' => $variant->sku, 'options' => $variant->options,
            'price' => $variant->price_override ?? $model->price, 'image_url' => $variant->imageUrl() ?? $model->imageUrl(),
        ]), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
    }

    public function upload(Request $request, Store $store, int $product): JsonResponse
    {
        $data = $request->validate(['field_key' => ['required', 'string', 'max:80'], 'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:max_width=4096,max_height=4096']]);
        $model = Product::where('store_id', $store->id)->where('is_active', true)->findOrFail($product);
        $field = collect($model->personalization_fields ?? [])->firstWhere('key', $data['field_key']);
        abort_unless($field && $field['type'] === 'image', 422, 'This product does not accept this image field.');
        $file = $request->file('file');
        $path = $file->store('product-personalization/'.$store->id, 'local');
        abort_unless($path, 503, 'The image could not be stored.');
        $token = bin2hex(random_bytes(32));
        try {
            $upload = ProductPersonalizationUpload::create(['id' => (string) Str::uuid(), 'store_id' => $store->id, 'product_id' => $model->id,
                'field_key' => $field['key'], 'token_hash' => hash('sha256', $token), 'path' => $path,
                'filename' => mb_substr(preg_replace('/[^\pL\pN ._-]/u', '', $file->getClientOriginalName()) ?: 'image.'.$file->extension(), 0, 160),
                'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'expires_at' => now()->addDays(7)]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        return response()->json(['data' => ['token' => $token, 'filename' => $upload->filename, 'url' => $upload->downloadUrl(), 'expires_at' => $upload->expires_at]], 201);
    }

    public function store(Request $request, Store $store, ManualStoreOrders $orders): JsonResponse
    {
        $money = ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.ManualStoreOrders::MAX_MONEY];
        $data = $request->validate([
            'request_key' => ['required', 'uuid'], 'customer_name' => ['required', 'string', 'max:200'],
            'customer_phone' => ['required', 'string', 'max:50'], 'customer_email' => ['nullable', 'email', 'max:200'],
            'address' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:5000'],
            'shipping_total' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.ManualStoreOrders::MAX_MONEY],
            'grand_total' => $money, 'payment_method' => ['nullable', 'in:cod,bank_transfer'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'], 'items.*' => ['array:product_id,variant_id,quantity,unit_price,expected_price,personalization'],
            'items.*.product_id' => ['required', 'integer', 'min:1'], 'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'], 'items.*.unit_price' => $money,
            'items.*.expected_price' => $money,
        ] + ProductPersonalization::inputRules('items.*.personalization'));
        $data['request_key'] = strtolower($data['request_key']);
        $order = $orders->place($store, $request->user(), $data);

        return $this->present($order, 201);
    }

    public function reconcile(Request $request, Store $store, string $key): JsonResponse
    {
        $order = StoreOrder::withTrashed()->forStore($store)->where('manual_actor_id', $request->user()->id)->where('manual_owner_id', $store->owner_user_id)->where('manual_request_key', strtolower($key))->firstOrFail();
        abort_if($order->trashed(), 409, 'This manual order was archived.');

        return $this->present($order->load('items'), 200);
    }

    private function present(StoreOrder $order, int $status): JsonResponse
    {
        $owner = $order->store?->owner;
        $visible = ! $owner || ! app(PlatformWallet::class)->ordersLocked($owner) || ! DB::table('platform_wallet_entries')->where('store_order_id', $order->id)->where('type', 'order_fee')->exists();

        return response()->json(['data' => $visible ? new MerchantOrderResource($order) : ['id' => $order->id], 'orders_locked' => ! $visible], $status);
    }
}
