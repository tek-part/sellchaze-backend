<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesLocale;
use App\Models\CommunityGroup;
use App\Models\Follow;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\User;
use App\Models\UserSafetyRelation;
use App\Support\Feed\FeedQuery;
use App\Support\Feed\PostPresenter;
use App\Support\Feed\UserCardPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Unified community search: posts, reels, people, groups and hashtags in one
 * endpoint, every vertical running through the same visibility pipeline the
 * feed uses.
 *
 * Performance notes (documented, deliberate): body matching is LIKE '%q%' —
 * unindexable, but bounded by min-2-chars, per_page caps, the section caps of
 * type=all, and the tenant-read throttle. Hashtags/usernames match by prefix
 * so their indexes engage. The long-term fix is a FULLTEXT index on
 * posts.body; not part of this batch.
 */
class CommunitySearchController extends Controller
{
    use ResolvesLocale;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'type' => ['sometimes', Rule::in(['all', 'posts', 'reels', 'users', 'groups', 'hashtags'])],
            'per_page' => ['sometimes', 'integer'],
        ]);
        $this->resolveLocale($request);

        $viewer = $request->user();
        $term = addcslashes(trim($data['q']), '\\%_');
        $type = $data['type'] ?? 'all';
        $perPage = min(20, max(3, (int) ($data['per_page'] ?? 10)));

        if ($type === 'all') {
            return response()->json([
                'data' => [
                    'users' => $this->presentUsers($this->userQuery($viewer, $term)->limit(3)->get(), $viewer),
                    'posts' => $this->postQuery($viewer, $term)->limit(5)->get()->map(fn ($p) => PostPresenter::card($p, $viewer->id))->values()->all(),
                    'reels' => $this->reelQuery($viewer, $term)->limit(3)->get()->map(fn ($p) => PostPresenter::card($p, $viewer->id))->values()->all(),
                    'groups' => $this->groupQuery($viewer, $term)->limit(3)->get()->map(fn ($g) => $this->groupCard($g))->values()->all(),
                    'hashtags' => $this->hashtagQuery($term)->limit(5)->get()->map(fn ($t) => $this->hashtagCard($t))->values()->all(),
                ],
                'meta' => ['q' => $data['q']],
            ]);
        }

        $query = match ($type) {
            'posts' => $this->postQuery($viewer, $term),
            'reels' => $this->reelQuery($viewer, $term),
            'users' => $this->userQuery($viewer, $term),
            'groups' => $this->groupQuery($viewer, $term),
            'hashtags' => $this->hashtagQuery($term),
        };

        $rows = $query->paginate($perPage);
        $items = collect($rows->items());

        $presented = match ($type) {
            'posts', 'reels' => $items->map(fn ($p) => PostPresenter::card($p, $viewer->id)),
            'users' => collect($this->presentUsers($items, $viewer)),
            'groups' => $items->map(fn ($g) => $this->groupCard($g)),
            'hashtags' => $items->map(fn ($t) => $this->hashtagCard($t)),
        };

        return response()->json([
            'data' => $presented->values()->all(),
            'meta' => [
                'q' => $data['q'],
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    /** GET /hashtags/{slug}/posts — the page a #tag link opens. Feed-shaped. */
    public function hashtagPosts(Request $request, string $slug): JsonResponse
    {
        $tag = Hashtag::query()->where('slug', $slug)->firstOrFail();
        $viewer = $request->user();
        $this->resolveLocale($request);
        $perPage = min(20, max(5, $request->integer('per_page', 10)));

        $rows = FeedQuery::hydrate(
            Post::query()->published()->visibleTo($viewer)->withoutBlockedFor($viewer)
                ->whereHas('hashtags', fn ($h) => $h->where('hashtags.id', $tag->id)),
            $viewer->id,
        )->orderByDesc('published_at')->paginate($perPage);

        return response()->json([
            'hashtag' => $this->hashtagCard($tag),
            'data' => collect($rows->items())->map(fn ($p) => PostPresenter::card($p, $viewer->id))->values()->all(),
            'meta' => [
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    private function postQuery(User $viewer, string $term): Builder
    {
        return $this->basePostQuery($viewer, $term)
            ->where('format', '!=', 'reel')
            ->orderByDesc('published_at');
    }

    private function reelQuery(User $viewer, string $term): Builder
    {
        return $this->basePostQuery($viewer, $term)
            ->where('format', 'reel')
            ->whereHas('media', fn ($m) => $m->where('kind', 'video')->whereIn('status', ['processing', 'ready']))
            ->orderByRaw('(ranking_score + (likes_count * 0.8) + (comments_count * 1.5) + (shares_count * 2)) DESC')
            ->orderByDesc('published_at');
    }

    private function basePostQuery(User $viewer, string $term): Builder
    {
        return FeedQuery::hydrate(
            Post::query()->published()->visibleTo($viewer)->withoutBlockedFor($viewer),
            $viewer->id,
        )->where(fn (Builder $w) => $w
            ->where('body', 'like', "%{$term}%")
            ->orWhereHas('hashtags', fn ($h) => $h->where('slug', 'like', "{$term}%")->orWhere('label', 'like', "{$term}%")));
    }

    private function userQuery(User $viewer, string $term): Builder
    {
        $iBlocked = UserSafetyRelation::query()->where('actor_user_id', $viewer->id)->where('type', 'block')->pluck('target_user_id');
        $blockedMe = UserSafetyRelation::query()->where('target_user_id', $viewer->id)->where('type', 'block')->pluck('actor_user_id');
        $excluded = $iBlocked->merge($blockedMe)->unique()->all();

        return User::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('pending_approval')->orWhere('pending_approval', false))
            ->whereHas('profile', fn ($p) => $p->where('is_public', true))
            ->when($excluded, fn ($q) => $q->whereNotIn('users.id', $excluded))
            ->with('profile', 'roles')
            ->where(fn ($w) => $w
                ->where('users.name', 'like', "%{$term}%")
                ->orWhereHas('profile', fn ($p) => $p->where('company', 'like', "%{$term}%")->orWhere('username', 'like', "{$term}%")))
            ->orderByDesc('is_verified')
            ->orderByDesc('created_at');
    }

    private function groupQuery(User $viewer, string $term): Builder
    {
        return CommunityGroup::query()
            ->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%"))
            ->withExists(['members as joined' => fn ($m) => $m->where('users.id', $viewer->id)->where('community_group_memberships.status', 'active')])
            ->orderByDesc('members_count');
    }

    private function hashtagQuery(string $term): Builder
    {
        return Hashtag::query()
            ->where(fn ($w) => $w->where('label', 'like', "{$term}%")->orWhere('slug', 'like', "{$term}%"))
            ->orderByDesc('trend_score')
            ->orderByDesc('posts_count');
    }

    /** @return array<int, array<string, mixed>> */
    private function presentUsers($users, User $viewer): array
    {
        $ids = collect($users)->pluck('id')->all();
        $iFollow = Follow::query()->where('follower_id', $viewer->id)->whereIn('followed_id', $ids)->pluck('followed_id')->flip();
        $followMe = Follow::query()->where('followed_id', $viewer->id)->whereIn('follower_id', $ids)->pluck('follower_id')->flip();

        return collect($users)
            ->map(fn (User $u) => UserCardPresenter::make($u, $iFollow->has($u->id), $followMe->has($u->id)))
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private function groupCard(CommunityGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'avatar_url' => $group->avatar_url,
            'members_count' => (int) $group->members_count,
            'privacy' => $group->privacy,
            'is_verified' => (bool) ($group->is_verified ?? false),
            'joined' => (bool) ($group->joined ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function hashtagCard(Hashtag $tag): array
    {
        return [
            'id' => $tag->id,
            'slug' => $tag->slug,
            'label' => $tag->label,
            'posts_count' => (int) $tag->posts_count,
            'trend_score' => (float) $tag->trend_score,
        ];
    }
}
