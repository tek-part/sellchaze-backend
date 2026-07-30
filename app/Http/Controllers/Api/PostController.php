<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\PostShare;
use App\Models\Product;
use App\Services\SubscriptionService;
use App\Support\Feed\PostPresenter;
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
            'body' => ['nullable', 'string', 'max:20000'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'attachments' => ['nullable', 'array', 'max:8'],
            'attachments.*' => ['string', 'max:2048'],
            'meta' => ['nullable', 'array'],
        ]);

        // A post must carry something: body text or an attached product.
        $body = $this->sanitize($data['body'] ?? null);
        if (blank(strip_tags((string) $body)) && empty($data['product_id']) && empty($data['attachments'])) {
            return response()->json(['message' => 'A post needs some text, a product, or an attachment.'], 422);
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
            'sector_id' => $data['sector_id'] ?? $user->primary_sector_id,
            'type' => $data['type'],
            'body' => $body,
            'product_id' => $data['product_id'] ?? null,
            'attachments' => $data['attachments'] ?? null,
            'meta' => $data['meta'] ?? null,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $post->load(Post::FEED_RELATIONS);

        return response()->json(['data' => PostPresenter::card($post, $user->id)], 201);
    }

    public function show(Request $request, Post $post): JsonResponse
    {
        if ($post->status !== 'published' && $post->user_id !== $request->user()?->id) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $post->load(Post::FEED_RELATIONS);
        $post->setAttribute('liked', PostLike::query()->where('post_id', $post->id)->where('user_id', $request->user()?->id)->exists());

        return response()->json(['data' => PostPresenter::card($post, $request->user()?->id)]);
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

    /** Strip the dangerous bits from user rich-text (mirrors ArticlesApiController::sanitize). */
    private function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }
        $clean = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $html);
        $clean = preg_replace('#<style\b[^>]*>(.*?)</style>#is', '', (string) $clean);
        $clean = preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#i', '', (string) $clean);
        $clean = preg_replace("#\son[a-z]+\s*=\s*'[^']*'#i", '', (string) $clean);
        $clean = preg_replace('#javascript\s*:#i', '', (string) $clean);

        return $clean;
    }
}
