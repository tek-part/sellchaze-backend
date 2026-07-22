<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WavexInboxMessage extends Model
{
    protected $fillable = [
        'instance_id',
        'chat_id',
        'direction',
        'body',
        'raw',
        'message_at',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'message_at' => 'datetime',
        ];
    }
}
