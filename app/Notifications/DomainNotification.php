<?php

namespace App\Notifications;

use App\Models\StoreDomain;
use App\Models\User;
use App\Services\NotificationChannelResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Shared base for every custom-domain notification.
 *
 * Exists so the six domain notifications share one channel-resolution and
 * database-payload implementation instead of repeating it — subclasses supply
 * only the subject, body lines and event key.
 */
abstract class DomainNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly StoreDomain $domain) {}

    /** Machine-readable key for the database payload and the frontend. */
    abstract public function event(): string;

    abstract public function subject(): string;

    /** @return list<string> */
    abstract public function lines(): array;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof User) {
            // Reuses the existing per-user channel preferences.
            return app(NotificationChannelResolver::class)->channels($notifiable, 'domain_alerts');
        }

        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject());

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'domain',
            'event' => $this->event(),
            'store_id' => $this->domain->store_id,
            'domain_id' => $this->domain->id,
            'host' => $this->domain->host,
            'status' => $this->domain->status,
            'ssl_status' => $this->domain->ssl_status,
            'title' => $this->subject(),
            'message' => $this->lines()[0] ?? '',
        ];
    }
}
