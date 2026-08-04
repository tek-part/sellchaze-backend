<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvestmentOpportunityRequest;
use App\Models\InvestmentOpportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Investment & partnership opportunities. A factory lists itself as open to
 * investment or partnership; Sellchaze reviews; approved listings appear on a
 * board that investors and potential partners browse.
 *
 * Surfaces:
 *  - owner:    POST /opportunities, GET /me/opportunities
 *  - investor: GET /opportunities (approved board), GET /opportunities/{id}
 *  - admin:    GET /admin/opportunities, POST /admin/opportunities/{id}/review
 */
class InvestmentOpportunityController extends Controller
{
    /** POST /opportunities */
    public function store(StoreInvestmentOpportunityRequest $request): JsonResponse
    {
        $data = $request->validated();
        $opportunity = InvestmentOpportunity::create([
            ...$data,
            'user_id' => $request->user()->id,
            'currency' => $data['currency'] ?? 'EGP',
            'status' => InvestmentOpportunity::STATUS_PENDING,
        ]);

        return response()->json(['data' => $this->present($opportunity)], 201);
    }

    /** GET /me/opportunities */
    public function mine(Request $request): JsonResponse
    {
        $rows = InvestmentOpportunity::query()
            ->where('user_id', $request->user()->id)
            ->with('sector:id,name_en,name_ar,slug')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($rows);
    }

    /** GET /opportunities — the approved board (filterable by kind/sector). */
    public function board(Request $request): JsonResponse
    {
        $rows = InvestmentOpportunity::query()
            ->published()
            ->with(['owner:id,name,avatar', 'sector:id,name_en,name_ar,slug'])
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->get('kind')))
            ->when($request->filled('sector_id'), fn ($q) => $q->where('sector_id', $request->get('sector_id')))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($rows, withOwner: true);
    }

    /** GET /opportunities/{id} */
    public function show(int $id): JsonResponse
    {
        $opportunity = InvestmentOpportunity::query()
            ->published()
            ->with(['owner:id,name,avatar', 'sector:id,name_en,name_ar,slug'])
            ->findOrFail($id);

        return response()->json(['data' => $this->present($opportunity, withOwner: true, withContact: true)]);
    }

    /** GET /admin/opportunities — moderation queue. */
    public function adminIndex(Request $request): JsonResponse
    {
        $rows = InvestmentOpportunity::query()
            ->with(['owner:id,name,avatar', 'sector:id,name_en,name_ar,slug'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($rows, withOwner: true);
    }

    /** POST /admin/opportunities/{id}/review */
    public function review(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected,closed'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $opportunity = InvestmentOpportunity::findOrFail($id);
        $opportunity->update([
            'status' => $data['status'],
            'review_note' => $data['review_note'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['data' => $this->present($opportunity->fresh(['owner', 'sector']), withOwner: true)]);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->get('per_page', 15), 1), 100);
    }

    private function paginated($rows, bool $withOwner = false): JsonResponse
    {
        return response()->json([
            'data' => collect($rows->items())
                ->map(fn (InvestmentOpportunity $o) => $this->present($o, $withOwner))
                ->all(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(InvestmentOpportunity $o, bool $withOwner = false, bool $withContact = false): array
    {
        $locale = app()->getLocale();

        $data = [
            'id' => $o->id,
            'kind' => $o->kind,
            'title' => $o->title,
            'description' => $o->description,
            'amount_sought' => $o->amount_sought !== null ? (float) $o->amount_sought : null,
            'currency' => $o->currency,
            'equity_offered' => $o->equity_offered !== null ? (float) $o->equity_offered : null,
            'city' => $o->city,
            'status' => $o->status,
            'review_note' => $o->review_note,
            'created_at' => $o->created_at?->toIso8601String(),
        ];

        if ($o->relationLoaded('sector') && $o->sector) {
            $data['sector'] = [
                'id' => $o->sector->id,
                'slug' => $o->sector->slug,
                'name' => $locale === 'ar' ? $o->sector->name_ar : $o->sector->name_en,
            ];
        }

        if ($withOwner && $o->relationLoaded('owner') && $o->owner) {
            $data['owner'] = [
                'id' => $o->owner->id,
                'name' => $o->owner->name,
                'avatar' => $o->owner->avatar,
            ];
        }

        // Contact details are revealed only on the detail view, not on the board.
        if ($withContact) {
            $data['contact_email'] = $o->contact_email;
            $data['contact_phone'] = $o->contact_phone;
        }

        return $data;
    }
}
