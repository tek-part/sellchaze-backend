<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThemeVersion extends Model
{
    public const STATUSES = ['draft', 'review', 'approved', 'published', 'deprecated'];

    protected $fillable = [
        'theme_id', 'version', 'status', 'settings_schema', 'sections_schema', 'templates',
        'bundle_url', 'bundle_disk', 'bundle_path', 'bundle_checksum',
        'min_platform_version', 'max_platform_version', 'supported_features',
        'changelog', 'published_at', 'bundle_integrity', 'bundle_size',
        'manifest_checksum', 'uploaded_by_user_id', 'reviewed_by_user_id',
    ];

    protected $casts = [
        'settings_schema' => 'array',
        'sections_schema' => 'array',
        'templates' => 'array',
        'supported_features' => 'array',
        'published_at' => 'datetime',
    ];

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    public function statusChanges()
    {
        return $this->hasMany(ThemeVersionStatusChange::class);
    }
}
