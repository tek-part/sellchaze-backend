<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use App\Models\OrderTicket;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TicketCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public OrderTicket $ticket
    ) {}

    public function build()
    {
        $vars = EmailTemplateService::varsForTicketCreated($this->ticket);
        $rendered = app(EmailTemplateService::class)->render(EmailTemplate::KEY_TICKET_CREATED, $vars);

        return $this->subject($rendered['subject'])
            ->view('mail.wrapped-html', [
                'html' => $rendered['html'],
                'mailTitle' => config('app.name'),
            ])
            ->withSymfonyMessage(function ($message) {
                $message->getHeaders()->addTextHeader('X-Mail-Template-Key', EmailTemplate::KEY_TICKET_CREATED);
            });
    }
}
