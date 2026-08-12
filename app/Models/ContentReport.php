<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentReport extends Model
{
    protected $fillable = [
        'reporter_user_id', 'target_type', 'target_id', 'reason', 'details', 'status',
        'reviewed_by_user_id', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ModerationAction::class);
    }
}
