<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunityGroup;
use App\Models\Follow;
use App\Models\Hashtag;
use App\Models\MediaAsset;
use App\Models\OrganizationMembership;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\PostReaction;
use App\Models\PostSave;
use App\Models\PostShare;
use App\Models\Product;
use App\Models\User;
use App\Services\FeedCache;
use App\Services\SubscriptionService;
use App\Support\Feed\FeedQuery;
use App\Support\Feed\PostPresenter;
use App\Support\Feed\UserCardPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Feed post lifecycle + engagement: create / view / delete a post, and like/unlike/share it.
 * Posting is open to any authenticated (non-pending) user; Phase 3 layers a monthly quota on store().
 */
class PostController extends Controller
{
    public function store(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $user = $request->user();

        // Free-plan users are capped at N posts/month; paid is unlimited. 402 signals "upgrade".
        if (! $subscriptions->canPost($user)) {
            $quota = $subscriptions->quota($user);

            return response()->json([
                'message' => 'You have reached your monthly post limit. Upgrade to post more.',
                'quota' => $quota,
            ], 402);
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(Post::TYPES)],
            'format' => ['nullable', Rule::in(['post', 'reel', 'carousel'])],
            'body' => ['nullable', 'string', 'max:20000'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'attachments' => ['nullable', 'array', 'max:8'],
            'attachments.*' => ['string', 'max:2048'],
            'meta' => ['nullable', 'array'],
            'acting_organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'media_asset_ids' => ['nullable', 'array', 'max:12'],
            'media_asset_ids.*' => ['integer', 'distinct', 'exists:media_assets,id'],
            'audience' => ['nullable', Rule::in(['public', 'followers', 'sector', 'group'])],
            'community_group_id' => ['nullable', 'integer', 'exists:community_groups,id'],
            'cta_type' => ['nullable', Rule::in(['request_quote', 'contact', 'view_product', 'register', 'apply'])],
            'cta_label' => ['nullable', 'string', 'max:120'],
            'cta_url' => ['nullable', 'url', 'max:2048'],
            'comments_enabled' => ['nullable', 'boolean'],
            'location_name' => ['nullable', 'string', 'max:160'],
            'hashtags' => ['nullable', 'array', 'max:10'],
            'hashtags.*' => ['string', 'max:100'],
        ]);

        $organizationId = isset($data['acting_organization_id']) ? (int) $data['acting_organization_id'] : null;
        if ($organizationId !== null) {
            $membership = OrganizationMembership::query()
                ->where('organization_id', $organizationId)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->first();
            $allowed = $membership && (
                in_array($membership->role, ['owner', 'admin'], true)
                || in_array('post_as_company', $membership->permissions ?? [], true)
            );
            if (! $allowed) {
                return response()->json(['message' => 'You cannot publish as this organization.'], 403);
            }
        }

        if (! empty($data['community_group_id'])) {
            $group = CommunityGroup::query()->findOrFail($data['community_group_id']);
            $isMember = $group->members()->where('users.id', $user->id)->wherePivot('status', 'active')->exists();
            if (! $isMember) {
                return response()->json(['message' => 'Join the group before publishing in it.'], 403);
            }
            $data['audience'] = 'group';
        }

        // A post must carry something: body text or an attached product.
        $body = $this->sanitize($data['body'] ?? null);
        if (blank(strip_tags((string) $body)) && empty($data['product_id']) && empty($data['attachments']) && empty($data['media_asset_ids'])) {
            return response()->json(['message' => 'A post needs some text, a product, or an attachment.'], 422);
        }

        $media = collect();
        if (! empty($data['media_asset_ids'])) {
            $media = MediaAsset::query()->whereIn('id', $data['media_asset_ids'])->get();
            if ($media->count() !== count($data['media_asset_ids']) || $media->contains(fn (MediaAsset $asset) => (int) $asset->user_id !== (int) $user->id || ! in_array($asset->status, ['uploaded', 'processing', 'ready'], true))) {
                return response()->json(['message' => 'Every media asset must be owned by you and fully uploaded.'], 422);
            }
            if (($data['format'] ?? 'post') === 'reel' && ! $media->contains(fn (MediaAsset $asset) => $asset->kind === 'video')) {
                return response()->json(['message' => 'A reel requires a video.'], 422);
            }
        }
        // A carousel is a swipeable image album — it needs at least two images.
        if (($data['format'] ?? 'post') === 'carousel' && $media->filter(fn (MediaAsset $asset) => $asset->kind === 'image')->count() < 2) {
            return response()->json(['message' => 'A carousel requires at least two images.'], 422);
        }

