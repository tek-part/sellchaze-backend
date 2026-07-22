<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WavexTemplate extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'body',
        'body_html',
        'media_type',
        'media_path',
        'media_original_name',
        'media_mime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
