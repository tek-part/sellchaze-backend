<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\NotificationChannelResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TicketReplyDatabaseNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public array $data) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof User) {
            $ch = app(NotificationChannelResolver::class)->channels($notifiable, 'ticket_in_app');

            return array_values(array_intersect($ch, ['database']));
        }

        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return array_merge(['type' => 'ticket_reply'], $this->data);
    }
}
