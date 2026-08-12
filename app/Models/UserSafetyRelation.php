<?php

namespace App\Models;

use App\Services\FeedCache;
use Illuminate\Database\Eloquent\Model;

class UserSafetyRelation extends Model
{
    protected static function booted(): void
    {
        static::saved(fn () => app(FeedCache::class)->flush());
        static::deleted(fn () => app(FeedCache::class)->flush());
    }

    public const TYPES = ['block', 'mute'];

    protected $fillable = ['actor_user_id', 'target_user_id', 'type'];
}
