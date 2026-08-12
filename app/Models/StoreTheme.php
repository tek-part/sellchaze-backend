<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A per-store theme install. Intentionally NOT using BelongsToStore/StoreScope:
 * this is a store-configuration record resolved during store creation and SSR
 * (contexts where the tenant may not yet be set); it is always scoped explicitly
 * by store_id in the service layer.
 */
class StoreTheme extends Model
{
    public const STATUSES = ['installed', 'preview', 'active'];

    protected $fillable = [
        'store_id', 'theme_id', 'theme_version_id', 'settings', 'custom_css',
        'status', 'installed_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'installed_at' => 'datetime',
    ];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<Theme, $this> */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    /** @return BelongsTo<ThemeVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ThemeVersion::class, 'theme_version_id');
    }

    /** @return HasMany<StoreThemeRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(StoreThemeRevision::class);
    }
}
