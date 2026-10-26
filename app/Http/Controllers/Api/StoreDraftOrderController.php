<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreDraftOrder;
use App\Services\Commerce\DraftOrderConversion;
use App\Services\Commerce\DraftOrders;
use App\Services\Commerce\GiftCards;
use App\Services\Commerce\ManualStoreOrders;
use App\Services\Commerce\StoreDigitalWhatsappClient;
use App\Services\Commerce\StoreShipping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Merchant draft orders (Shopify "Draft orders"); mounted in the shared store-admin group. */
class StoreDraftOrderController extends Controller
{
    public function __construct(private readonly DraftOrders $drafts) {}

    public function index(Request $request, Store $store): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,invoice_sent,completed,cancelled,active'],
            'search' => ['nullable', 'string', 'max:200'],
            'sort_by' => ['nullable', 'in:number,created_at,updated_at,grand_total'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $sort = ($filters['sort_by'] ?? null) === 'number' ? 'sequence' : ($filters['sort_by'] ?? 'id');
        $page = StoreDraftOrder::forStore($store)
            ->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->whereIn('status', StoreDraftOrder::EDITABLE))
            ->when(in_array($filters['status'] ?? null, StoreDraftOrder::STATUSES, true), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters) {
                $term = '%'.addcslashes(trim($filters['search']), '%_\\').'%';
                $query->where(fn ($match) => $match->where('number', 'like', $term)->orWhere('customer', 'like', $term)->orWhere('tags', 'like', $term));
            })
            ->orderBy($sort, $filters['sort_direction'] ?? 'desc')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
        $counts = StoreDraftOrder::forStore($store)->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'data' => $page->getCollection()->map(fn (StoreDraftOrder $draft) => $this->summary($draft))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage(),
                'counts' => collect(StoreDraftOrder::STATUSES)->mapWithKeys(fn ($status) => [$status => (int) ($counts[$status] ?? 0)])],
        ]);
    }

    /** Editor settings: delivery rates and tax, readable with the orders permission alone. */
    public function options(Request $request, Store $store): JsonResponse
    {
        return response()->json(['data' => [
            'currency' => $store->currency ?: 'USD',
            'shipping' => app(StoreShipping::class)->publicConfiguration($store),
            'tax' => ['enabled' => (bool) $store->tax_enabled, 'rate' => (string) ($store->tax_rate ?: '0'), 'prices_include' => (bool) $store->tax_prices_include],
            'invoice_days' => ['default' => 30, 'max' => DraftOrders::MAX_INVOICE_DAYS],
        ]], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, Store $store, int $draft): JsonResponse
    {
        return $this->respond($this->find($store, $draft));
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        $attributes = $this->drafts->normalize($store, $request->validate(DraftOrders::rules()));
        $totals = $this->drafts->compute($store, $attributes)['totals'];
        $draft = $this->drafts->create($store, $attributes + DraftOrders::snapshot($totals), $request->user()?->id);

        return $this->respond($draft, 201);
    }

    public function update(Request $request, Store $store, int $draft): JsonResponse
    {
        $data = $request->validate(DraftOrders::rules());
        $model = DB::transaction(function () use ($request, $store, $draft, $data) {
            $model = StoreDraftOrder::forStore($store)->whereKey($draft)->lockForUpdate()->firstOrFail();
            $this->assertEditable($model);
            $attributes = $this->drafts->normalize($store, $data);
            $totals = $this->drafts->compute($store, $attributes)['totals'];
            $model->update($attributes + DraftOrders::snapshot($totals) + ['updated_by_id' => $request->user()?->id]);

            return $model;
        });

        return $this->respond($model);
    }

    /** Live totals for unsaved editor state (or the stored draft when no lines are sent). Never reserves stock. */
    public function calculate(Request $request, Store $store, ?int $draft = null): JsonResponse
    {
        $model = $draft !== null ? $this->find($store, $draft) : null;
        if ($model === null || $request->has('line_items')) {
            $attributes = $this->drafts->normalize($store, $request->validate(DraftOrders::rules()));
        } else {
            $attributes = $model->only(['store_customer_id', 'customer', 'shipping_address', 'line_items', 'applied_discount', 'shipping_line']);
        }

        return response()->json(['data' => $this->drafts->compute($store, $attributes)['totals']], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** Generate (rotate) the customer invoice link and ready-to-share texts. Nothing is sent by the platform. */
    public function invoice(Request $request, Store $store, int $draft): JsonResponse
    {
        $data = $request->validate(['expires_in_days' => ['nullable', 'integer', 'min:1', 'max:'.DraftOrders::MAX_INVOICE_DAYS], 'locale' => ['nullable', 'in:ar,en']]);
        [$model, $invoice] = DB::transaction(function () use ($request, $store, $draft, $data) {
            $model = StoreDraftOrder::forStore($store)->whereKey($draft)->lockForUpdate()->firstOrFail();
            $this->assertEditable($model);
            $totals = $this->drafts->compute($store, $model->only(['store_customer_id', 'customer', 'shipping_address', 'line_items', 'applied_discount', 'shipping_line']))['totals'];
            $model->fill(DraftOrders::snapshot($totals));

            return [$model, $this->drafts->issueInvoice($store, $model, (int) ($data['expires_in_days'] ?? 30), $request->user()?->id)];
        });
        $messages = $this->drafts->invoiceMessages($store, $model, $invoice['url']);
        $locale = $data['locale'] ?? ($store->default_locale === 'ar' ? 'ar' : 'en');
        $phone = StoreDigitalWhatsappClient::phone((string) ($model->customer['phone'] ?? ''));

        return response()->json([
            'data' => $this->drafts->present($model->fresh()),
            'invoice' => [
                'url' => $invoice['url'],
                'expires_at' => $model->expires_at,
                'message' => $messages[$locale],
                'messages' => $messages,
                'whatsapp_url' => 'https://wa.me/'.($phone ? ltrim($phone, '+') : '').'?text='.rawurlencode($messages[$locale]),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** Convert into a real order exactly once; repeating returns the same order. */
    public function complete(Request $request, Store $store, int $draft, DraftOrderConversion $conversion, ManualStoreOrders $manual): JsonResponse
    {
        GiftCards::rejectOn($request);
        $data = $request->validate(['payment_status' => ['required', 'in:pending,paid'], 'payment_method' => ['nullable', 'in:cod,bank_transfer']]);
        abort_unless($manual->authorized($store, $request->user()), 403);
        $result = $conversion->complete($store, $draft, ['source' => 'merchant', 'payment_status' => $data['payment_status'],
            'payment_method' => $data['payment_method'] ?? null, 'actor_id' => $request->user()->id]);
        $order = $result['order'];

        return response()->json([
            'data' => $this->drafts->present($this->find($store, $draft)),
            'order' => ['id' => $order->id, 'order_number' => $order->order_number, 'payment_status' => $order->payment_status,
                'payment_method' => $order->payment_method, 'grand_total' => $order->grand_total, 'currency' => $order->currency],
            'created' => $result['created'],
        ], $result['created'] ? 201 : 200);
    }

    public function duplicate(Request $request, Store $store, int $draft): JsonResponse
    {
        $source = $this->find($store, $draft);
        $copy = $this->drafts->create($store, $source->only(['store_customer_id', 'customer', 'shipping_address', 'line_items', 'applied_discount',
            'shipping_line', 'totals', 'grand_total', 'note', 'tags']), $request->user()?->id);

        return $this->respond($copy, 201);
    }

    public function cancel(Request $request, Store $store, int $draft): JsonResponse
    {
        $model = DB::transaction(function () use ($request, $store, $draft) {
            $model = StoreDraftOrder::forStore($store)->whereKey($draft)->lockForUpdate()->firstOrFail();
            $this->assertEditable($model);
            $model->update(['status' => 'cancelled', 'cancelled_at' => now(), 'invoice_token_hash' => null, 'updated_by_id' => $request->user()?->id]);

            return $model;
        });

        return $this->respond($model);
    }

    public function destroy(Request $request, Store $store, int $draft): JsonResponse
    {
        DB::transaction(function () use ($store, $draft) {
            $model = StoreDraftOrder::forStore($store)->whereKey($draft)->lockForUpdate()->firstOrFail();
            if ($model->status === 'completed') {
                throw ValidationException::withMessages(['status' => 'A completed draft cannot be deleted.']);
            }
            $model->delete();
        });

        return response()->json(['message' => 'Draft order deleted.']);
    }

    private function find(Store $store, int $draft): StoreDraftOrder
    {
        return StoreDraftOrder::forStore($store)->whereKey($draft)->firstOrFail();
    }

    private function assertEditable(StoreDraftOrder $draft): void
    {
        if (! $draft->editable()) {
            throw ValidationException::withMessages(['status' => 'Only open drafts can be changed.']);
        }
    }

    private function respond(StoreDraftOrder $draft, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->drafts->present($draft->fresh() ?? $draft)], $status, [], JSON_UNESCAPED_UNICODE);
    }

    private function summary(StoreDraftOrder $draft): array
    {
        return [
            'id' => $draft->id, 'number' => $draft->number, 'status' => $draft->status,
            'customer' => $draft->customer ?? (object) [], 'grand_total' => $draft->grand_total, 'currency' => $draft->currency,
            'items_count' => array_sum(array_map(fn ($line) => (int) ($line['quantity'] ?? 0), $draft->line_items ?? [])),
            'tags' => $draft->tags ?? [], 'expires_at' => $draft->expires_at, 'expired' => $draft->expired(),
            'invoice_sent_at' => $draft->invoice_sent_at, 'completed_order_id' => $draft->completed_order_id,
            'created_at' => $draft->created_at, 'updated_at' => $draft->updated_at,
        ];
    }
}
