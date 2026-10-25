<?php

namespace App\Mail;

use App\Models\Store;
use App\Models\StoreCustomer;
use App\Services\Commerce\CustomerPasswordResetService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** The store-branded "reset your password" email for a store customer. */
class StoreCustomerPasswordResetMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Store $store, public StoreCustomer $customer, public string $url) {}

    public function build(): static
    {
        $ar = $this->store->default_locale === 'ar';
        $data = [
            'ar' => $ar,
            'store' => $this->store,
            'logo' => $this->store->logoUrl(),
            'accent' => StoreNotificationMail::accent($this->store),
            'name' => $this->customer->name,
            'url' => $this->url,
            'minutes' => CustomerPasswordResetService::EXPIRES_MINUTES,
        ];

        $mail = $this->from(config('mail.from.address'), $this->store->name)
            ->subject($ar ? 'إعادة تعيين كلمة المرور — '.$this->store->name : 'Reset your password — '.$this->store->name)
            ->view('emails.store-customer-password-reset', $data)
            ->text('emails.store-customer-password-reset-text', $data);
        if (filter_var($this->store->email, FILTER_VALIDATE_EMAIL)) {
            $mail->replyTo($this->store->email, $this->store->name);
        }

        return $mail;
    }
}
