<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreThemeRevision extends Model
{
    protected $fillable = [
        'store_theme_id', 'store_id', 'theme_version_id', 'created_by_user_id',
        'source', 'settings', 'checksum',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    /** @return BelongsTo<StoreTheme, $this> */
    public function storeTheme(): BelongsTo
    {
        return $this->belongsTo(StoreTheme::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
