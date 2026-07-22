<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationsApiController extends Controller
{
    // ---- User endpoints ----

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = VerificationRequest::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'is_verified' => (bool) ($user->is_verified ?? false),
            'verified_at' => $user->verified_at?->toIso8601String(),
            'request' => $current ? $this->serialize($current) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! empty($user->is_verified)) {
            return response()->json(['message' => 'Account already verified.'], 422);
        }

        $existing = VerificationRequest::query()
            ->where('user_id', $user->id)
            ->where('status', VerificationRequest::STATUS_PENDING)
            ->first();
        if ($existing) {
            return response()->json(['message' => 'You already have a pending request.', 'data' => $this->serialize($existing)], 422);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['string', 'max:500'],
        ]);

        $vr = VerificationRequest::create([
            'user_id' => $user->id,
            'status' => VerificationRequest::STATUS_PENDING,
            'notes' => $validated['notes'] ?? null,
            'documents' => $validated['documents'] ?? [],
        ]);

        return response()->json(['data' => $this->serialize($vr)], 201);
    }

    // ---- Admin endpoints ----

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $q = VerificationRequest::query()->with(['user:id,name,email,is_verified', 'reviewer:id,name']);
        if ($status && in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $q->where('status', $status);
        }
        $rows = $q->orderByDesc('id')->limit(200)->get();

        return response()->json([
            'data' => $rows->map(fn (VerificationRequest $v) => $this->serialize($v, true))->values()->all(),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['review_notes' => ['nullable', 'string']]);
        $vr = VerificationRequest::findOrFail($id);
        $vr->status = VerificationRequest::STATUS_APPROVED;
        $vr->review_notes = $validated['review_notes'] ?? null;
        $vr->reviewed_by_user_id = (int) $request->user()->id;
        $vr->reviewed_at = now();
        $vr->save();

        User::where('id', $vr->user_id)->update([
            'is_verified' => true,
            'verified_at' => now(),
            'verified_by_user_id' => (int) $request->user()->id,
        ]);

        return response()->json(['data' => $this->serialize($vr->fresh(['user', 'reviewer']), true)]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['review_notes' => ['nullable', 'string']]);
        $vr = VerificationRequest::findOrFail($id);
        $vr->status = VerificationRequest::STATUS_REJECTED;
        $vr->review_notes = $validated['review_notes'] ?? null;
        $vr->reviewed_by_user_id = (int) $request->user()->id;
        $vr->reviewed_at = now();
        $vr->save();

        return response()->json(['data' => $this->serialize($vr->fresh(['user', 'reviewer']), true)]);
    }

    private function serialize(VerificationRequest $v, bool $withUser = false): array
    {
        $data = [
            'id' => $v->id,
            'status' => $v->status,
            'notes' => $v->notes,
            'review_notes' => $v->review_notes,
            'documents' => $v->documents ?? [],
            'reviewed_at' => $v->reviewed_at?->toIso8601String(),
            'created_at' => $v->created_at?->toIso8601String(),
        ];
        if ($withUser && $v->relationLoaded('user') && $v->user) {
            $data['user'] = [
                'id' => $v->user->id,
                'name' => $v->user->name,
                'email' => $v->user->email,
                'is_verified' => (bool) $v->user->is_verified,
            ];
        }
        if ($withUser && $v->relationLoaded('reviewer') && $v->reviewer) {
            $data['reviewer'] = ['id' => $v->reviewer->id, 'name' => $v->reviewer->name];
        }

        return $data;
    }
}
