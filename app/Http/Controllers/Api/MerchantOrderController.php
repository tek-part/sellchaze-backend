<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddOrderNoteRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\MerchantOrderResource;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Services\Commerce\StoreOrderService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6E: merchant order management. Bound under `store.scope`, so the
 * CurrentStore tenant is set and every StoreOrder query is auto-isolated to the
 * owner's store (ownership already asserted by the middleware/StorePolicy —
 * another store's owner gets 403). Reuses StoreOrderService for the lifecycle;
 * this controller stays thin (query + delegate).
 */
class MerchantOrderController extends Controller
{
    private const SORTABLE = ['created_at', 'placed_at', 'grand_total', 'status', 'order_number'];

    public function __construct(private readonly StoreOrderService $orders) {}

    /** GET /stores/{store}/orders */
    public function index(Request $request, Store $store): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        $sort = in_array($request->get('sort'), self::SORTABLE, true) ? (string) $request->get('sort') : 'created_at';
        $dir = $request->get('dir') === 'asc' ? 'asc' : 'desc';

        $paginator = StoreOrder::query()
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->get('status')))
            ->when($request->filled('order_number'), fn (Builder $q) => $q->where('order_number', 'like', '%'.$request->get('order_number').'%'))
            ->when($request->filled('customer'), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('customer_name', 'like', '%'.$request->get('customer').'%')
                ->orWhere('customer_email', 'like', '%'.$request->get('customer').'%')))
            ->when($request->filled('search'), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('order_number', 'like', '%'.$request->get('search').'%')
                ->orWhere('customer_name', 'like', '%'.$request->get('search').'%')
                ->orWhere('customer_email', 'like', '%'.$request->get('search').'%')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('placed_at', '>=', $request->get('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('placed_at', '<=', $request->get('date_to')))
            ->withCount('items')
            ->orderBy($sort, $dir)
            ->paginate($perPage);

        return response()->json([
            'data' => MerchantOrderResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /** GET /stores/{store}/orders/{order} */
    public function show(Request $request, Store $store, int $order): JsonResponse
    {
        return response()->json([
            'data' => new MerchantOrderResource($this->find($order)->load(['items', 'statusChanges.actor'])),
        ]);
    }

    /** PATCH /stores/{store}/orders/{order}/status */
    public function updateStatus(UpdateOrderStatusRequest $request, Store $store, int $order): JsonResponse
    {
        $model = $this->find($order);
        $to = (string) $request->input('status');

        // Fail-closed: reject illegal jumps with 422 (no invalid state changes).
        if (! $this->orders->canTransition($model, $to)) {
            throw ValidationException::withMessages([
                'status' => "Cannot change status from {$model->status} to {$to}.",
            ]);
        }

        $this->orders->transition($model, $to, (int) $request->user()->id, $request->input('note'));

        return response()->json([
            'data' => new MerchantOrderResource($model->load(['items', 'statusChanges.actor'])),
        ]);
    }

    /** POST /stores/{store}/orders/{order}/note */
    public function addNote(AddOrderNoteRequest $request, Store $store, int $order): JsonResponse
    {
        $model = $this->find($order);
        $this->orders->addNote($model, (string) $request->input('note'), (int) $request->user()->id);

        return response()->json([
            'data' => new MerchantOrderResource($model->load(['items', 'statusChanges.actor'])),
        ]);
    }

    private function find(int $id): StoreOrder
    {
        $order = StoreOrder::query()->find($id); // StoreScope-isolated to the current store
        abort_if($order === null, 404, 'Order not found.');

        return $order;
    }
}