        // Only allow attaching a product the author actually owns.
        if (! empty($data['product_id'])) {
            $owns = Product::query()->where('id', $data['product_id'])->where('user_id', $user->id)->exists();
            if (! $owns) {
                return response()->json(['message' => 'You can only attach your own products.'], 422);
            }
        }

        $post = Post::create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'sector_id' => $data['sector_id'] ?? $user->primary_sector_id,
            'type' => $data['type'],
            'format' => $data['format'] ?? 'post',
            'body' => $body,
            'product_id' => $data['product_id'] ?? null,
            'attachments' => $data['attachments'] ?? null,
            'meta' => $data['meta'] ?? null,
            'status' => 'published',
            'published_at' => now(),
            'lifecycle_status' => 'published',
            'audience' => $data['audience'] ?? 'public',
            'community_group_id' => $data['community_group_id'] ?? null,
            'cta_type' => $data['cta_type'] ?? null,
            'cta_label' => $data['cta_label'] ?? null,
            'cta_url' => $data['cta_url'] ?? null,
            'comments_enabled' => $data['comments_enabled'] ?? true,
            'location_name' => $data['location_name'] ?? null,
        ]);

        if ($media->isNotEmpty()) {
            $post->media()->sync($media->values()->mapWithKeys(fn (MediaAsset $asset, int $position) => [$asset->id => ['position' => $position]])->all());
        }
        $this->syncHashtags($post, $data['hashtags'] ?? [], $body);

        if ($post->community_group_id) {
            CommunityGroup::query()->whereKey($post->community_group_id)->increment('posts_count');
        }

        $post->load(Post::FEED_RELATIONS);

        return response()->json(['data' => PostPresenter::card($post, $user->id)], 201);
    }

    public function show(Request $request, Post $post): JsonResponse
    {
        if (! $this->isViewable($request, $post)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $viewerId = $request->user()?->id;
        // Re-fetch through the shared hydration so the detail card carries the
        // same truthful liked/saved/reaction state as a feed card.
        $post = FeedQuery::hydrate(Post::query()->whereKey($post->id), $viewerId)->firstOrFail();

        return response()->json(['data' => PostPresenter::card($post, $viewerId)]);
    }

    /**
     * Author-only edit. Content changes stamp `edited_at`; archiving or
     * toggling comments does not. Archiving is `lifecycle_status: archived`
     * through this same endpoint — the scopePublished tightening hides the
     * post everywhere public while the author keeps full access.
     */
    public function update(Request $request, Post $post): JsonResponse
    {
        $user = $request->user();
        if ($post->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        // A moderation-hidden post must not be editable back into sight.
        if ($post->status === 'hidden') {
            return response()->json(['message' => 'This post is under moderation.'], 403);
        }

        $data = $request->validate([
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'audience' => ['sometimes', Rule::in(['public', 'followers', 'sector'])],
            'comments_enabled' => ['sometimes', 'boolean'],
            'cta_type' => ['sometimes', 'nullable', Rule::in(['request_quote', 'contact', 'view_product', 'register', 'apply'])],
            'cta_label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'cta_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'location_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'type' => ['sometimes', Rule::in(Post::TYPES)],
            'hashtags' => ['sometimes', 'array', 'max:10'],
            'hashtags.*' => ['string', 'max:100'],
            'lifecycle_status' => ['sometimes', Rule::in(['published', 'archived'])],
        ]);

        if (array_key_exists('audience', $data) && $post->audience === 'group') {
            return response()->json(['message' => 'Group posts keep their group audience.'], 422);
        }
        if (($data['audience'] ?? null) === 'sector' && ! $post->sector_id) {
            return response()->json(['message' => 'This post has no sector to restrict to.'], 422);
        }
        if (array_key_exists('lifecycle_status', $data) && $post->lifecycle_status === 'scheduled') {
            return response()->json(['message' => 'Scheduled posts cannot be archived before publishing.'], 422);
        }

        $bodyChanged = array_key_exists('body', $data);
        if ($bodyChanged) {
            $data['body'] = $this->sanitize($data['body']);
            if (blank(strip_tags((string) $data['body'])) && ! $post->product_id && empty($post->attachments) && ! $post->media()->exists()) {
                return response()->json(['message' => 'A post needs some text, a product, or an attachment.'], 422);
            }
        }

        $hashtags = $data['hashtags'] ?? [];
        unset($data['hashtags']);

        $post->fill($data);
        if ($post->isDirty(['body', 'type', 'audience', 'cta_type', 'cta_label', 'cta_url', 'location_name'])) {
            $post->edited_at = now();
        }
        $post->save();

        if ($bodyChanged) {
            $this->syncHashtags($post, $hashtags, $post->body);
        }

        $fresh = FeedQuery::hydrate(Post::query()->whereKey($post->id), $user->id)->firstOrFail();

        return response()->json(['data' => PostPresenter::card($fresh, $user->id)]);
    }

    /**
     * Who liked / reacted: one paginated list unioning the two systems, with a
     * per-type summary for the viewer dialog's header chips.
     */
    public function reactions(Request $request, Post $post): JsonResponse
    {
        if (! $this->isViewable($request, $post)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $data = $request->validate([
            'type' => ['sometimes', Rule::in(['like', 'celebrate', 'insightful', 'support', 'interested'])],
            'per_page' => ['sometimes', 'integer'],
        ]);
        $perPage = min(50, max(5, (int) ($data['per_page'] ?? 20)));
        $type = $data['type'] ?? null;

        $likes = DB::table('post_likes')->where('post_id', $post->id)
            ->select('user_id', DB::raw("'like' as type"), 'created_at');
        $reactions = DB::table('post_reactions')->where('post_id', $post->id)
            ->select('user_id', 'type', 'created_at');

        $source = match (true) {
            $type === 'like' => $likes,
            $type !== null => $reactions->where('type', $type),
            default => $likes->unionAll($reactions),
        };

        $rows = DB::query()->fromSub($source, 'r')->orderByDesc('created_at')->paginate($perPage);

        $viewerId = (int) $request->user()->id;
        $users = User::query()->with('profile', 'roles')->whereIn('id', collect($rows->items())->pluck('user_id'))->get()->keyBy('id');
        $ids = $users->keys()->all();
        $iFollow = Follow::query()->where('follower_id', $viewerId)->whereIn('followed_id', $ids)->pluck('followed_id')->flip();
        $followMe = Follow::query()->where('followed_id', $viewerId)->whereIn('follower_id', $ids)->pluck('follower_id')->flip();

        $summary = $post->reactions()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');
        $summary['like'] = (int) $post->likes_count;

        return response()->json([
            'data' => collect($rows->items())
                ->filter(fn ($row) => $users->has($row->user_id))
                ->map(fn ($row) => [
                    'user' => UserCardPresenter::make($users[$row->user_id], $iFollow->has($row->user_id), $followMe->has($row->user_id)),
                    'type' => $row->type,
                    'reacted_at' => $row->created_at,
                ])->values()->all(),
            'meta' => [
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'summary' => $summary,
            ],
        ]);
    }

    /** Shared visibility gate for show()/reactions(): published, not archived (author excepted), audience allows the viewer. */
    private function isViewable(Request $request, Post $post): bool
    {
        $isAuthor = (int) $post->user_id === (int) $request->user()?->id;
        if (($post->status !== 'published' || $post->lifecycle_status === 'archived') && ! $isAuthor) {
            return false;
        }

        return $this->canView($request, $post);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        if ($post->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $post->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function like(Request $request, Post $post): JsonResponse
    {
        $created = false;
        DB::transaction(function () use ($request, $post, &$created) {
            $like = PostLike::firstOrCreate(['post_id' => $post->id, 'user_id' => $request->user()->id]);
            if ($like->wasRecentlyCreated) {
                $post->increment('likes_count');
                $created = true;
            }
        });

        return response()->json(['liked' => true, 'likes_count' => (int) $post->refresh()->likes_count, 'changed' => $created]);
    }

    public function unlike(Request $request, Post $post): JsonResponse
    {
        $deleted = PostLike::query()->where('post_id', $post->id)->where('user_id', $request->user()->id)->delete();
        if ($deleted) {
            $post->decrement('likes_count');
            if ($post->likes_count < 0) {
                $post->update(['likes_count' => 0]);
            }
        }

        return response()->json(['liked' => false, 'likes_count' => (int) $post->refresh()->likes_count]);
    }

    public function share(Request $request, Post $post): JsonResponse
    {
        $data = $request->validate(['caption' => ['nullable', 'string', 'max:500']]);
        PostShare::create(['post_id' => $post->id, 'user_id' => $request->user()->id, 'caption' => $data['caption'] ?? null]);
        $post->increment('shares_count');

        return response()->json(['shares_count' => (int) $post->refresh()->shares_count]);
    }

    public function save(Request $request, Post $post): JsonResponse
    {
        $save = PostSave::query()->firstOrCreate([
            'post_id' => $post->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['saved' => true, 'changed' => $save->wasRecentlyCreated]);
    }

    public function unsave(Request $request, Post $post): JsonResponse
    {
        PostSave::query()->where('post_id', $post->id)->where('user_id', $request->user()->id)->delete();
        app(FeedCache::class)->flush();

        return response()->json(['saved' => false]);
    }

    public function react(Request $request, Post $post): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(['celebrate', 'insightful', 'support', 'interested'])]]);
        PostReaction::query()->updateOrCreate(['post_id' => $post->id, 'user_id' => $request->user()->id], ['type' => $data['type']]);
        app(FeedCache::class)->flush();

        return response()->json(['reaction' => $data['type'], 'summary' => $post->reactions()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type')]);
    }

    public function unreact(Request $request, Post $post): JsonResponse
    {
        PostReaction::query()->where('post_id', $post->id)->where('user_id', $request->user()->id)->delete();
        app(FeedCache::class)->flush();

        return response()->json(['reaction' => null, 'summary' => $post->reactions()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type')]);
    }

    /** Strip the dangerous bits from user rich-text (mirrors ArticlesApiController::sanitize). */
    private function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }
        $clean = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><blockquote><a><h2><h3>');
        $clean = preg_replace('#<style\b[^>]*>(.*?)</style>#is', '', (string) $clean);
        $clean = preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#i', '', (string) $clean);
        $clean = preg_replace("#\son[a-z]+\s*=\s*'[^']*'#i", '', (string) $clean);
        $clean = preg_replace('#javascript\s*:#i', '', (string) $clean);

        return $clean;
    }

    private function canView(Request $request, Post $post): bool
    {
        $viewer = $request->user();
        if ((int) $post->user_id === (int) $viewer->id || $post->audience === 'public') {
            return true;
        }
        if ($post->audience === 'followers') {
            return Follow::query()->where('follower_id', $viewer->id)->where('followed_id', $post->user_id)->exists();
        }
        if ($post->audience === 'sector') {
            return $post->sector_id && in_array((int) $post->sector_id, $viewer->sectors()->pluck('sectors.id')->map(fn ($id) => (int) $id)->push((int) $viewer->primary_sector_id)->all(), true);
        }
        if ($post->audience === 'group' && $post->community_group_id) {
            return CommunityGroup::query()->whereKey($post->community_group_id)->whereHas('members', fn ($q) => $q->where('users.id', $viewer->id)->where('community_group_memberships.status', 'active'))->exists();
        }

        return false;
    }

    private function syncHashtags(Post $post, array $explicit, ?string $body): void
    {
        preg_match_all('/(?:^|\s)#([\p{L}\p{N}_-]{2,100})/u', strip_tags((string) $body), $matches);
        $labels = collect([...$explicit, ...($matches[1] ?? [])])->map(fn ($tag) => ltrim(trim((string) $tag), '#'))->filter()->unique(fn ($tag) => mb_strtolower($tag))->take(10);
        $ids = $labels->map(function (string $label) {
            $slug = mb_strtolower(preg_replace('/[^\p{L}\p{N}_-]+/u', '-', $label), 'UTF-8');

            return Hashtag::query()->firstOrCreate(['slug' => trim($slug, '-')], ['label' => $label])->id;
        });
        $post->hashtags()->sync($ids->all());
        Hashtag::query()->whereIn('id', $ids)->each(fn (Hashtag $tag) => $tag->update(['posts_count' => $tag->posts()->count()]));
    }
}
