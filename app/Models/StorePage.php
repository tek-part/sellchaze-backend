<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorePage extends Model
{
    use BelongsToStore;

    public const STATUSES = ['draft', 'published', 'scheduled'];

    protected $fillable = [
        'store_id', 'title', 'slug', 'status', 'template', 'locale',
        'seo', 'publish_at', 'published_at',
    ];

    protected $casts = [
        'seo' => 'array',
        'publish_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    // FK-scoped; opt out of the fail-closed tenant scope for the relation.
    /** @return HasMany<StorePageSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(StorePageSection::class)->withoutGlobalScope(StoreScope::class)->orderBy('position');
    }

    /** @return HasMany<StorePageRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(StorePageRevision::class)->withoutGlobalScope(StoreScope::class)->orderByDesc('revision_number');
    }

    public function isPubliclyVisible(): bool
    {
        if ($this->status === 'published') {
            return true;
        }

        return $this->status === 'scheduled' && $this->publish_at !== null && $this->publish_at->isPast();
    }
}
