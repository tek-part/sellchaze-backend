<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_user_id',
        'action',
        'event_type',
        'channel',
        'level',
        'subject_type',
        'subject_id',
        'properties',
        'context',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'properties' => 'array',
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
