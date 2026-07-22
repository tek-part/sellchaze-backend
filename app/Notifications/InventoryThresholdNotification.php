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

class InventoryThresholdNotification extends Notification implements ShouldQueue
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
            return app(NotificationChannelResolver::class)->channels($notifiable, 'inventory_alerts');
        }

        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $type = $this->data['type'] ?? 'inventory_low';
        $key = $type === 'inventory_out'
            ? EmailTemplate::KEY_INVENTORY_OUT
            : EmailTemplate::KEY_INVENTORY_LOW;
        $vars = EmailTemplateService::varsForInventoryAlert($this->data);

        return app(EmailTemplateService::class)->mailMessage($key, $vars);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->data;
    }
}
