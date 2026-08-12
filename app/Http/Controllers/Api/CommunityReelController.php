<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\UserSafetyRelation;
use App\Support\Feed\FeedQuery;
use App\Support\Feed\PostPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunityReelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $hidden = UserSafetyRelation::query()->where('actor_user_id', $viewer->id)->whereIn('type', ['block', 'mute'])->pluck('target_user_id');
        $q = FeedQuery::hydrate(Post::query()->published()->visibleTo($viewer), $viewer->id)
            ->where('format', 'reel')->whereHas('media', fn ($m) => $m->where('kind', 'video')->whereIn('status', ['processing', 'ready']))->whereNotIn('user_id', $hidden)
            ->orderByRaw('(ranking_score + (likes_count * 0.8) + (comments_count * 1.5) + (shares_count * 2)) DESC')->orderByDesc('published_at')->orderByDesc('id');
        $rows = $q->cursorPaginate(min(20, max(3, $request->integer('per_page', 8))));

        return response()->json(['data' => collect($rows->items())->map(fn ($post) => PostPresenter::card($post, $viewer->id))->all(), 'meta' => ['next_cursor' => $rows->nextCursor()?->encode()]]);
    }
}
