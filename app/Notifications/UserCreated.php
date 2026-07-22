<?php

namespace App\Notifications;

use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserCreated extends Notification
{
    use Queueable;

    protected $user_id;

    /**
     * Create a new user_id instance.
     */
    public function __construct($user_id)
    {
        $this->user_id = $user_id;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return MailMessage
     */
    public function toMail($notifiable)
    {
        $base = rtrim((string) config('sellchase.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');
        $vars = EmailTemplateService::varsForUserWelcome($base.'/settings/profile');

        return app(EmailTemplateService::class)->mailMessage(EmailTemplate::KEY_USER_WELCOME, $vars);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toDatabase($notifiable)
    {
        return [
            'type' => 'user_registered',
            'action' => 'create',
            'user_id' => $this->user_id,
        ];
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
