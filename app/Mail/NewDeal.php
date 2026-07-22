<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewDeal extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public array $data) {}

    public function build()
    {
        $vars = EmailTemplateService::varsForQuotationDeal($this->data);
        $rendered = app(EmailTemplateService::class)->render(EmailTemplate::KEY_QUOTATION_DEAL_APPROVED, $vars);

        $address = config('mail.from.address', 'hello@example.com');
        $name = config('mail.from.name', config('app.name'));

        return $this->from($address, $name)
            ->replyTo($address, $name)
            ->subject($rendered['subject'])
            ->view('mail.wrapped-html', [
                'html' => $rendered['html'],
                'mailTitle' => config('app.name'),
            ])
            ->withSymfonyMessage(function ($message) {
                $message->getHeaders()->addTextHeader('X-Mail-Template-Key', EmailTemplate::KEY_QUOTATION_DEAL_APPROVED);
            });
    }
}
