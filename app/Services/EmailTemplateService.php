<?php

namespace App\Services;

use App\Models\EmailTemplate;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\View;

class EmailTemplateService
{
    /**
     * @param  array<string, string>  $vars
     * @return array{subject: string, html: string}
     */
    public function render(string $key, array $vars): array
    {
        $row = EmailTemplate::query()->where('key', $key)->first();

        $default = $this->defaultForKey($key, $vars);
        $subject = $row && $row->subject !== null && $row->subject !== ''
            ? $this->replacePlaceholders($row->subject, $vars)
            : $default['subject'];
        $html = $row && $row->body_html !== null && trim((string) $row->body_html) !== ''
            ? $this->replacePlaceholders($row->body_html, $vars)
            : $default['html'];

        return ['subject' => $subject, 'html' => $html];
    }

    /**
     * Build a MailMessage with wrapped HTML and X-Mail-Template-Key for logging.
     *
     * @param  array<string, string>  $vars
     */
    public function mailMessage(string $key, array $vars): MailMessage
    {
        $rendered = $this->render($key, $vars);

        return (new MailMessage)
            ->subject($rendered['subject'])
            ->view('mail.wrapped-html', [
                'html' => $rendered['html'],
                'mailTitle' => config('app.name'),
            ])
            ->withSymfonyMessage(function ($message) use ($key) {
                $message->getHeaders()->addTextHeader('X-Mail-Template-Key', $key);
            });
    }

    /**
     * @return array<int, array{key: string, description: string, placeholders: string}>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => EmailTemplate::KEY_USER_WELCOME,
                'description' => 'Welcome email for a newly created user.',
                'placeholders' => 'app_name, profile_url',
            ],
            [
                'key' => EmailTemplate::KEY_LOGIN_SECURITY,
                'description' => 'Alert when signing in from a new device (optional mail).',
                'placeholders' => 'user_name, ip, when',
            ],
            [
                'key' => EmailTemplate::KEY_INVENTORY_LOW,
                'description' => 'Low stock alert (available at or below threshold).',
                'placeholders' => 'intro_line, product_id, warehouse_id, qty_available',
            ],
            [
                'key' => EmailTemplate::KEY_INVENTORY_OUT,
                'description' => 'Out of stock alert.',
                'placeholders' => 'intro_line, product_id, warehouse_id, qty_available',
            ],
            [
                'key' => EmailTemplate::KEY_AUTH_RESET_PASSWORD,
                'description' => 'Password reset link email.',
                'placeholders' => 'reset_url, expire_minutes',
            ],
        ];
    }

    /**
     * @param  array<string, string>  $vars
     * @return array{subject: string, html: string}
     */
    private function defaultForKey(string $key, array $vars): array
    {
        return match ($key) {
            EmailTemplate::KEY_USER_WELCOME => [
                'subject' => __('Welcome to :app', ['app' => $vars['app_name'] ?? config('app.name')]),
                'html' => View::make('mail.partials.user-welcome-body', [
                    'app_name' => $vars['app_name'] ?? config('app.name'),
                    'profile_url' => $vars['profile_url'] ?? '',
                ])->render(),
            ],
            EmailTemplate::KEY_LOGIN_SECURITY => [
                'subject' => __('New sign-in to your account'),
                'html' => View::make('mail.partials.login-security-body', [
                    'user_name' => $vars['user_name'] ?? '',
                    'ip' => $vars['ip'] ?? '',
                    'when' => $vars['when'] ?? '',
                ])->render(),
            ],
            EmailTemplate::KEY_INVENTORY_LOW => [
                'subject' => __('Low stock: product #:id', ['id' => $vars['product_id'] ?? '—']),
                'html' => View::make('mail.partials.inventory-alert-body', [
                    'intro_line' => $vars['intro_line'] ?? '',
                    'product_id' => $vars['product_id'] ?? '—',
                    'warehouse_id' => $vars['warehouse_id'] ?? '—',
                    'qty_available' => $vars['qty_available'] ?? '—',
                ])->render(),
            ],
            EmailTemplate::KEY_INVENTORY_OUT => [
                'subject' => __('Out of stock: product #:id', ['id' => $vars['product_id'] ?? '—']),
                'html' => View::make('mail.partials.inventory-alert-body', [
                    'intro_line' => $vars['intro_line'] ?? '',
                    'product_id' => $vars['product_id'] ?? '—',
                    'warehouse_id' => $vars['warehouse_id'] ?? '—',
                    'qty_available' => $vars['qty_available'] ?? '—',
                ])->render(),
            ],
            EmailTemplate::KEY_AUTH_RESET_PASSWORD => [
                'subject' => __('Reset Password Notification'),
                'html' => View::make('mail.partials.auth-reset-password-body', [
                    'reset_url' => $vars['reset_url'] ?? '#',
                    'expire_minutes' => $vars['expire_minutes'] ?? '60',
                ])->render(),
            ],
            default => [
                'subject' => config('app.name'),
                'html' => '<p>'.e(__('Notification')).'</p>',
            ],
        };
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function replacePlaceholders(string $template, array $vars): string
    {
        $out = $template;
        foreach ($vars as $k => $v) {
            $out = str_replace('{{'.$k.'}}', (string) $v, $out);
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function varsForUserWelcome(string $profileUrl): array
    {
        return [
            'app_name' => (string) config('app.name'),
            'profile_url' => $profileUrl,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function varsForLoginSecurity(string $userName, string $ip, string $when): array
    {
        return [
            'user_name' => $userName,
            'ip' => $ip,
            'when' => $when,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public static function varsForInventoryAlert(array $data): array
    {
        $type = $data['type'] ?? 'inventory_low';
        $intro = $type === 'inventory_out'
            ? __('This product is out of stock in a warehouse.')
            : __('Available quantity is at or below the low-stock threshold.');

        return [
            'intro_line' => $intro,
            'product_id' => (string) ($data['product_id'] ?? '—'),
            'warehouse_id' => (string) ($data['warehouse_id'] ?? '—'),
            'qty_available' => isset($data['qty_available']) ? (string) $data['qty_available'] : '—',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function varsForAuthResetPassword(string $resetUrl, int $expireMinutes): array
    {
        return [
            'reset_url' => $resetUrl,
            'expire_minutes' => (string) $expireMinutes,
        ];
    }
}
