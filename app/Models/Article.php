<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Article extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'title_ar',
        'excerpt',
        'excerpt_ar',
        'content',
        'content_ar',
        'featured_image',
        'published',
        'published_at',
        'meta_title',
        'meta_title_ar',
        'meta_description',
        'meta_description_ar',
        'created_by',
    ];

    protected $casts = [
        'published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function scopePublished($query)
    {
        return $query->where('published', true)
            ->where(function ($q) {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Return a title/excerpt/content localized to $lang (ar falls back to EN).
     */
    public function localized(string $lang = 'en'): array
    {
        $ar = $lang === 'ar';

        return [
            'title' => $ar && $this->title_ar ? $this->title_ar : $this->title,
            'excerpt' => $ar && $this->excerpt_ar ? $this->excerpt_ar : $this->excerpt,
            'content' => $ar && $this->content_ar ? $this->content_ar : $this->content,
            'meta_title' => $ar && $this->meta_title_ar ? $this->meta_title_ar : $this->meta_title,
            'meta_description' => $ar && $this->meta_description_ar ? $this->meta_description_ar : $this->meta_description,
        ];
    }
}
