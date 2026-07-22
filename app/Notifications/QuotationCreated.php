<?php

namespace App\Notifications;

use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\EmailTemplateService;
use App\Services\NotificationChannelResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuotationCreated extends Notification
{
    use Queueable;

    /**
     * @var array
     */
    protected $order;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($order)
    {
        $this->order = $order;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        if ($notifiable instanceof User) {
            return app(NotificationChannelResolver::class)->channels($notifiable, 'quotation_activity');
        }

        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return MailMessage
     */
    public function toMail($notifiable)
    {
        $vars = EmailTemplateService::varsForOrderPayload($this->order);

        return app(EmailTemplateService::class)->mailMessage(EmailTemplate::KEY_QUOTATION_CREATED, $vars);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toDatabase($notifiable)
    {
        return [
            'type' => 'quotation',
            'action' => 'create',
            'order_id' => $this->order['order_id'],
            'supplier_id' => $this->order['supplier_id'],
        ];
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
