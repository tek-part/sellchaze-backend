<?php

namespace App\Notifications;

use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderCreated extends Notification
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

        return app(EmailTemplateService::class)->mailMessage(EmailTemplate::KEY_ORDER_CREATED, $vars);
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
            'type' => 'order',
            'action' => 'create',
            'order_id' => $this->order['order_id'],
            'customer_id' => $this->order['customer_id'],
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
