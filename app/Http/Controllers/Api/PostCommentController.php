<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostComment;
use App\Support\Feed\PostPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comments on a feed post, with one level of threaded replies. Keeps posts.comments_count in sync.
 */
class PostCommentController extends Controller
{
    public function index(Request $request, Post $post): JsonResponse
    {
        // An archived (or moderation-hidden) post keeps its thread readable
        // only for the post's author.
        if (! $this->threadReadable($request, $post)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $viewerId = $request->user()?->id;
        $rows = PostComment::query()
            ->where('post_id', $post->id)
            ->with('author.profile', 'author.roles')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (PostComment $c) => PostPresenter::comment($c, $viewerId, (int) $post->user_id))->values()->all(),
        ]);
    }

    public function store(Request $request, Post $post): JsonResponse
    {
        // Archived posts are frozen — nobody comments, the author included.
        if ($post->status !== 'published' || $post->lifecycle_status !== 'published') {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $post->comments_enabled) {
            return response()->json(['message' => 'Comments are disabled for this post.'], 422);
        }
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer', 'exists:post_comments,id'],
        ]);

        // A reply's parent must belong to the same post.
        if (! empty($data['parent_id'])) {
            $ok = PostComment::query()->where('id', $data['parent_id'])->where('post_id', $post->id)->exists();
            if (! $ok) {
                return response()->json(['message' => 'Invalid parent comment.'], 422);
            }
        }

        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $request->user()->id,
            'parent_id' => $data['parent_id'] ?? null,
            'body' => strip_tags($data['body']),
        ]);
        $post->increment('comments_count');
        $comment->load('author.profile', 'author.roles');

        return response()->json(['data' => PostPresenter::comment($comment, $request->user()->id, (int) $post->user_id)], 201);
    }

    /** Author-only comment edit; the post owner can delete but not rewrite. */
    public function update(Request $request, Post $post, PostComment $comment): JsonResponse
    {
        if ($comment->post_id !== $post->id) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($comment->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $comment->update(['body' => strip_tags($data['body']), 'edited_at' => now()]);
        $comment->load('author.profile', 'author.roles');

        return response()->json(['data' => PostPresenter::comment($comment, $request->user()->id, (int) $post->user_id)]);
    }

    public function destroy(Request $request, Post $post, PostComment $comment): JsonResponse
    {
        if ($comment->post_id !== $post->id) {
            return response()->json(['message' => 'Not found'], 404);
        }
        // Comment author or the post owner can remove a comment.
        if ($comment->user_id !== $request->user()->id && $post->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Count the comment + any replies removed by the cascade, and correct the counter.
        $removed = 1 + PostComment::query()->where('parent_id', $comment->id)->count();
        $comment->delete();
        $post->decrement('comments_count', $removed);
        if ($post->comments_count < 0) {
            $post->update(['comments_count' => 0]);
        }

        return response()->json(['message' => 'Deleted']);
    }

    private function threadReadable(Request $request, Post $post): bool
    {
        $isAuthor = (int) $post->user_id === (int) $request->user()?->id;

        return $isAuthor || ($post->status === 'published' && $post->lifecycle_status !== 'archived');
    }
}
