<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use App\Models\OrderTicket;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TicketStatusChangedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public OrderTicket $ticket,
        public string $oldStatus,
        public string $recipientName
    ) {}

    public function build()
    {
        $vars = EmailTemplateService::varsForTicketStatusChanged($this->ticket, $this->oldStatus);
        $rendered = app(EmailTemplateService::class)->render(EmailTemplate::KEY_TICKET_STATUS_CHANGED, $vars);

        return $this->subject($rendered['subject'])
            ->view('mail.wrapped-html', [
                'html' => $rendered['html'],
                'mailTitle' => config('app.name'),
            ])
            ->withSymfonyMessage(function ($message) {
                $message->getHeaders()->addTextHeader('X-Mail-Template-Key', EmailTemplate::KEY_TICKET_STATUS_CHANGED);
            });
    }
}
