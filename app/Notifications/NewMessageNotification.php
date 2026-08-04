<?php

namespace App\Notifications;

use App\Models\Message;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Notify a chat recipient of a new direct message.
 * Channels: database (bell), broadcast (real-time via Pusher), and optionally mail.
 */
class NewMessageNotification extends Notification
{
    use Queueable;

    public function __construct(public Message $message, public bool $email = false)
    {
    }

    public function via(object $notifiable): array
    {
        // Chat alerts surface as an unread badge on the chat button (broadcast),
        // not in the general notifications bell — so we skip the database channel.
        $channels = ['broadcast'];
        if ($this->email) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    private function sender(): ?User
    {
        return $this->message->user ?: User::find($this->message->user_id);
    }

    private function chatUrl(): string
    {
        $base = rtrim((string) config('sellchase.frontend_url', env('FRONTEND_URL', 'https://sellchaze.com')), '/');

        return $base . '/chat?c=' . $this->message->conversation_id;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->sender()?->name ?? 'A Sellchase user';
        $preview = Str::limit((string) $this->message->body, 160);

        return (new MailMessage)
            ->subject("New message from {$name} · Sellchase")
            ->greeting('Hello ' . ($notifiable->name ?? '') . ',')
            ->line("You received a new message from {$name}:")
            ->line('"' . $preview . '"')
            ->action('Open conversation', $this->chatUrl())
            ->line('You are receiving this because you have a Sellchase account.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_message',
            'conversation_id' => $this->message->conversation_id,
            'message_id' => $this->message->id,
            'sender_id' => $this->message->user_id,
            'sender_name' => $this->sender()?->name,
            'preview' => Str::limit((string) $this->message->body, 120),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
