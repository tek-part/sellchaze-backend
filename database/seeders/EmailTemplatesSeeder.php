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
            EmailTemplate::KEY_SUPPLIER_ORDER_ASSIGNED => [
                'subject' => 'You were assigned order {{order_code}}',
                'body_html' => <<<'HTML'
<p style="margin:0 0 12px;">
    You have been assigned to fulfill an order. Please review the details below.
</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;width:100%;font-size:14px;">
    <tr>
        <td style="padding:6px 0;color:#64748b;">Order</td>
        <td style="padding:6px 0;">{{order_code}}</td>
    </tr>
    <tr>
        <td style="padding:6px 0;color:#64748b;">Product</td>
        <td style="padding:6px 0;">{{product_name}}</td>
    </tr>
    <tr>
        <td style="padding:6px 0;color:#64748b;">App</td>
        <td style="padding:6px 0;">{{app_name}}</td>
    </tr>
</table>
HTML,
            ],
            EmailTemplate::KEY_ORDER_CREATED => [
                'subject' => 'New order activity',
                'body_html' => <<<'HTML'
<p style="margin:0 0 12px;">{{greeting}}</p>
<p style="margin:0 0 20px;">{{body}}</p>
<p style="margin:0 0 20px;">
    <a href="{{action_url}}" style="display:inline-block;padding:12px 20px;background:#0f172a;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;">
        {{action_text}}
    </a>
</p>
<p style="margin:0;">{{thanks}}</p>
HTML,
            ],
            EmailTemplate::KEY_QUOTATION_CREATED => [
                'subject' => 'New quotation activity',
                'body_html' => <<<'HTML'
<p style="margin:0 0 12px;">{{greeting}}</p>
<p style="margin:0 0 20px;">{{body}}</p>
<p style="margin:0 0 20px;">
    <a href="{{action_url}}" style="display:inline-block;padding:12px 20px;background:#0f172a;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;">
        {{action_text}}
    </a>
</p>
<p style="margin:0;">{{thanks}}</p>
HTML,
            ],
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
            EmailTemplate::KEY_INVITATION => [
                'subject' => 'Invitation to {{app_name}}',
                'body_html' => <<<'HTML'
<p style="margin:0 0 12px;">Hello!</p>
<p style="margin:0 0 16px;">You have been invited to join {{app_name}}.</p>
<p style="margin:0 0 20px;">
    <a href="{{url}}" style="display:inline-block;padding:12px 20px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;">
        Accept invitation
    </a>
</p>
<p style="margin:0 0 8px;font-size:14px;">Your invite code is: {{invite_code}}</p>
<p style="margin:0;font-size:13px;color:#64748b;">You can also register using this code on the invitation page.</p>
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
            EmailTemplate::KEY_DELIVERY_UPDATED => [
                'subject' => 'Shipping update: order {{order_code}}',
                'body_html' => <<<'HTML'
<p style="margin:0 0 12px;">Shipping update for order {{order_code}}</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;width:100%;font-size:14px;">
    <tr><td style="padding:6px 0;color:#64748b;">Tracking</td><td style="padding:6px 0;">{{tracking}}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b;">Status</td><td style="padding:6px 0;">{{status}}</td></tr>
</table>
<p style="margin:0;">
    <a href="{{tracking_url}}" style="color:#2563eb;">Track shipment</a>
</p>
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
            EmailTemplate::KEY_TICKET_CREATED => [
                'subject' => 'Ticket #{{ticket_id}} - Order {{order_code}} - Action required',
                'body_html' => <<<'HTML'
<h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">Ticket #{{ticket_id}} - Order {{order_code}}</h2>
<p style="margin:0 0 8px;"><strong>Type:</strong> {{ticket_type}}</p>
<p style="margin:0 0 8px;"><strong>Notes:</strong></p>
<div style="padding:16px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">{{ticket_notes}}</div>
<p style="margin:16px 0 0;">Please review and take the required action.</p>
HTML,
            ],
            EmailTemplate::KEY_TICKET_REPLY => [
                'subject' => 'Ticket #{{ticket_id}} - New Reply - Order {{order_code}}',
                'body_html' => <<<'HTML'
<h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">Ticket #{{ticket_id}} - New Reply</h2>
<p style="margin:0 0 8px;">Order: {{order_code}}</p>
<p style="margin:0 0 8px;"><strong>Type:</strong> {{ticket_type}}</p>
<p style="margin:0 0 8px;"><strong>New message:</strong></p>
<div style="padding:16px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">{{message_body}}</div>
<p style="margin:12px 0 0;font-size:13px;color:#64748b;">From: {{sender_name}} — {{message_created_at}}</p>
<p style="margin:16px 0 0;">Please review and reply if needed.</p>
HTML,
            ],
            EmailTemplate::KEY_TICKET_STATUS_CHANGED => [
                'subject' => 'Ticket #{{ticket_id}} - Status Updated - Order {{order_code}}',
                'body_html' => <<<'HTML'
<h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">Ticket #{{ticket_id}} - Status Updated</h2>
<p style="margin:0 0 8px;">Order: {{order_code}}</p>
<p style="margin:0 0 8px;"><strong>Type:</strong> {{ticket_type}}</p>
<p style="margin:0 0 12px;"><strong>Status changed from:</strong> {{old_status}} → {{new_status}}</p>
<p style="margin:0;">Please review the ticket for any required action.</p>
HTML,
            ],
            EmailTemplate::KEY_QUOTATION_DEAL_APPROVED => [
                'subject' => 'Quotation has been successfully approved for order {{refnum}}',
                'body_html' => <<<'HTML'
<h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">Deal confirmed</h2>
<p style="margin:0 0 16px;">Your quotation has been accepted by the customer.</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;width:100%;font-size:14px;border:1px solid #e2e8f0;border-radius:8px;padding:16px;">
    <tr><td style="padding:6px 0;color:#64748b;">Order / Ref</td><td style="padding:6px 0;">{{refnum}}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b;">Supplier</td><td style="padding:6px 0;">{{supplier}}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b;">Customer</td><td style="padding:6px 0;">{{customer}}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b;">Price</td><td style="padding:6px 0;">{{price}}</td></tr>
</table>
<p style="margin:0;font-size:14px;color:#64748b;">Thank you for using {{app_name}}.</p>
HTML,
            ],
        ];
    }
}
