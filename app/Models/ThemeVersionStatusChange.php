<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThemeVersionStatusChange extends Model
{
    protected $fillable = ['theme_version_id', 'from_status', 'to_status', 'actor_id', 'notes'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(ThemeVersion::class, 'theme_version_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
