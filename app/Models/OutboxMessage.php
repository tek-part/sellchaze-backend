<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'aggregate_type', 'aggregate_id', 'event_type', 'payload', 'metadata',
        'available_at', 'published_at', 'failed_at', 'attempts', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'metadata' => 'array',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
