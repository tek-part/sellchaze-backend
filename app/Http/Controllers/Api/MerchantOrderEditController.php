<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantOrderResource;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderEdit;
use App\Services\Commerce\ManualStoreOrders;
use App\Services\Commerce\MerchantOrderEditing;
use App\Services\Commerce\ProductPersonalization;
use App\Services\PlatformWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MerchantOrderEditController extends Controller
{
    public function show(Request $request, Store $store, int $order): JsonResponse
    {
        abort_unless(app(ManualStoreOrders::class)->authorized($store, $request->user()), 403);
        $row = StoreOrder::forStore($store)->findOrFail($order);
        app(PlatformWallet::class)->assertOrderVisible($row);

        return $this->present($store, $row);
    }

    public function update(Request $request, Store $store, int $order): JsonResponse
    {
        $money = ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.ManualStoreOrders::MAX_MONEY];
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'expected_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'customer_name' => ['required', 'string', 'max:200'], 'customer_phone' => ['required', 'string', 'max:50'], 'customer_email' => ['nullable', 'email', 'max:200'],
            'shipping_address' => ['required', 'array:country,region,city,line1,line2,phone_alt'], 'shipping_address.country' => ['nullable', 'string', 'max:100'],
            'shipping_address.region' => ['nullable', 'string', 'max:200'], 'shipping_address.city' => ['nullable', 'string', 'max:200'],
            'shipping_address.line1' => ['nullable', 'string', 'max:2000'], 'shipping_address.line2' => ['nullable', 'string', 'max:1000'], 'shipping_address.phone_alt' => ['nullable', 'string', 'max:50'],
            'shipping_total' => $money, 'grand_total' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.ManualStoreOrders::MAX_MONEY], 'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'], 'items.*' => ['array:item_id,product_id,variant_id,quantity,unit_price,personalization,expected_price,price_overridden'],
            'items.*.item_id' => ['nullable', 'integer', 'min:1'], 'items.*.product_id' => ['nullable', 'integer', 'min:1'], 'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'], 'items.*.unit_price' => $money,
            'items.*.expected_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.ManualStoreOrders::MAX_MONEY], 'items.*.price_overridden' => ['nullable', 'boolean']] + ProductPersonalization::inputRules('items.*.personalization'));
        $data['request_key'] = Str::lower($data['request_key']);
        $data['shipping_total'] = bcadd((string) $data['shipping_total'], '0', 2);
        if (isset($data['grand_total'])) {
            $data['grand_total'] = bcadd((string) $data['grand_total'], '0', 2);
        }
        foreach ($data['items'] as &$line) {
            $line['quantity'] = (int) $line['quantity'];
            $line['unit_price'] = bcadd((string) $line['unit_price'], '0', 2);
            if (isset($line['expected_price'])) {
                $line['expected_price'] = bcadd((string) $line['expected_price'], '0', 2);
            }
            if (isset($line['price_overridden'])) {
                $line['price_overridden'] = (bool) $line['price_overridden'];
            }
            foreach (['item_id', 'product_id', 'variant_id'] as $field) {
                if (isset($line[$field])) {
                    $line[$field] = (int) $line[$field];
                }
            }
        }
        unset($line);
        $row = app(MerchantOrderEditing::class)->apply($store, $request->user(), $order, $data);

        return $this->present($store->fresh(), $row->fresh());
    }

    public function reconcile(Request $request, Store $store, int $order, string $key): JsonResponse
    {
        abort_unless(app(ManualStoreOrders::class)->authorized($store, $request->user()), 403);
        $edit = StoreOrderEdit::where('store_id', $store->id)->where('store_order_id', $order)->where('actor_id', $request->user()->id)->where('request_key', Str::lower($key))->firstOrFail();
        abort_unless((int) $edit->owner_id === (int) $store->owner_user_id, 409, 'Ownership changed after this edit.');
        $row = StoreOrder::forStore($store)->findOrFail($order);
        app(PlatformWallet::class)->assertOrderVisible($row);

        return $this->present($store, $row);
    }

    private function present(Store $store, StoreOrder $row): JsonResponse
    {
        $editing = app(MerchantOrderEditing::class);

        return response()->json(['data' => new MerchantOrderResource($row->load(['items', 'merchantReview', 'statusChanges.actor', 'b2bOrder'])),
            'edit' => $editing->capabilities($store, $row) + ['token' => $editing->token($store, $row)],
            'pricing' => ['currency' => $row->currency, 'tax_enabled' => (bool) $store->tax_enabled, 'tax_rate' => (string) ($store->tax_rate ?: '0'), 'tax_prices_include' => (bool) $store->tax_prices_include]]);
    }
}
