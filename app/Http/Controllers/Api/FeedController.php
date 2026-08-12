<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesLocale;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Sector;
use App\Models\UserSafetyRelation;
use App\Models\Follow;
use App\Models\OrganizationFollow;
use App\Models\PostReaction;
use App\Services\FeedCache;
use App\Support\Feed\PostPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The community feed. Visible to every registered user. Three scopes:
 *   - scope=all (default): the whole feed, the viewer's own sector floated to the top, then recent.
 *   - scope=mine: only posts in the viewer's sector(s).
 *   - ?sector={slug}: only posts in that sector (and its specialties).
 */
class FeedController extends Controller
{
    use ResolvesLocale;

    public function __construct(private readonly FeedCache $cache) {}

    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $viewerId = $viewer?->id;
        $locale = $this->resolveLocale($request);
        $cacheKey = $this->cache->key($request, (int) $viewerId, $locale);
        if ($cached = $this->cache->get($cacheKey)) {
            return response()->json($cached);
        }

        $q = Post::query()->published()->visibleTo($viewer)
            ->addSelect(['viewer_reaction' => PostReaction::query()->select('type')->whereColumn('post_id', 'posts.id')->where('user_id', $viewerId)->limit(1)])
            ->withCount([
                'reactions as celebrate_reactions_count' => fn ($r) => $r->where('type', 'celebrate'),
                'reactions as insightful_reactions_count' => fn ($r) => $r->where('type', 'insightful'),
                'reactions as support_reactions_count' => fn ($r) => $r->where('type', 'support'),
                'reactions as interested_reactions_count' => fn ($r) => $r->where('type', 'interested'),
            ])
            ->withFeedRelations()
            ->withExists([
                'likes as liked' => fn (Builder $l) => $l->where('user_id', $viewerId),
                'saves as saved' => fn (Builder $s) => $s->where('user_id', $viewerId),
            ]);

        if ($viewerId) {
            $hiddenAuthors = UserSafetyRelation::query()
                ->where('actor_user_id', $viewerId)
                ->whereIn('type', ['block', 'mute'])
                ->pluck('target_user_id');
            $blockedBy = UserSafetyRelation::query()
                ->where('target_user_id', $viewerId)
                ->where('type', 'block')
                ->pluck('actor_user_id');
            $q->whereNotIn('user_id', $hiddenAuthors->merge($blockedBy)->unique()->all());
        }

        $scope = (string) $request->query('scope', 'all');

        if ($request->filled('type')) {
            $q->where('type', $request->string('type'));
        }
        if ($request->filled('format')) {
            $q->where('format', $request->string('format'));
        }
        if ($request->filled('group')) {
            $q->where('community_group_id', $request->integer('group'));
        }

        if ($sectorSlug = $request->query('sector')) {
            $node = Sector::query()->where('slug', $sectorSlug)->first();
            $ids = $node ? $node->children()->pluck('id')->push($node->id)->all() : [-1];
            $q->whereIn('sector_id', $ids)->orderByDesc('published_at');
        } elseif ($scope === 'saved') {
            $q->whereHas('saves', fn (Builder $s) => $s->where('user_id', $viewerId))->orderByDesc('published_at');
        } elseif ($scope === 'following') {
            $followedUsers = Follow::query()->where('follower_id', $viewerId)->pluck('followed_id');
            $followedOrganizations = OrganizationFollow::query()->where('user_id', $viewerId)->pluck('organization_id');
            $q->where(fn (Builder $x) => $x->whereIn('user_id', $followedUsers)->orWhereIn('organization_id', $followedOrganizations))->orderByDesc('published_at');
        } elseif ($scope === 'trending') {
            $q->orderByRaw('(ranking_score + (likes_count * 0.8) + (comments_count * 1.5) + (shares_count * 2)) DESC')->orderByDesc('published_at')->orderByDesc('id');
        } elseif ($scope === 'mine') {
            $ids = $viewer?->sectors()->pluck('sectors.id')->all() ?: [];
            if ($viewer?->primary_sector_id) {
                $ids[] = $viewer->primary_sector_id;
            }
            $q->whereIn('sector_id', array_values(array_unique($ids)) ?: [-1])->orderByDesc('published_at');
        } else {
            // Default "all": your sector first, then everyone else, newest within each band.
            $primary = (int) ($viewer?->primary_sector_id ?? 0);
            if ($primary > 0) {
                $q->orderByRaw('CASE WHEN sector_id = ? THEN 0 ELSE 1 END', [$primary]);
            }
            $q->orderByDesc('published_at');
        }

        $perPage = (int) min(30, max(5, (int) $request->query('per_page', 10)));
        if ($request->has('cursor')) {
            $rows = $q->orderByDesc('id')->cursorPaginate($perPage);

            $payload = [
                'data' => collect($rows->items())->map(fn (Post $p) => PostPresenter::card($p, $viewerId, $locale))->values()->all(),
                'meta' => [
                    'per_page' => $rows->perPage(),
                    'next_cursor' => $rows->nextCursor()?->encode(),
                    'previous_cursor' => $rows->previousCursor()?->encode(),
                ],
            ];
            $this->cache->put($cacheKey, $payload);

            return response()->json($payload);
        }

        $rows = $q->paginate($perPage);

        $payload = [
            'data' => collect($rows->items())->map(fn (Post $p) => PostPresenter::card($p, $viewerId, $locale))->values()->all(),
            'meta' => [
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
            ],
        ];
        $this->cache->put($cacheKey, $payload);

        return response()->json($payload);
    }
}
