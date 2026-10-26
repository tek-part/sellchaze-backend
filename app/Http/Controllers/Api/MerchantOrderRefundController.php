<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreOrderRefund;
use App\Models\StoreOrderReturn;
use App\Services\Commerce\MerchantOrderQuery;
use App\Services\Commerce\StoreOrderRefunds;
use App\Services\Commerce\StoreOrderReturns;
use App\Services\PlatformWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Merchant refunds and returns (store-admin group, `store.orders.manage`). Bound under
 * `store.scope`, so every StoreOrder / StoreOrderReturn query is isolated to the current store.
 * Refunds are bookkeeping only: money goes back through the gateway or manually.
 */
class MerchantOrderRefundController extends Controller
{
    public function __construct(private readonly StoreOrderRefunds $refunds, private readonly StoreOrderReturns $returns) {}

    /** GET /stores/{store}/orders/{order}/refunds */
    public function index(Request $request, Store $store, int $order): JsonResponse
    {
        return response()->json(['data' => $this->refunds->summary($this->order($order))]);
    }

    /** POST /stores/{store}/orders/{order}/refunds */
    public function store(Request $request, Store $store, int $order): JsonResponse
    {
        $data = $request->validate([
            'items' => ['sometimes', 'array', 'max:200'],
            'items.*.store_order_item_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'expected_refunded_total' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'reason' => ['required', Rule::in(StoreOrderRefund::REASONS)],
            'note' => ['nullable', 'string', 'max:2000'],
            'method' => ['required', Rule::in(StoreOrderRefund::METHODS)],
            'restock' => ['sometimes', 'boolean'],
        ]);
        $model = $this->order($order);
        $this->refunds->create($model, $data, (int) $request->user()->id);

        return response()->json(['data' => $this->refunds->summary($model->refresh())], 201);
    }

    /** GET /stores/{store}/orders/{order}/returns */
    public function orderReturns(Request $request, Store $store, int $order): JsonResponse
    {
        $model = $this->order($order);
        $names = $this->names($store, [$model->id]);

        return response()->json(['data' => $model->returns()->get()->map(fn (StoreOrderReturn $row) => $this->returns->present($row, true, $names))->values()]);
    }

    /** GET /stores/{store}/returns — the Returns page. */
    public function returnsIndex(Request $request, Store $store): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(StoreOrderReturn::STATUSES)],
            'search' => ['nullable', 'string', 'max:200'],
            'sort_by' => ['nullable', Rule::in(['id', 'created_at', 'updated_at', 'status'])],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ]);
        $perPage = min(max((int) $request->get('per_page', 25), 1), 100);
        $orders = app(MerchantOrderQuery::class)->build($store, array_filter(['search' => $filters['search'] ?? null]))->withTrashed()->select('store_orders.id');
        $base = StoreOrderReturn::query()->whereIn('store_order_id', $orders);
        $counts = (clone $base)->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        if (! empty($filters['status'])) {
            $base->where('status', $filters['status']);
        }
        $direction = $filters['sort_direction'] ?? 'desc';
        $paginator = $base->with(['order' => fn ($query) => $query->withTrashed()->select(['id', 'store_id', 'order_number', 'status', 'currency', 'customer_name', 'customer_email', 'customer_phone', 'grand_total'])])
            ->orderBy($filters['sort_by'] ?? 'created_at', $direction)->orderBy('id', $direction)->paginate($perPage);
        $names = $this->names($store, $paginator->getCollection()->pluck('store_order_id')->unique()->all());

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (StoreOrderReturn $row) => $this->returns->present($row, true, $names) + [
                'order' => $row->order ? ['id' => $row->order->id, 'order_number' => $row->order->order_number, 'status' => $row->order->status,
                    'currency' => $row->order->currency, 'grand_total' => $row->order->grand_total,
                    'customer' => ['name' => $row->order->customer_name, 'email' => $row->order->customer_email, 'phone' => $row->order->customer_phone]] : null,
            ])->values(),
            'counts' => $counts,
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
        ]);
    }

    /** PATCH /stores/{store}/returns/{return} */
    public function updateReturn(Request $request, Store $store, int $return): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(StoreOrderReturn::STATUSES)],
            'merchant_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'resolution' => ['sometimes', 'nullable', Rule::in(StoreOrderReturn::RESOLUTIONS)],
            'refund' => ['sometimes', 'nullable', 'array'],
            'refund.method' => ['required_with:refund', Rule::in(StoreOrderRefund::METHODS)],
            'refund.reason' => ['sometimes', Rule::in(StoreOrderRefund::REASONS)],
            'refund.restock' => ['sometimes', 'boolean'],
            'refund.shipping_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'refund.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'refund.note' => ['nullable', 'string', 'max:2000'],
        ]);
        $row = StoreOrderReturn::query()->find($return);
        abort_if($row === null, 404, 'Return not found.');
        $this->order((int) $row->store_order_id);
        if (isset($data['refund'])) {
            $data['refund'] = array_intersect_key($data['refund'], array_flip(['method', 'reason', 'restock', 'shipping_amount', 'amount', 'note']));
        }
        $row = $this->returns->update($row, $data, (int) $request->user()->id);

        return response()->json(['data' => $this->returns->present($row, true, $this->names($store, [(int) $row->store_order_id]))]);
    }

    private function order(int $id): StoreOrder
    {
        $order = StoreOrder::query()->find($id); // StoreScope-isolated to the current store
        abort_if($order === null, 404, 'Order not found.');
        app(PlatformWallet::class)->assertOrderVisible($order);

        return $order;
    }

    /**
     * Line names for many orders in one query (return rows only store item ids).
     *
     * @param  array<int, int>  $orderIds
     * @return array<int, string>
     */
    private function names(Store $store, array $orderIds): array
    {
        return $orderIds === [] ? [] : StoreOrderItem::query()->withoutGlobalScopes()->where('store_id', $store->id)
            ->whereIn('store_order_id', $orderIds)->pluck('name', 'id')->all();
    }
}
