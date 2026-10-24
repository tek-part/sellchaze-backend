<?php

namespace App\Mail;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Services\Notifications\StoreNotificationSettings;
use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

/** A customer or staff order notification in the store's branded layout with the order summary. */
class StoreNotificationMail extends Mailable
{
    /** @param array{subject: string, body: string} $template */
    public function __construct(
        public Store $store,
        public StoreOrder $order,
        public string $event,
        private array $template,
        private ?string $deliveryId = null,
        private bool $test = false,
    ) {}

    public function build(): static
    {
        $notifications = app(StoreNotificationSettings::class);
        $settings = $notifications->configured($this->store);
        $definition = StoreNotificationSettings::events()[$this->event];
        $staff = $definition['audience'] === 'staff';
        $variables = $notifications->variables($this->store, $this->order, $staff);
        $subject = StoreNotificationSettings::render($this->template['subject'], $variables);
        $subject = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', ($this->test ? '[TEST] ' : '').$subject)), 0, 200);
        $ar = $this->store->default_locale === 'ar';
        $shipment = $notifications->shipment($this->order);
        $items = $this->order->relationLoaded('items') ? $this->order->items : $this->order->items()->get();

        $mail = $this->from(config('mail.from.address'), $settings['sender_name'] ?: $this->store->name)->subject($subject)
            ->view('emails.store-notification', [
                'ar' => $ar,
                'store' => $this->store,
                'logo' => $this->store->logoUrl(),
                'accent' => self::accent($this->store),
                'body' => StoreNotificationSettings::render($this->template['body'], $variables),
                'order' => $this->order,
                'items' => $items,
                'staff' => $staff,
                'orderUrl' => $variables['order_url'],
                'address' => $variables['shipping_address'],
                'payment' => $variables['payment_method'],
                'tracking' => $variables['tracking_number'] !== '' ? ['number' => $variables['tracking_number'], 'carrier' => $variables['carrier'], 'url' => $notifications->trackingUrl($shipment)] : null,
                'money' => fn ($amount) => StoreNotificationSettings::money($amount, $this->order->currency),
            ])
            ->text('emails.store-notification-text', ['body' => StoreNotificationSettings::render($this->template['body'], $variables), 'orderUrl' => $variables['order_url'], 'ar' => $ar]);
        if (filter_var($settings['reply_to'] ?? null, FILTER_VALIDATE_EMAIL)) {
            $mail->replyTo($settings['reply_to'], $this->store->name);
        } elseif (! $staff && filter_var($this->store->email, FILTER_VALIDATE_EMAIL)) {
            $mail->replyTo($this->store->email, $this->store->name);
        }

        return $mail->withSymfonyMessage(function (Email $message) {
            if ($this->deliveryId) {
                $message->getHeaders()->addIdHeader('Message-ID', 'notification-'.$this->deliveryId.'@sellchaze.com');
            }
            $message->getHeaders()->addTextHeader('X-Mail-Template-Key', 'store-notification-'.$this->event);
        });
    }

    /** The store's brand colour when it is a plain hex value, else the platform teal. */
    public static function accent(Store $store): string
    {
        $color = (string) (($store->theme_settings['primary_color'] ?? null) ?: '#073f4b');

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#073f4b';
    }
}
