<?php

namespace App\Events;

use App\Models\OutboxMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DomainEventPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly OutboxMessage $message) {}
}
