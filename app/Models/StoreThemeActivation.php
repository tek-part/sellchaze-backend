<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreThemeActivation extends Model
{
    protected $fillable = [
        'store_id', 'theme_id', 'theme_version_id', 'settings', 'action',
        'activated_by', 'activated_at', 'rollback_from_version_id', 'rollback_to_version_id',
    ];

    protected $casts = [
        'settings' => 'array',
        'activated_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }
}
