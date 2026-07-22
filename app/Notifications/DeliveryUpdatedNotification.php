<?php

namespace App\Notifications;

use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\EmailTemplateService;
use App\Services\NotificationChannelResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeliveryUpdatedNotification extends Notification implements ShouldQueue
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
            return app(NotificationChannelResolver::class)->channels($notifiable, 'delivery_updates');
        }

        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vars = EmailTemplateService::varsForDelivery($this->data);

        return app(EmailTemplateService::class)->mailMessage(EmailTemplate::KEY_DELIVERY_UPDATED, $vars);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->data;
    }
}
