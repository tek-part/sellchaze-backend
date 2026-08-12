<?php

namespace App\Support\Feed;

use App\Models\PostReaction;
use Illuminate\Database\Eloquent\Builder;

/**
 * The per-viewer hydration every post-card query needs: the viewer's reaction,
 * per-type reaction counts, feed relations, and liked/saved flags. One place,
 * so the feed, reels, post detail, search and hashtag pages all produce the
 * same card shape.
 */
class FeedQuery
{
    public static function hydrate(Builder $query, ?int $viewerId): Builder
    {
        return $query
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
    }
}
