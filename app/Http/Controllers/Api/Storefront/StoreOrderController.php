<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\StoreOrderResource;
use App\Models\StoreOrder;
use App\Models\StoreOrderReturn;
use App\Services\Commerce\StoreOrderReturns;
use App\Services\Commerce\StoreOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 5: a customer's own orders (store.customer). Scoped to the current
 * store (StoreScope) and to the authenticated customer — a customer can only
 * ever see their own orders in their own store.
 */
class StoreOrderController extends Controller
{
    use ResolvesStorefront;

    public function __construct(private readonly StoreOrderService $orders, private readonly StoreOrderReturns $returns) {}

    /** GET /storefront/orders */
    public function index(Request $request): JsonResponse
    {
        $customer = $this->currentCustomer($request);
        $perPage = min(max((int) $request->get('per_page', 15), 1), 50);

        $paginator = StoreOrder::query()
            ->where('store_customer_id', $customer->id)
            ->with('items')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => StoreOrderResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /** GET /storefront/orders/{number} */
    public function show(Request $request, string $number): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->owned($request, $number))]);
    }

    /** POST /storefront/orders/{number}/returns — delivered orders, inside the store's return window, one open return at a time. */
    public function requestReturn(Request $request, string $number): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.store_order_item_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'reason' => ['required', Rule::in(StoreOrderReturn::REASONS)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $order = $this->owned($request, $number);
        $this->returns->request($order, (int) $this->currentCustomer($request)->id, $data);

        return response()->json(['data' => $this->detail($order->refresh())], 201);
    }

    /** The customer's order plus its return requests and whether a new one can be opened. */
    private function detail(StoreOrder $order): array
    {
        $resource = (new StoreOrderResource($order->load('items')))->resolve(request());

        return $resource + [
            'refunded_total' => $order->refunded_total ?? '0.00',
            'returns' => $order->returns()->get()->map(fn (StoreOrderReturn $row) => $this->returns->present($row, false))->values()->all(),
            'return_eligibility' => $this->returns->eligibility($order),
        ];
    }

    /** POST /storefront/orders/{number}/cancel */
    public function cancel(Request $request, string $number): JsonResponse
    {
        $order = $this->owned($request, $number);
        $this->orders->cancelByCustomer($order);

        return response()->json(['data' => new StoreOrderResource($order->load('items'))]);
    }

    private function owned(Request $request, string $number): StoreOrder
    {
        $customer = $this->currentCustomer($request);
        $order = StoreOrder::query()
            ->where('store_customer_id', $customer->id)
            ->where('order_number', $number)
            ->first();
        abort_if($order === null, 404, 'Order not found.');

        return $order;
    }
}
