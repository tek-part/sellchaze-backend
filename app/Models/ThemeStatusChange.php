<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThemeStatusChange extends Model
{
    protected $fillable = ['theme_id', 'from_status', 'to_status', 'actor_id', 'notes'];

    /** @return BelongsTo<Theme, $this> */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
