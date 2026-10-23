<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\User;
use App\Services\NotificationChannelResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Support ticket activity: to the support team (created / merchant replied) or to the merchant (reply / resolved). */
class SupportTicketNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly SupportTicket $ticket,
        private readonly string $event,
        private readonly bool $forStaff,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User
            ? app(NotificationChannelResolver::class)->channels($notifiable, 'ticket_in_app')
            : ['database'];
    }

    private function path(): string
    {
        return ($this->forStaff ? '/admin/support/' : '/support/').$this->ticket->id;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = match ($this->event) {
            'created' => __('New support ticket :ref', ['ref' => $this->ticket->reference]),
            'resolved' => __('Your support ticket :ref was resolved', ['ref' => $this->ticket->reference]),
            'closed' => __('Your support ticket :ref was closed', ['ref' => $this->ticket->reference]),
            default => __('New reply on support ticket :ref', ['ref' => $this->ticket->reference]),
        };

        return (new MailMessage)
            ->subject($subject)
            ->line($this->ticket->subject)
            ->action(__('Open ticket'), rtrim((string) config('sellchase.frontend_url'), '/').$this->path());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_ticket',
            'event' => $this->event,
            'ticket_id' => $this->ticket->id,
            'reference' => $this->ticket->reference,
            'subject' => $this->ticket->subject,
            'status' => $this->ticket->status,
            'url' => $this->path(),
        ];
    }
}
