<?php

namespace App\Models;

use App\Services\FeedCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A single feed/wall post. See the create_posts_table migration for field semantics.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $sector_id
 * @property string $type
 * @property string|null $body
 * @property int|null $product_id
 * @property array|null $attachments
 * @property array|null $meta
 * @property int $likes_count
 * @property int $comments_count
 * @property int $shares_count
 * @property string $status
 * @property Carbon|null $published_at
 * @property Carbon|null $scheduled_at
 * @property CommunityGroup|null $communityGroup
 * @property Collection<int, MediaAsset> $media
 */
class Post extends Model
{
    public const TYPES = ['new_product', 'ad_offer', 'rfq', 'update_news', 'question'];

    /** Relations every feed-card render needs — single source of truth for eager-loading.
     *  author.roles rides along so PostPresenter can emit the author's community
     *  role (supplier/merchant) without a per-row Spatie query. */
    public const FEED_RELATIONS = ['author.profile', 'author.roles', 'organization', 'sector', 'product', 'media.variants', 'communityGroup'];

    protected $fillable = [
        'user_id', 'organization_id', 'sector_id', 'type', 'body', 'product_id',
        'attachments', 'meta', 'status', 'published_at', 'edited_at', 'format', 'lifecycle_status',
        'audience', 'community_group_id', 'original_post_id', 'cta_type', 'cta_label',
        'cta_url', 'comments_enabled', 'scheduled_at', 'location_name', 'ranking_score',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'meta' => 'array',
            'likes_count' => 'integer',
            'comments_count' => 'integer',
            'shares_count' => 'integer',
            'published_at' => 'datetime',
            'edited_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'comments_enabled' => 'boolean',
            'ranking_score' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (Post $post) {
            if ($post->wasRecentlyCreated || $post->wasChanged(['body', 'status', 'published_at', 'sector_id', 'format', 'audience', 'community_group_id', 'lifecycle_status', 'comments_enabled', 'cta_type', 'cta_label', 'cta_url', 'location_name'])) {
                app(FeedCache::class)->flush();
            }
        });
        static::deleted(fn () => app(FeedCache::class)->flush());
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Sector, $this> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'sector_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return HasMany<PostLike, $this> */
    public function likes(): HasMany
    {
        return $this->hasMany(PostLike::class);
    }

    /** @return HasMany<PostComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    /** @return HasMany<PostShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(PostShare::class);
    }

    /** @return HasMany<PostSave, $this> */
    public function saves(): HasMany
    {
        return $this->hasMany(PostSave::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(PostReaction::class);
    }

    /** @return BelongsToMany<MediaAsset, $this> */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'post_media')
            ->withPivot(['position', 'role', 'alt_text', 'crop'])
            ->withTimestamps()
            ->orderBy('post_media.position');
    }

    public function hashtags(): BelongsToMany
    {
        return $this->belongsToMany(Hashtag::class, 'post_hashtag');
    }

    /** @return BelongsTo<CommunityGroup, $this> */
    public function communityGroup(): BelongsTo
    {
        return $this->belongsTo(CommunityGroup::class);
    }

    /**
     * The only rows the public surfaces (feed, reels, group walls, ranking)
     * ever see. Equality on lifecycle_status is deliberate: it excludes both
     * 'archived' and any 'scheduled' rows the cron has not released yet.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')->where('lifecycle_status', 'published');
    }

    /** Apply row-level audience rules for every feed/reel query. */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        $sectorIds = $viewer->sectors()->pluck('sectors.id')->push($viewer->primary_sector_id)->filter()->unique()->all();

        return $query->where(function (Builder $visibility) use ($viewer, $sectorIds) {
            $visibility->where('audience', 'public')
                ->orWhere('user_id', $viewer->id)
                ->orWhere(function (Builder $followers) use ($viewer) {
                    $followers->where('audience', 'followers')->whereIn('user_id', Follow::query()->select('followed_id')->where('follower_id', $viewer->id));
                })
                ->orWhere(function (Builder $sector) use ($sectorIds) {
                    $sector->where('audience', 'sector')->whereIn('sector_id', $sectorIds ?: [-1]);
                })
                ->orWhere(function (Builder $group) use ($viewer) {
                    $group->where('audience', 'group')->whereIn('community_group_id', function ($memberships) use ($viewer) {
                        $memberships->select('community_group_id')->from('community_group_memberships')->where('user_id', $viewer->id)->where('status', 'active');
                    });
                });
        });
    }

    /** Eager-load the relations a feed card needs (see {@see Post::FEED_RELATIONS}). */
    public function scopeWithFeedRelations(Builder $query): Builder
    {
        return $query->with(self::FEED_RELATIONS);
    }

    /**
     * Drop posts by authors the viewer blocked/muted, and by authors who
     * blocked the viewer — the same exclusion the feed applies, shared so
     * search and the hashtag page behave identically.
     */
    public function scopeWithoutBlockedFor(Builder $query, User $viewer): Builder
    {
        $hiddenAuthors = UserSafetyRelation::query()
            ->where('actor_user_id', $viewer->id)
            ->whereIn('type', ['block', 'mute'])
            ->pluck('target_user_id');
        $blockedBy = UserSafetyRelation::query()
            ->where('target_user_id', $viewer->id)
            ->where('type', 'block')
            ->pluck('actor_user_id');

        $excluded = $hiddenAuthors->merge($blockedBy)->unique()->all();

        return $excluded ? $query->whereNotIn('user_id', $excluded) : $query;
    }
}
