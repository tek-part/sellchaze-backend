<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminModerationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = ContentReport::query()->with('actions')->orderByDesc('id')
            ->paginate(min(100, max(10, $request->integer('per_page', 25))));

        return response()->json(['data' => $rows->items(), 'meta' => [
            'total' => $rows->total(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(),
        ]]);
    }

    public function review(Request $request, ContentReport $report): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['dismiss', 'hide_content', 'warn'])],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        DB::transaction(function () use ($request, $report, $data): void {
            if ($data['action'] === 'hide_content' && $report->target_type === 'post') {
                Post::query()->whereKey($report->target_id)->update(['status' => 'hidden']);
            }
            $report->update([
                'status' => $data['action'] === 'dismiss' ? 'dismissed' : 'actioned',
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
            ModerationAction::query()->create([
                'content_report_id' => $report->id,
                'moderator_user_id' => $request->user()->id,
                'action' => $data['action'],
                'notes' => $data['notes'] ?? null,
                'snapshot' => $report->only(['target_type', 'target_id', 'reason', 'details']),
            ]);
        });

        return response()->json(['data' => $report->fresh('actions')]);
    }

    public function verifyOrganization(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'verified' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($request, $organization, $data): void {
            $organization->update([
                'is_verified' => $data['verified'],
                'verified_at' => $data['verified'] ? now() : null,
                'verified_by_user_id' => $data['verified'] ? $request->user()->id : null,
            ]);
            DB::table('organization_verification_events')->insert([
                'organization_id' => $organization->id,
                'moderator_user_id' => $request->user()->id,
                'verified' => $data['verified'],
                'reason' => $data['reason'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['data' => [
            'organization_id' => $organization->id,
            'is_verified' => (bool) $organization->fresh()->is_verified,
            'verified_at' => $organization->fresh()->verified_at,
        ]]);
    }
}
