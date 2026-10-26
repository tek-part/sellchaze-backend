<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderFulfillment;
use App\Services\Commerce\StoreFulfillments;
use App\Services\PlatformWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Merchant fulfillments (store-admin group, `store.orders.manage`). Bound under `store.scope`, so
 * StoreOrder / StoreOrderFulfillment lookups are isolated to the current store. Every response is the
 * order's fulfillment summary so the dashboard redraws from one shape.
 */
class MerchantOrderFulfillmentController extends Controller
{
    public function __construct(private readonly StoreFulfillments $fulfillments) {}

    /** GET /stores/{store}/orders/{order}/fulfillments */
    public function index(Request $request, Store $store, int $order): JsonResponse
    {
        return response()->json(['data' => $this->fulfillments->summary($this->order($order))]);
    }

    /** POST /stores/{store}/orders/{order}/fulfillments */
    public function store(Request $request, Store $store, int $order): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.store_order_item_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            ...$this->trackingRules(true),
        ]);
        $model = $this->order($order);
        $this->fulfillments->create($model, $data, (int) $request->user()->id);

        return response()->json(['data' => $this->fulfillments->summary($model->refresh())], 201);
    }

    /** PATCH /stores/{store}/fulfillments/{fulfillment} */
    public function update(Request $request, Store $store, int $fulfillment): JsonResponse
    {
        $data = $request->validate([
            ...$this->trackingRules(false),
            'status' => ['sometimes', Rule::in(StoreOrderFulfillment::UPDATABLE_STATUSES)],
        ]);
        $row = $this->fulfillment($fulfillment);
        $this->fulfillments->update($row, $data, (int) $request->user()->id);

        return response()->json(['data' => $this->fulfillments->summary($this->order((int) $row->store_order_id))]);
    }

    /** POST /stores/{store}/fulfillments/{fulfillment}/cancel */
    public function cancel(Request $request, Store $store, int $fulfillment): JsonResponse
    {
        $row = $this->fulfillment($fulfillment);
        $this->fulfillments->cancel($row, (int) $request->user()->id);

        return response()->json(['data' => $this->fulfillments->summary($this->order((int) $row->store_order_id))]);
    }

    /** @return array<string, list<mixed>> */
    private function trackingRules(bool $create): array
    {
        return [
            'carrier' => [$create ? 'required' : 'sometimes', Rule::in(array_keys(StoreOrderFulfillment::CARRIERS))],
            'carrier_name' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._\/-]*$/'],
            'tracking_url' => ['nullable', 'string', 'max:500', 'url:https,http'],
            'notify_customer' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function order(int $id): StoreOrder
    {
        $order = StoreOrder::query()->find($id); // StoreScope-isolated to the current store
        abort_if($order === null, 404, 'Order not found.');
        app(PlatformWallet::class)->assertOrderVisible($order);

        return $order;
    }

    private function fulfillment(int $id): StoreOrderFulfillment
    {
        $row = StoreOrderFulfillment::query()->find($id);
        abort_if($row === null, 404, 'Fulfillment not found.');
        $this->order((int) $row->store_order_id);

        return $row;
    }
}
