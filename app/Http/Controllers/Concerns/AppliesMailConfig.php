<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;

trait AppliesMailConfig
{
    /**
     * Apply mail config from database so sending uses SMTP from settings.
     */
    protected function applyMailConfigFromSettings(): void
    {
        $mailHost = Setting::get('mail_host');
        if ($mailHost === null || $mailHost === '') {
            return;
        }
        Config::set('mail.default', Setting::get('mail_mailer', 'smtp'));
        Config::set('mail.from.address', Setting::get('mail_from_address', config('mail.from.address')));
        Config::set('mail.from.name', Setting::get('mail_from_name', config('mail.from.name')));
        Config::set('mail.mailers.smtp.host', $mailHost);
        Config::set('mail.mailers.smtp.port', Setting::get('mail_port', 465));
        Config::set('mail.mailers.smtp.username', Setting::get('mail_username'));
        Config::set('mail.mailers.smtp.password', Setting::get('mail_password'));
        $enc = Setting::get('mail_encryption');
        Config::set('mail.mailers.smtp.encryption', $enc === 'null' || $enc === 'none' ? null : $enc);
    }
}
