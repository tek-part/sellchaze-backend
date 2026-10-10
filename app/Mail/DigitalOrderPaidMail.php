<?php

namespace App\Mail;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Services\Commerce\DigitalProducts;
use App\Services\Commerce\OrderReceipt;
use App\Services\Storefront\StorefrontUrlGenerator;
use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

class DigitalOrderPaidMail extends Mailable
{
    public function __construct(public Store $store, public StoreOrder $order, private string $deliveryId) {}

    public function build(): static
    {
        $receipt = app(OrderReceipt::class)->make($this->order);
        $url = app(StorefrontUrlGenerator::class)->publicUrl($this->store, '/order/success?number='.rawurlencode($this->order->order_number).'#receipt='.rawurlencode($receipt['token']));
        $lines = $this->order->items->map(fn ($item) => ['name' => $item->name, 'delivery' => DigitalProducts::present($item, $this->order)])->all();

        return $this->subject(($this->store->default_locale === 'ar' ? 'منتجات طلبك الرقمي ' : 'Your digital order ').$this->order->order_number)
            ->view('emails.digital-order-paid', ['lines' => $lines, 'receiptUrl' => $url, 'ar' => $this->store->default_locale === 'ar'])
            ->withSymfonyMessage(function (Email $message) {
                $message->getHeaders()->addIdHeader('Message-ID', 'digital-'.$this->deliveryId.'@sellchaze.com');
                $message->getHeaders()->addTextHeader('X-Mail-Template-Key', 'storefront-digital-paid');
            });
    }
}
