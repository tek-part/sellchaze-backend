<?php

namespace App\Notifications;

use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\EmailTemplateService;
use App\Services\NotificationChannelResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public bool $isNewDevice,
        public string $ip,
        public string $userAgentShort,
        public string $method
    ) {}

    /**
     * @param  User  $notifiable
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['database'];
        }
        $channels = app(NotificationChannelResolver::class)->channels($notifiable, 'login_security');
        if (! $this->isNewDevice) {
            $channels = array_values(array_filter($channels, fn (string $c) => $c !== 'mail'));
        }

        return $channels;
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $vars = EmailTemplateService::varsForLoginSecurity(
            (string) $notifiable->name,
            $this->ip,
            now()->timezone(config('app.timezone'))->format('Y-m-d H:i')
        );

        return app(EmailTemplateService::class)->mailMessage(EmailTemplate::KEY_LOGIN_SECURITY, $vars);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => $this->isNewDevice ? 'login_new_device' : 'login',
            'ip' => $this->ip,
            'user_agent' => $this->userAgentShort,
            'method' => $this->method,
        ];
    }
}
