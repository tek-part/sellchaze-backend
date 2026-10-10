<?php

namespace App\Mail;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Services\Commerce\BankTransferInstructions;
use App\Services\Commerce\OrderReceipt;
use App\Services\Storefront\StorefrontUrlGenerator;
use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

class DigitalOrderReceiptMail extends Mailable
{
    public function __construct(public Store $store, public StoreOrder $order, private string $messageId) {}

    public function build(): static
    {
        $receipt = app(OrderReceipt::class)->make($this->order);
        $url = app(StorefrontUrlGenerator::class)->publicUrl($this->store, '/order/success?number='.rawurlencode($this->order->order_number).'#receipt='.rawurlencode($receipt['token']));

        return $this->subject(($this->store->default_locale === 'ar' ? 'إيصال طلبك ' : 'Your order receipt ').$this->order->order_number)
            ->view('emails.digital-order-receipt', ['bank' => BankTransferInstructions::forOrder($this->order), 'receiptUrl' => $url, 'ar' => $this->store->default_locale === 'ar'])
            ->withSymfonyMessage(function (Email $message) {
                $message->getHeaders()->addIdHeader('Message-ID', 'receipt-'.$this->messageId.'@sellchaze.com');
                $message->getHeaders()->addTextHeader('X-Mail-Template-Key', 'storefront-digital-receipt');
            });
    }
}
