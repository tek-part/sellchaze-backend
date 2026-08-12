<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModerationAction extends Model
{
    protected $fillable = ['content_report_id', 'moderator_user_id', 'action', 'notes', 'snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }
}
