<?php

namespace App\Notifications;

use App\Models\EmailTemplate;
use App\Models\Invitation;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationCreated extends Notification
{
    use Queueable;

    public function __construct(
        public Invitation $invitation,
        public ?array $legacyPermissionIds = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->invitation->getLink($this->legacyPermissionIds);
        $vars = EmailTemplateService::varsForInvitation($url, $this->invitation->invite_code);

        return app(EmailTemplateService::class)->mailMessage(EmailTemplate::KEY_INVITATION, $vars);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'invitation',
            'invitation_id' => $this->invitation->id,
        ];
    }
}
