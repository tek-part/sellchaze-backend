<?php

namespace App\Support\Feed;

use App\Models\Post;
use App\Models\PostComment;
use App\Support\ProductImageUrl;
use App\Support\Community\MediaPresenter;

/**
 * Serialises feed posts and comments into the shape the frontend consumes. Kept as a small support
 * class (mirroring the app's inline-JSON style) so FeedController, PostController and
 * PostCommentController share one card contract.
 */
class PostPresenter
{
    /** @return array<string, mixed> */
    public static function card(Post $post, ?int $viewerId, ?string $locale = null): array
    {
        $author = $post->author;
        $profile = $author?->profile;
        $public = $profile && $profile->is_public;

        return [
            'id' => $post->id,
            'type' => $post->type,
            'format' => $post->format ?? 'post',
            'lifecycle_status' => $post->lifecycle_status ?? 'published',
            'audience' => $post->audience ?? 'public',
            'body' => $post->body,
            'attachments' => $post->attachments ?? [],
            'meta' => $post->meta ?? null,
            'cta' => $post->cta_type ? ['type' => $post->cta_type, 'label' => $post->cta_label, 'url' => $post->cta_url] : null,
            'comments_enabled' => (bool) ($post->comments_enabled ?? true),
            'location_name' => $post->location_name,
            'group' => $post->communityGroup ? ['id' => $post->communityGroup->id, 'name' => $post->communityGroup->name, 'slug' => $post->communityGroup->slug] : null,
            'media' => $post->relationLoaded('media') ? $post->media->map(fn ($asset) => [...MediaPresenter::asset($asset), 'position' => $asset->pivot->position, 'role' => $asset->pivot->role, 'alt_text' => $asset->pivot->alt_text])->values()->all() : [],
            'created_at' => ($post->published_at ?? $post->created_at)?->toIso8601String(),
            'author' => $author ? [
                'id' => $author->id,
                'name' => $author->name,
                'company' => $profile?->company,
                'username' => $public ? $profile->username : null,
                'is_verified' => (bool) ($author->is_verified ?? false),
                'photo' => $profile?->photo
                    ? asset('storage/uploads/users/original/'.$profile->photo)
                    : (! empty($author->avatar) ? $author->avatar : null),
            ] : null,
            'acting_as' => $post->organization ? [
                'type' => 'organization',
                'id' => $post->organization->id,
                'name' => $post->organization->name,
                'slug' => $post->organization->slug,
                'logo_url' => $post->organization->logo_url,
                'is_verified' => (bool) $post->organization->is_verified,
            ] : ['type' => 'user'],
            'sector' => $post->sector ? [
                'slug' => $post->sector->slug,
                'name' => $post->sector->nameLocalized($locale),
            ] : null,
            'product' => $post->product ? [
                'id' => $post->product->id,
                'name' => $post->product->name,
                'slug' => $post->product->slug,
                'image' => ProductImageUrl::thumbUrl($post->product->image),
            ] : null,
            'counts' => [
                'likes' => (int) $post->likes_count,
                'comments' => (int) $post->comments_count,
                'shares' => (int) $post->shares_count,
            ],
            'liked' => (bool) ($post->liked ?? false),
            'saved' => (bool) ($post->saved ?? false),
            'reaction' => $post->getAttribute('viewer_reaction'),
            'reaction_summary' => collect(['celebrate', 'insightful', 'support', 'interested'])->mapWithKeys(fn ($type) => [$type => (int) ($post->getAttribute($type.'_reactions_count') ?? 0)])->all(),
            'can_delete' => $viewerId !== null && $viewerId === (int) $post->user_id,
        ];
    }

    /** @return array<string, mixed> */
    public static function comment(PostComment $c, ?int $viewerId): array
    {
        $author = $c->author;
        $profile = $author?->profile;
        $public = $profile && $profile->is_public;

        return [
            'id' => $c->id,
            'parent_id' => $c->parent_id,
            'body' => $c->body,
            'created_at' => $c->created_at?->toIso8601String(),
            'author' => $author ? [
                'id' => $author->id,
                'name' => $author->name,
                'username' => $public ? $profile->username : null,
                'photo' => $profile?->photo
                    ? asset('storage/uploads/users/original/'.$profile->photo)
                    : (! empty($author->avatar) ? $author->avatar : null),
            ] : null,
            'can_delete' => $viewerId !== null && $viewerId === (int) $c->user_id,
        ];
    }
}
