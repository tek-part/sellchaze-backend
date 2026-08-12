<?php

namespace App\Models;

use App\Services\FeedCache;
use Illuminate\Database\Eloquent\Model;

class PostSave extends Model
{
    protected static function booted(): void
    {
        static::saved(fn () => app(FeedCache::class)->flush());
        static::deleted(fn () => app(FeedCache::class)->flush());
    }

    protected $fillable = ['post_id', 'user_id'];
}
