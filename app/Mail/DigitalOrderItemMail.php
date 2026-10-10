<?php

namespace App\Mail;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Services\Commerce\DigitalDeliverySettings;
use App\Services\Commerce\OrderReceipt;
use App\Services\Storefront\StorefrontUrlGenerator;
use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

class DigitalOrderItemMail extends Mailable
{
    public function __construct(public Store $store, public StoreOrder $order, public StoreOrderItem $item, private array $settings, private string $deliveryId) {}

    public function build(): static
    {
        $variables = DigitalDeliverySettings::variables($this->store, $this->order, $this->item);
        $subject = DigitalDeliverySettings::render($this->settings['email_subject'], $variables);
        $subject = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $subject), 0, 200);
        $body = DigitalDeliverySettings::render($this->settings['email_body'], $variables);
        $receipt = app(OrderReceipt::class)->make($this->order);
        $url = app(StorefrontUrlGenerator::class)->publicUrl($this->store, '/order/success?number='.rawurlencode($this->order->order_number).'#receipt='.rawurlencode($receipt['token']));

        return $this->from(config('mail.from.address'), $this->settings['sender_name'] ?: $this->store->name)->subject($subject)
            ->view('emails.digital-order-item', ['body' => $body, 'receiptUrl' => $url, 'ar' => $this->store->default_locale === 'ar'])
            ->withSymfonyMessage(function (Email $message) {
                $message->getHeaders()->addIdHeader('Message-ID', 'digital-item-'.$this->deliveryId.'@sellchaze.com');
                $message->getHeaders()->addTextHeader('X-Mail-Template-Key', 'storefront-digital-item');
            });
    }
}
