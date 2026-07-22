<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use App\Models\OrderTicket;
use App\Models\TicketMessage;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TicketReplyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public OrderTicket $ticket,
        public TicketMessage $message,
        public string $recipientName
    ) {}

    public function build()
    {
        $vars = EmailTemplateService::varsForTicketReply($this->ticket, $this->message);
        $rendered = app(EmailTemplateService::class)->render(EmailTemplate::KEY_TICKET_REPLY, $vars);

        return $this->subject($rendered['subject'])
            ->view('mail.wrapped-html', [
                'html' => $rendered['html'],
                'mailTitle' => config('app.name'),
            ])
            ->withSymfonyMessage(function ($message) {
                $message->getHeaders()->addTextHeader('X-Mail-Template-Key', EmailTemplate::KEY_TICKET_REPLY);
            });
    }
}
