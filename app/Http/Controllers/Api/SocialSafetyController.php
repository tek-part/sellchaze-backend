<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Models\UserSafetyRelation;
use App\Services\FeedCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SocialSafetyController extends Controller
{
    public function relate(Request $request, User $user, string $type): JsonResponse
    {
        abort_unless(in_array($type, UserSafetyRelation::TYPES, true), 404);
        abort_if((int) $request->user()->id === (int) $user->id, 422, 'You cannot apply this action to yourself.');
        $relation = UserSafetyRelation::query()->firstOrCreate([
            'actor_user_id' => $request->user()->id,
            'target_user_id' => $user->id,
            'type' => $type,
        ]);

        return response()->json(['data' => ['type' => $type, 'active' => true, 'changed' => $relation->wasRecentlyCreated]]);
    }

    public function unrelate(Request $request, User $user, string $type): JsonResponse
    {
        abort_unless(in_array($type, UserSafetyRelation::TYPES, true), 404);
        UserSafetyRelation::query()->where([
            'actor_user_id' => $request->user()->id,
            'target_user_id' => $user->id,
            'type' => $type,
        ])->delete();
        app(FeedCache::class)->flush();

        return response()->json(['data' => ['type' => $type, 'active' => false]]);
    }

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_type' => ['required', Rule::in(['post', 'comment', 'user', 'organization'])],
            'target_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::in(['spam', 'fraud', 'harassment', 'illegal', 'impersonation', 'other'])],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);
        $exists = match ($data['target_type']) {
            'post' => Post::query()->whereKey($data['target_id'])->exists(),
            'comment' => PostComment::query()->whereKey($data['target_id'])->exists(),
            'user' => User::query()->whereKey($data['target_id'])->exists(),
            'organization' => Organization::query()->whereKey($data['target_id'])->exists(),
        };
        abort_unless($exists, 404);
        $report = ContentReport::query()->updateOrCreate([
            'reporter_user_id' => $request->user()->id,
            'target_type' => $data['target_type'],
            'target_id' => $data['target_id'],
        ], [
            'reason' => $data['reason'],
            'details' => $data['details'] ?? null,
            'status' => 'pending',
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
        ]);

        return response()->json(['data' => $report], 201);
    }
}
