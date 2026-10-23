<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketMessage extends Model
{
    protected $fillable = ['support_ticket_id', 'user_id', 'is_staff', 'body', 'attachments'];

    protected $casts = [
        'is_staff' => 'boolean',
        // [{path, name, mime, size}] on the private disk; served only through the authorized download route.
        'attachments' => 'array',
    ];

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
