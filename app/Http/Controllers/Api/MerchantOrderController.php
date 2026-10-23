<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddOrderNoteRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\MerchantOrderResource;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Services\Commerce\DigitalDeliveryTracking;
use App\Services\Commerce\DigitalOrderEmail;
use App\Services\Commerce\ManualBankPayment;
use App\Services\Commerce\ManualCashPayment;
use App\Services\Commerce\MerchantOrderQuery;
use App\Services\Commerce\MerchantOrderReviews;
use App\Services\Commerce\StoreOrderService;
use App\Services\PlatformWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
    public function __construct(private readonly StoreOrderService $orders) {}

    /** GET /stores/{store}/orders */
    public function index(Request $request, Store $store): JsonResponse
    {
        $query = app(MerchantOrderQuery::class);
        $filters = $query->filters($request);
        $perPage = min(max((int) $request->get('per_page', 25), 1), 100);
        $sort = $filters['sort'] ?? 'created_at';
        $dir = $filters['dir'] ?? 'desc';
        $base = $query->build($store, array_diff_key($filters, array_flip(['status', 'payment_status', 'sort', 'dir'])));
        $counts = (clone $base)->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $paymentCounts = (clone $base)->select('payment_status')->selectRaw('COUNT(*) AS total')->groupBy('payment_status')->pluck('total', 'payment_status');
        $locked = $store->owner && app(PlatformWallet::class)->ordersLocked($store->owner);
        $charged = DB::table('platform_wallet_entries')->where('type', 'order_fee')->whereNotNull('store_order_id')->select('store_order_id');
        $lockedCount = $locked ? StoreOrder::forStore($store)->whereIn('id', $charged)->count() : 0;
        $paginator = $query->build($store, $filters)->withCount('items')->withBotVerification()->with(['merchantReview'])->orderBy($sort, $dir)->orderBy('id', $dir)->paginate($perPage);

        return response()->json([
            'data' => MerchantOrderResource::collection($paginator->getCollection()),
            'counts' => $counts, 'payment_counts' => $paymentCounts,
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'locked_orders' => $lockedCount],
        ]);
    }

    /** GET /stores/{store}/orders/{order} */
    public function show(Request $request, Store $store, int $order): JsonResponse
    {
        return response()->json([
            'data' => new MerchantOrderResource($this->find($order)->load(['items', 'statusChanges.actor'])),
        ]);
    }

    public function printData(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'integer', 'min:1', 'distinct']]);
        $rows = StoreOrder::forStore($store)->whereIn('id', $data['ids'])->withBotVerification()->with(['items', 'merchantReview'])->orderBy('id')->get();
        abort_unless($rows->count() === count($data['ids']), 404);
        foreach ($rows as $row) {
            app(PlatformWallet::class)->assertOrderVisible($row);
        }

        return response()->json(['store' => ['name' => $store->name], 'data' => MerchantOrderResource::collection($rows)]);
    }

    public function filterProducts(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:200']]);
        $visible = app(MerchantOrderQuery::class)->build($store, [])->select('store_orders.id');
        $rows = DB::table('store_order_items')->whereNull('superseded_at')->where('store_id', $store->id)->whereNotNull('store_product_id')->whereIn('store_order_id', $visible)
            ->when(isset($data['search']) && $data['search'] !== '', fn ($query) => $query->where('name', 'like', '%'.$data['search'].'%'))
            ->select('store_product_id')->selectRaw('MIN(name) AS name')->groupBy('store_product_id')->orderBy('name')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn ($row) => ['id' => $row->store_product_id, 'name' => $row->name])]);
    }

    public function bulkStatus(Request $request, Store $store): JsonResponse
    {
        $risk = in_array($request->input('status'), ['high_risk', 'moderate_risk'], true);
        $data = $request->validate(['status' => ['required', Rule::in([...StoreOrder::STATUSES, 'high_risk', 'moderate_risk'])],
            'eligible_only' => ['sometimes', 'boolean'],
            'orders' => ['required', 'array', 'min:1', 'max:100'], 'orders.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'orders.*.status' => ['required', Rule::in(StoreOrder::STATUSES)],
            'orders.*.review_version' => [Rule::requiredIf($risk), 'integer', 'min:0']]);
        $result = DB::transaction(function () use ($request, $store, $data, $risk) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $expected = collect($data['orders'])->keyBy('id');
            $rows = StoreOrder::forStore($store)->whereIn('id', $expected->keys())->orderBy('id')->lockForUpdate()->get();
            abort_unless($rows->count() === $expected->count(), 404);
            foreach ($rows as $row) {
                app(PlatformWallet::class)->assertOrderVisible($row);
                if ($risk || $row->status !== $data['status']) {
                    abort_unless($row->status === $expected[$row->id]['status'], 409, 'Order changed. Reload before changing its status.');
                    if (! $risk && ! ($data['eligible_only'] ?? false) && ! $this->orders->canTransition($row, $data['status'])) {
                        throw ValidationException::withMessages(['status' => 'One or more selected orders cannot make this transition.']);
                    }
                }
            }
            $changed = 0;
            $skipped = 0;
            $unchanged = 0;
            foreach ($rows as $row) {
                if ($risk) {
                    $updated = app(MerchantOrderReviews::class)->classify($row, $data['status'], $expected[$row->id]['review_version'], (int) $request->user()->id);
                    $updated ? $changed++ : $unchanged++;
                } elseif ($row->status === $data['status']) {
                    $unchanged++;
                } elseif (! $this->orders->canTransition($row, $data['status'])) {
                    $skipped++;
                } else {
                    $this->orders->transition($row, $data['status'], (int) $request->user()->id);
                    $changed++;
                }
            }

            return ['updated' => $changed, 'skipped' => $skipped, 'unchanged' => $unchanged];
        });

        return response()->json($result);
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

    public function confirmBankPayment(Request $request, Store $store, int $order): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:200'], 'note' => ['nullable', 'string', 'max:2000']]);
        $model = app(ManualBankPayment::class)->confirm($this->find($order), $data['reference'], (int) $request->user()->id, $data['note'] ?? null);

        return response()->json(['data' => new MerchantOrderResource($model->load(['items', 'statusChanges.actor']))]);
    }

    public function confirmCashPayment(Request $request, Store $store, int $order): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:200'], 'amount' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999.99'],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'], 'collected' => ['required', 'accepted'], 'note' => ['nullable', 'string', 'max:2000']]);
        $model = app(ManualCashPayment::class)->confirm($this->find($order), $data['reference'], (string) $data['amount'], $data['currency'], (int) $request->user()->id, $data['note'] ?? null);

        return response()->json(['data' => new MerchantOrderResource($model->load(['items', 'statusChanges.actor']))]);
    }

    public function digitalEmail(Request $request, Store $store, int $order): JsonResponse
    {
        return response()->json(['data' => app(DigitalOrderEmail::class)->status($this->find($order))]);
    }

    public function resendDigitalEmail(Request $request, Store $store, int $order): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:receipt,delivery'], 'message_id' => ['nullable', 'uuid'], 'request_key' => ['required', 'uuid']]);
        $model = $this->find($order);
        app(DigitalOrderEmail::class)->request($model, $data['kind'], $data['message_id'] ?? null, $data['request_key'], (int) $request->user()->id);

        return response()->json(['data' => app(DigitalOrderEmail::class)->status($model)], 202);
    }

    private function find(int $id): StoreOrder
    {
        $order = StoreOrder::query()->find($id); // StoreScope-isolated to the current store
        abort_if($order === null, 404, 'Order not found.');
        app(PlatformWallet::class)->assertOrderVisible($order);

        return $order;
    }

    public function digitalDeliveryStatus(Request $request, Store $store, int $order): JsonResponse
    {
        return response()->json(['data' => app(DigitalDeliveryTracking::class)->status($this->find($order))]);
    }

    public function resendDigitalItemEmail(Request $request, Store $store, int $order): JsonResponse
    {
        $data = $request->validate(['item_id' => ['required', 'integer', 'min:1'], 'message_id' => ['nullable', 'uuid'], 'request_key' => ['required', 'uuid']]);
        $model = $this->find($order);
        $tracking = app(DigitalDeliveryTracking::class);
        $tracking->resendEmail($model, $data['item_id'], $data['message_id'] ?? null, $data['request_key'], (int) $request->user()->id);

        return response()->json(['data' => $tracking->status($model)], 202);
    }
}
