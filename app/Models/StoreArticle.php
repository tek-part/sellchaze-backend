<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Resolved with an explicit store_id in every editorial and public query. */
class StoreArticle extends Model
{
    protected $fillable = ['store_id', 'slug', 'draft', 'publication', 'publish_at', 'archived', 'version', 'scheduled_publication', 'scheduled_at', 'legacy_positions'];

    protected $casts = [
        'draft' => 'array', 'legacy_positions' => 'array', 'publication' => 'array', 'publish_at' => 'datetime',
        'archived' => 'boolean', 'version' => 'integer', 'scheduled_publication' => 'array', 'scheduled_at' => 'datetime',
    ];

    public function editorialStatus(): string
    {
        if ($this->archived) {
            return 'archived';
        }
        if ($this->scheduled_publication !== null && $this->scheduled_at !== null) {
            return $this->scheduled_at->isFuture() ? 'scheduled' : 'published';
        }
        if ($this->publication === null || $this->publish_at === null) {
            return 'draft';
        }

        return $this->publish_at->isFuture() ? 'scheduled' : 'published';
    }

    /** Scheduled revisions become visible by time, without exposing subsequent draft edits. */
    public function publicSnapshot(): ?array
    {
        if ($this->archived) {
            return null;
        }
        if ($this->scheduled_publication !== null && $this->scheduled_at !== null && ! $this->scheduled_at->isFuture()) {
            return $this->scheduled_publication;
        }

        return $this->publish_at !== null && ! $this->publish_at->isFuture() ? $this->publication : null;
    }
}
