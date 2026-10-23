<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class EmailTemplatesSeeder extends Seeder
{
    /**
     * Seed all email template keys with default subject + HTML using {{placeholder}} syntax
     * (same variables documented in EmailTemplateService::definitions()).
     */
    public function run(): void
    {
        if (! Schema::hasTable('email_templates')) {
            return;
        }

        foreach ($this->templates() as $key => $row) {
            EmailTemplate::query()->updateOrCreate(
                ['key' => $key],
                [
                    'subject' => $row['subject'],
                    'body_html' => $row['body_html'],
                ]
            );
        }
    }

    /**
     * @return array<string, array{subject: string, body_html: string}>
     */
    private function templates(): array
    {
        return [
            EmailTemplate::KEY_USER_WELCOME => [
                'subject' => 'Welcome to {{app_name}}',
                'body_html' => <<<'HTML'
<p style="margin:0 0 12px;">Hello!</p>
<p style="margin:0 0 16px;">Welcome to {{app_name}}.</p>
<p style="margin:0;">
    <a href="{{profile_url}}" style="display:inline-block;padding:12px 20px;background:#0f172a;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;">
        View profile
    </a>
</p>
HTML,
            ],
            EmailTemplate::KEY_LOGIN_SECURITY => [
                'subject' => 'New sign-in to your account',
                'body_html' => <<<'HTML'
<p style="margin:0 0 12px;">Hello {{user_name}},</p>
<p style="margin:0 0 16px;">We noticed a sign-in to your account from a new IP address or device.</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;width:100%;font-size:14px;">
    <tr><td style="padding:6px 0;color:#64748b;">IP</td><td style="padding:6px 0;">{{ip}}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b;">When</td><td style="padding:6px 0;">{{when}}</td></tr>
</table>
<p style="margin:0 0 16px;font-size:13px;color:#64748b;">If this was you, you can ignore this email.</p>
<p style="margin:0;font-size:13px;color:#64748b;">If you did not sign in, change your password from your profile and review active sessions.</p>
HTML,
            ],
            EmailTemplate::KEY_INVENTORY_LOW => [
                'subject' => 'Low stock: product #{{product_id}}',
                'body_html' => <<<'HTML'
<p style="margin:0 0 12px;">{{intro_line}}</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;width:100%;font-size:14px;">
    <tr><td style="padding:6px 0;color:#64748b;">Product</td><td style="padding:6px 0;">#{{product_id}}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b;">Warehouse</td><td style="padding:6px 0;">#{{warehouse_id}}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b;">Available</td><td style="padding:6px 0;">{{qty_available}}</td></tr>
</table>
HTML,
            ],
            EmailTemplate::KEY_INVENTORY_OUT => [
                'subject' => 'Out of stock: product #{{product_id}}',
                'body_html' => <<<'HTML'
<p style="margin:0 0 12px;">{{intro_line}}</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;width:100%;font-size:14px;">
    <tr><td style="padding:6px 0;color:#64748b;">Product</td><td style="padding:6px 0;">#{{product_id}}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b;">Warehouse</td><td style="padding:6px 0;">#{{warehouse_id}}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b;">Available</td><td style="padding:6px 0;">{{qty_available}}</td></tr>
</table>
HTML,
            ],
            EmailTemplate::KEY_AUTH_RESET_PASSWORD => [
                'subject' => 'Reset Password Notification',
                'body_html' => <<<'HTML'
<p style="margin:0 0 16px;">Hello!</p>
<p style="margin:0 0 16px;">You are receiving this email because we received a password reset request for your account.</p>
<p style="margin:0 0 22px;">
    <a href="{{reset_url}}" style="display:inline-block;padding:12px 20px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;">
        Reset Password
    </a>
</p>
<p style="margin:0 0 12px;font-size:13px;color:#64748b;">This password reset link will expire in {{expire_minutes}} minutes.</p>
<p style="margin:0;font-size:13px;color:#64748b;">If you did not request a password reset, no further action is required.</p>
HTML,
            ],
        ];
    }
}
