<?php

namespace App\Notifications;

use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\EmailTemplateService;
use App\Services\NotificationChannelResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuotationApproved extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(protected array $data) {}

    /**
     * @param  mixed  $notifiable
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        if ($notifiable instanceof User) {
            return app(NotificationChannelResolver::class)->channels($notifiable, 'quotation_approved');
        }
        $channels = ['database'];
        if (! empty($notifiable->email ?? null)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        $vars = EmailTemplateService::varsForQuotationDeal($this->data);

        return app(EmailTemplateService::class)->mailMessage(EmailTemplate::KEY_QUOTATION_DEAL_APPROVED, $vars);
    }

    /**
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'quotation',
            'action' => 'accepted',
            'order_id' => $this->data['order_id'] ?? null,
            'order_code' => $this->data['order_code'] ?? null,
            'quotation_id' => $this->data['quotation_id'] ?? null,
        ];
    }
}
