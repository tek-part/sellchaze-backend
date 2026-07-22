<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'to',
        'subject',
        'template_key',
        'status',
        'error_message',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
