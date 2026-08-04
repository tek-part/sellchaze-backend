<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinancingRequest;
use App\Models\FinancingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Financing requests. A factory/merchant raises a request; Sellchaze (admin)
 * reviews and approves it; funders browse the approved board and respond.
 *
 * Surfaces:
 *  - owner:  POST /financing-requests, GET /me/financing-requests
 *  - funder: GET /financing-requests (approved board), GET /financing-requests/{id}
 *  - admin:  GET /admin/financing-requests, POST /admin/financing-requests/{id}/review
 */
class FinancingRequestController extends Controller
{
    /** POST /financing-requests — the authenticated owner raises a request. */
    public function store(StoreFinancingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $financing = FinancingRequest::create([
            'user_id' => $request->user()->id,
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'EGP',
            'purpose' => $data['purpose'],
            'repayment_months' => $data['repayment_months'] ?? null,
            'has_confirmed_order' => $data['has_confirmed_order'],
            'description' => $data['description'],
            'status' => FinancingRequest::STATUS_PENDING,
        ]);

        return response()->json(['data' => $this->present($financing)], 201);
    }

    /** GET /me/financing-requests — the owner's own requests. */
    public function mine(Request $request): JsonResponse
    {
        $rows = FinancingRequest::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($rows);
    }

    /** GET /financing-requests — the approved board funders browse. */
    public function board(Request $request): JsonResponse
    {
        $rows = FinancingRequest::query()
            ->published()
            ->with('requester:id,name,avatar')
            ->when($request->filled('purpose'), fn ($q) => $q->where('purpose', $request->get('purpose')))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($rows, withRequester: true);
    }

    /** GET /financing-requests/{id} — a single published request. */
    public function show(int $id): JsonResponse
    {
        $financing = FinancingRequest::query()->published()->with('requester:id,name,avatar')->findOrFail($id);

        return response()->json(['data' => $this->present($financing, withRequester: true)]);
    }

    /** GET /admin/financing-requests — moderation queue. */
    public function adminIndex(Request $request): JsonResponse
    {
        $rows = FinancingRequest::query()
            ->with('requester:id,name,avatar')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($rows, withRequester: true);
    }

    /** POST /admin/financing-requests/{id}/review — approve or reject. */
    public function review(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected,funded,closed'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $financing = FinancingRequest::findOrFail($id);
        $financing->update([
            'status' => $data['status'],
            'review_note' => $data['review_note'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['data' => $this->present($financing->fresh('requester'), withRequester: true)]);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->get('per_page', 15), 1), 100);
    }

    private function paginated($rows, bool $withRequester = false): JsonResponse
    {
        return response()->json([
            'data' => collect($rows->items())->map(fn (FinancingRequest $f) => $this->present($f, $withRequester))->all(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(FinancingRequest $f, bool $withRequester = false): array
    {
        $data = [
            'id' => $f->id,
            'amount' => (float) $f->amount,
            'currency' => $f->currency,
            'purpose' => $f->purpose,
            'repayment_months' => $f->repayment_months,
            'has_confirmed_order' => $f->has_confirmed_order,
            'description' => $f->description,
            'status' => $f->status,
            'review_note' => $f->review_note,
            'created_at' => $f->created_at?->toIso8601String(),
        ];

        if ($withRequester && $f->relationLoaded('requester') && $f->requester) {
            $data['requester'] = [
                'id' => $f->requester->id,
                'name' => $f->requester->name,
                'avatar' => $f->requester->avatar,
            ];
        }

        return $data;
    }
}
