<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 */
class Post extends Model
{
    public const TYPES = ['new_product', 'ad_offer', 'rfq', 'update_news', 'question'];

    /** Relations every feed-card render needs — single source of truth for eager-loading. */
    public const FEED_RELATIONS = ['author.profile', 'sector', 'product'];

    protected $fillable = [
        'user_id', 'sector_id', 'type', 'body', 'product_id',
        'attachments', 'meta', 'status', 'published_at',
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
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /** Eager-load the relations a feed card needs (see {@see Post::FEED_RELATIONS}). */
    public function scopeWithFeedRelations(Builder $query): Builder
    {
        return $query->with(self::FEED_RELATIONS);
    }
}
