<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketMessage extends Model
{
    protected $fillable = ['ticket_id', 'user_id', 'body', 'attachments'];

    protected $casts = [
        'attachments' => 'array',
    ];

    public function ticket()
    {
        return $this->belongsTo(OrderTicket::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
