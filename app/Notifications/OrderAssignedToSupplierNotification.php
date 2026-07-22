<?php

namespace App\Notifications;

use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\User;
use App\Services\EmailTemplateService;
use App\Services\NotificationChannelResolver;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderAssignedToSupplierNotification extends Notification
{
    public function __construct(public Order $order) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof User) {
            return app(NotificationChannelResolver::class)->channels($notifiable, 'order_assigned');
        }

        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->order->loadMissing('product');
        $vars = EmailTemplateService::varsForSupplierAssigned($this->order);

        return app(EmailTemplateService::class)->mailMessage(EmailTemplate::KEY_SUPPLIER_ORDER_ASSIGNED, $vars);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $this->order->loadMissing('product');

        return [
            'type' => 'order_assigned_to_supplier',
            'order_id' => $this->order->id,
            'order_code' => $this->order->code,
            'product_name' => optional($this->order->product)->name,
        ];
    }
}
