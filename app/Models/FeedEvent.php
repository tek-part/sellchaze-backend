<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedEvent extends Model
{
    protected $fillable = ['event_uuid', 'user_id', 'post_id', 'event_type', 'value_ms', 'session_id', 'context', 'occurred_at'];
    protected function casts(): array { return ['context' => 'array', 'occurred_at' => 'datetime']; }
}
