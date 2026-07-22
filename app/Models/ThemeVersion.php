<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThemeVersion extends Model
{
    protected $fillable = [
        'theme_id', 'version', 'settings_schema', 'sections_schema', 'templates',
        'bundle_url', 'min_platform_version', 'max_platform_version', 'supported_features',
        'changelog', 'published_at',
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
}
