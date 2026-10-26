<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreGiftCardResource;
use App\Models\Store;
use App\Models\StoreGiftCard;
use App\Models\StoreGiftCardTransaction;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Commerce\GiftCards;
use App\Services\Commerce\ManualStoreOrders;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Merchant gift cards (Products > Gift cards). Under `store.scope`/`store.own`; managers need
 * store.coupons.manage. The full code is returned once, by the issue response, and never again.
 */
class StoreGiftCardsController extends Controller
{
    public function __construct(private readonly GiftCards $giftCards) {}

    /** GET /stores/{store}/gift-cards */
    public function index(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,disabled,expired,depleted'],
            'sort_by' => ['nullable', 'in:created_at,balance,initial_value,expires_at'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $now = now();
        $search = trim((string) ($data['search'] ?? ''));
        $paginator = StoreGiftCard::forStore($store)
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $match) use ($search) {
                $last4 = GiftCards::normalize($search);
                if ($last4 !== '' && strlen($last4) <= 4) {
                    $match->orWhere('last4', 'like', '%'.$last4);
                }
                $like = '%'.addcslashes($search, '%_\\').'%';
                $match->orWhere('customer_name', 'like', $like)->orWhere('customer_email', 'like', $like)->orWhere('customer_phone', 'like', $like);
            }))
            ->when(($data['status'] ?? null) === 'disabled', fn (Builder $query) => $query->where('status', 'disabled'))
            ->when(($data['status'] ?? null) === 'expired', fn (Builder $query) => $query->where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<', $now))
            ->when(in_array($data['status'] ?? null, ['active', 'depleted'], true), fn (Builder $query) => $query->where('status', 'active')
                ->where(fn (Builder $live) => $live->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
                ->where('balance', ($data['status'] ?? null) === 'active' ? '>' : '<=', 0))
            ->orderBy($data['sort_by'] ?? 'created_at', $data['sort_direction'] ?? 'desc')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 15));

        return response()->json([
            'data' => StoreGiftCardResource::collection($paginator->getCollection()),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
        ]);
    }

    /** POST /stores/{store}/gift-cards — the only response that carries the full code. */
    public function store(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate([
            'initial_value' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.ManualStoreOrders::MAX_MONEY],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ] + $this->contactRules());
        $issued = $this->giftCards->issue($store, $data, (int) $request->user()->id);

        return response()->json(['data' => new StoreGiftCardResource($issued['card']), 'code' => $issued['code']], 201,
            ['Cache-Control' => 'private, no-store']);
    }

    /** GET /stores/{store}/gift-cards/{giftCard} */
    public function show(Request $request, Store $store, int $giftCard): JsonResponse
    {
        $card = $this->find($store, $giftCard);
        $rows = StoreGiftCardTransaction::forStore($store)->where('gift_card_id', $card->id)->orderByDesc('id')->limit(200)->get();
        $orders = StoreOrder::forStore($store)->whereIn('id', $rows->pluck('store_order_id')->filter()->unique())->pluck('order_number', 'id');
        $actors = User::query()->whereIn('id', $rows->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        return response()->json(['data' => new StoreGiftCardResource($card),
            'transactions' => $rows->map(fn (StoreGiftCardTransaction $row) => [
                'id' => $row->id, 'amount' => $row->amount, 'balance_after' => $row->balance_after, 'reason' => $row->reason, 'note' => $row->note,
                'store_order_id' => $row->store_order_id, 'order_number' => $row->store_order_id ? ($orders[$row->store_order_id] ?? null) : null,
                'actor_id' => $row->actor_id, 'actor_name' => $row->actor_id ? ($actors[$row->actor_id] ?? null) : null, 'created_at' => $row->created_at,
            ])->values()]);
    }

    /** PUT /stores/{store}/gift-cards/{giftCard} — status, expiry, contact and note; never the balance. */
    public function update(Request $request, Store $store, int $giftCard): JsonResponse
    {
        $card = $this->find($store, $giftCard);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(StoreGiftCard::STATUSES)],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ] + array_map(fn (array $rules) => ['sometimes', ...$rules], $this->contactRules()));
        $card->update($data);

        return response()->json(['data' => new StoreGiftCardResource($card->fresh())]);
    }

    /** POST /stores/{store}/gift-cards/{giftCard}/adjust — signed amount with a reason, logged. */
    public function adjust(Request $request, Store $store, int $giftCard): JsonResponse
    {
        $card = $this->find($store, $giftCard);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'not_in:0', 'min:-'.ManualStoreOrders::MAX_MONEY, 'max:'.ManualStoreOrders::MAX_MONEY],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $card = $this->giftCards->adjust($card, (string) $data['amount'], trim($data['reason']), (int) $request->user()->id);

        return response()->json(['data' => new StoreGiftCardResource($card)]);
    }

    /** @return array<string, list<string>> */
    private function contactRules(): array
    {
        return [
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_email' => ['nullable', 'email', 'max:190'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function find(Store $store, int $id): StoreGiftCard
    {
        $card = StoreGiftCard::forStore($store)->find($id);
        abort_if($card === null, 404, 'Gift card not found.');

        return $card;
    }
}
