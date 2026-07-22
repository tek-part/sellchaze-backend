<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class EmailSettingsSeeder extends Seeder
{
    /**
     * Seed email settings for SMTP (mail.wigpleasure.com).
     *
     * @return void
     */
    public function run()
    {
        $settings = [
            'mail_mailer' => 'smtp',
            'mail_host' => 'mail.wigpleasure.com',
            'mail_port' => '465',
            'mail_username' => 'sellchase@wigpleasure.com',
            'mail_password' => 'Tekpart@2024',
            'mail_encryption' => 'ssl',
            'mail_from_address' => 'sellchase@wigpleasure.com',
            'mail_from_name' => config('app.name', 'Sellchase'),
        ];

        foreach ($settings as $key => $value) {
            Setting::set($key, $value);
        }

        Setting::clearCache();
    }
}
