<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketAction extends Model
{
    protected $fillable = ['ticket_id', 'action', 'performed_by', 'notes'];

    public function ticket()
    {
        return $this->belongsTo(OrderTicket::class);
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
