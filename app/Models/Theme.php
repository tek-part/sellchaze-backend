<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Theme extends Model
{
    public const STATUSES = ['draft', 'review', 'approved', 'published', 'deprecated'];

    protected $fillable = [
        'key', 'name', 'description', 'author', 'category', 'status', 'is_marketplace',
        'is_featured', 'installs_count', 'preview_image', 'latest_version_id',
        'price', 'currency', 'license_type', 'support_days',
    ];

    protected $casts = [
        'is_marketplace' => 'boolean',
        'is_featured' => 'boolean',
        'installs_count' => 'integer',
        'price' => 'decimal:2',
        'support_days' => 'integer',
    ];

    /** @return HasMany<ThemeVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ThemeVersion::class);
    }

    /** @return HasMany<ThemeAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(ThemeAsset::class)->orderBy('position');
    }

    /** @return HasMany<ThemeStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(ThemeStatusChange::class);
    }

    /** @return BelongsTo<ThemeVersion, $this> */
    public function latestVersion(): BelongsTo
    {
        return $this->belongsTo(ThemeVersion::class, 'latest_version_id');
    }
}
