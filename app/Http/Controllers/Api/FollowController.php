<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Follow;
use App\Models\User;
use App\Models\UserSafetyRelation;
use App\Support\Feed\UserCardPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Company following + "follow these" suggestions for the community feed.
 */
class FollowController extends Controller
{
    /** POST /follows — follow a company. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $me = $request->user();
        if ((int) $data['user_id'] === (int) $me->id) {
            return response()->json(['message' => __('You cannot follow yourself.')], 422);
        }

        Follow::firstOrCreate([
            'follower_id' => $me->id,
            'followed_id' => (int) $data['user_id'],
        ]);

        return response()->json(['following' => true], 201);
    }

    /** DELETE /follows/{user} — unfollow. */
    public function destroy(Request $request, int $user): JsonResponse
    {
        Follow::query()
            ->where('follower_id', $request->user()->id)
            ->where('followed_id', $user)
            ->delete();

        return response()->json(['following' => false]);
    }

    /** GET /me/following — ids the viewer follows (cheap, for UI state). */
    public function following(Request $request): JsonResponse
    {
        $ids = Follow::query()
            ->where('follower_id', $request->user()->id)
            ->pluck('followed_id')
            ->all();

        return response()->json(['following' => $ids]);
    }

    /** GET /users/{user}/followers — who follows this member. */
    public function followersOf(Request $request, User $user): JsonResponse
    {
        return $this->graphList($request, $user, 'followers');
    }

    /** GET /users/{user}/following — who this member follows. */
    public function followingOf(Request $request, User $user): JsonResponse
    {
        return $this->graphList($request, $user, 'following');
    }

    /**
     * Shared follower/following list: paginated user cards, newest follow
     * first, hidden entirely between blocked pairs.
     */
    private function graphList(Request $request, User $user, string $direction): JsonResponse
    {
        $viewer = $request->user();

        if (! ($user->is_active ?? true) || ! empty($user->pending_approval)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        // A block in either direction hides the whole graph.
        $blockedPair = UserSafetyRelation::query()
            ->where('type', 'block')
            ->where(fn ($q) => $q
                ->where(fn ($a) => $a->where('actor_user_id', $viewer->id)->where('target_user_id', $user->id))
                ->orWhere(fn ($b) => $b->where('actor_user_id', $user->id)->where('target_user_id', $viewer->id)))
            ->exists();
        if ($blockedPair && $viewer->id !== $user->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $perPage = min(50, max(5, $request->integer('per_page', 20)));

        $relation = $direction === 'followers' ? $user->followers() : $user->followingUsers();
        $q = $relation->with('profile', 'roles')->where('users.is_active', true);

        // Hide rows the viewer has blocked, and rows that blocked the viewer.
        $iBlocked = UserSafetyRelation::query()->where('actor_user_id', $viewer->id)->where('type', 'block')->pluck('target_user_id');
        $blockedMe = UserSafetyRelation::query()->where('target_user_id', $viewer->id)->where('type', 'block')->pluck('actor_user_id');
        $excluded = $iBlocked->merge($blockedMe)->unique()->all();
        if ($excluded) {
            $q->whereNotIn('users.id', $excluded);
        }

        $rows = $q->orderByDesc('follows.created_at')->paginate($perPage);

        $ids = collect($rows->items())->pluck('id')->all();
        $iFollow = Follow::query()->where('follower_id', $viewer->id)->whereIn('followed_id', $ids)->pluck('followed_id')->flip();
        $followMe = Follow::query()->where('followed_id', $viewer->id)->whereIn('follower_id', $ids)->pluck('follower_id')->flip();

        return response()->json([
            'data' => collect($rows->items())
                ->map(fn (User $u) => UserCardPresenter::make($u, $iFollow->has($u->id), $followMe->has($u->id)))
                ->values()->all(),
            'meta' => [
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    /**
     * GET /me/follow-suggestions — companies in the viewer's sector(s) that they
     * do not already follow. This is the "follow these" block a member sees when
     * first entering the community.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $me = $request->user();
        $limit = (int) min(12, max(3, (int) $request->query('limit', 5)));

        $mySectorIds = DB::table('supplier_sector')->where('user_id', $me->id)->pluck('sector_id')->all();
        $alreadyFollowing = Follow::query()->where('follower_id', $me->id)->pluck('followed_id')->all();
        $exclude = array_merge($alreadyFollowing, [$me->id]);

        $base = User::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('pending_approval')->orWhere('pending_approval', false))
            ->whereNotIn('users.id', $exclude)
            ->with('profile');

        // Prefer companies sharing a sector with the viewer; fall back to any
        // active company so a brand-new member still gets useful suggestions.
        $suggestions = (clone $base)
            ->when($mySectorIds !== [], fn ($q) => $q->whereExists(function ($sub) use ($mySectorIds) {
                $sub->select(DB::raw(1))->from('supplier_sector')
                    ->whereColumn('supplier_sector.user_id', 'users.id')
                    ->whereIn('supplier_sector.sector_id', $mySectorIds);
            }))
            ->orderByDesc('is_verified')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        if ($suggestions->count() < $limit) {
            $fill = (clone $base)
                ->whereNotIn('users.id', $suggestions->pluck('id')->all())
                ->orderByDesc('is_verified')
                ->orderByDesc('created_at')
                ->limit($limit - $suggestions->count())
                ->get();
            $suggestions = $suggestions->concat($fill);
        }

        return response()->json([
            'suggestions' => $suggestions->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->profile?->username,
                'company' => $u->profile?->company,
                'city' => $u->profile?->city,
                'is_verified' => (bool) $u->is_verified,
            ])->values()->all(),
        ]);
    }
}
