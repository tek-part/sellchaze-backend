<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Follow;
use App\Models\User;
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
