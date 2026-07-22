<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\TicketMessage;
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
                'key' => EmailTemplate::KEY_SUPPLIER_ORDER_ASSIGNED,
                'description' => 'Email when a supplier is assigned to an order.',
                'placeholders' => 'order_code, product_name, app_name',
            ],
            [
                'key' => EmailTemplate::KEY_ORDER_CREATED,
                'description' => 'New order activity (admin/staff).',
                'placeholders' => 'greeting, body, action_url, action_text, thanks',
            ],
            [
                'key' => EmailTemplate::KEY_QUOTATION_CREATED,
                'description' => 'New quotation activity.',
                'placeholders' => 'greeting, body, action_url, action_text, thanks',
            ],
            [
                'key' => EmailTemplate::KEY_USER_WELCOME,
                'description' => 'Welcome email for a newly created user.',
                'placeholders' => 'app_name, profile_url',
            ],
            [
                'key' => EmailTemplate::KEY_INVITATION,
                'description' => 'Invitation to register with an invite link.',
                'placeholders' => 'app_name, url, invite_code',
            ],
            [
                'key' => EmailTemplate::KEY_LOGIN_SECURITY,
                'description' => 'Alert when signing in from a new device (optional mail).',
                'placeholders' => 'user_name, ip, when',
            ],
            [
                'key' => EmailTemplate::KEY_DELIVERY_UPDATED,
                'description' => 'Shipping / delivery update for an order.',
                'placeholders' => 'order_code, tracking, status, tracking_url',
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
            [
                'key' => EmailTemplate::KEY_TICKET_CREATED,
                'description' => 'New support ticket created (supplier / stakeholders).',
                'placeholders' => 'ticket_id, order_code, ticket_type, ticket_notes',
            ],
            [
                'key' => EmailTemplate::KEY_TICKET_REPLY,
                'description' => 'New reply on a ticket.',
                'placeholders' => 'ticket_id, order_code, ticket_type, message_body, sender_name, message_created_at',
            ],
            [
                'key' => EmailTemplate::KEY_TICKET_STATUS_CHANGED,
                'description' => 'Ticket status changed.',
                'placeholders' => 'ticket_id, order_code, ticket_type, old_status, new_status',
            ],
            [
                'key' => EmailTemplate::KEY_QUOTATION_DEAL_APPROVED,
                'description' => 'Quotation accepted / deal confirmed for supplier.',
                'placeholders' => 'refnum, supplier, customer, price, app_name',
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
            EmailTemplate::KEY_SUPPLIER_ORDER_ASSIGNED => [
                'subject' => __('You were assigned order :code', ['code' => $vars['order_code'] ?? '—']),
                'html' => View::make('mail.partials.order-assigned-supplier-body', [
                    'orderCode' => $vars['order_code'] ?? '—',
                    'productName' => $vars['product_name'] ?? '—',
                    'appName' => $vars['app_name'] ?? config('app.name'),
                ])->render(),
            ],
            EmailTemplate::KEY_ORDER_CREATED => [
                'subject' => __('New order activity'),
                'html' => View::make('mail.partials.activity-payload-body', [
                    'greeting' => $vars['greeting'] ?? '',
                    'body' => $vars['body'] ?? '',
                    'action_url' => $vars['action_url'] ?? '#',
                    'action_text' => $vars['action_text'] ?? __('View'),
                    'thanks' => $vars['thanks'] ?? '',
                ])->render(),
            ],
            EmailTemplate::KEY_QUOTATION_CREATED => [
                'subject' => __('New quotation activity'),
                'html' => View::make('mail.partials.activity-payload-body', [
                    'greeting' => $vars['greeting'] ?? '',
                    'body' => $vars['body'] ?? '',
                    'action_url' => $vars['action_url'] ?? '#',
                    'action_text' => $vars['action_text'] ?? __('View'),
                    'thanks' => $vars['thanks'] ?? '',
                ])->render(),
            ],
            EmailTemplate::KEY_USER_WELCOME => [
                'subject' => __('Welcome to :app', ['app' => $vars['app_name'] ?? config('app.name')]),
                'html' => View::make('mail.partials.user-welcome-body', [
                    'app_name' => $vars['app_name'] ?? config('app.name'),
                    'profile_url' => $vars['profile_url'] ?? '',
                ])->render(),
            ],
            EmailTemplate::KEY_INVITATION => [
                'subject' => __('Invitation to :app', ['app' => $vars['app_name'] ?? config('app.name')]),
                'html' => View::make('mail.partials.invitation-body', [
                    'app_name' => $vars['app_name'] ?? config('app.name'),
                    'url' => $vars['url'] ?? '#',
                    'invite_code' => $vars['invite_code'] ?? '',
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
            EmailTemplate::KEY_DELIVERY_UPDATED => [
                'subject' => __('Shipping update: order :code', ['code' => $vars['order_code'] ?? '—']),
                'html' => View::make('mail.partials.delivery-updated-body', [
                    'order_code' => $vars['order_code'] ?? '—',
                    'tracking' => $vars['tracking'] ?? '',
                    'status' => $vars['status'] ?? '',
                    'tracking_url' => $vars['tracking_url'] ?? '',
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
            EmailTemplate::KEY_TICKET_CREATED => [
                'subject' => __('Ticket #:id - Order :code - Action required', [
                    'id' => $vars['ticket_id'] ?? '—',
                    'code' => $vars['order_code'] ?? '—',
                ]),
                'html' => View::make('mail.partials.ticket-created-body', [
                    'ticket_id' => $vars['ticket_id'] ?? '—',
                    'order_code' => $vars['order_code'] ?? '—',
                    'ticket_type' => $vars['ticket_type'] ?? '',
                    'ticket_notes' => $vars['ticket_notes'] ?? '',
                ])->render(),
            ],
            EmailTemplate::KEY_TICKET_REPLY => [
                'subject' => __('Ticket #:id - New Reply - Order :code', [
                    'id' => $vars['ticket_id'] ?? '—',
                    'code' => $vars['order_code'] ?? '—',
                ]),
                'html' => View::make('mail.partials.ticket-reply-body', [
                    'ticket_id' => $vars['ticket_id'] ?? '—',
                    'order_code' => $vars['order_code'] ?? '—',
                    'ticket_type' => $vars['ticket_type'] ?? '',
                    'message_body' => $vars['message_body'] ?? '',
                    'sender_name' => $vars['sender_name'] ?? '',
                    'message_created_at' => $vars['message_created_at'] ?? '',
                ])->render(),
            ],
            EmailTemplate::KEY_TICKET_STATUS_CHANGED => [
                'subject' => __('Ticket #:id - Status Updated - Order :code', [
                    'id' => $vars['ticket_id'] ?? '—',
                    'code' => $vars['order_code'] ?? '—',
                ]),
                'html' => View::make('mail.partials.ticket-status-changed-body', [
                    'ticket_id' => $vars['ticket_id'] ?? '—',
                    'order_code' => $vars['order_code'] ?? '—',
                    'ticket_type' => $vars['ticket_type'] ?? '',
                    'old_status' => $vars['old_status'] ?? '',
                    'new_status' => $vars['new_status'] ?? '',
                ])->render(),
            ],
            EmailTemplate::KEY_QUOTATION_DEAL_APPROVED => [
                'subject' => __('Quotation has been successfully approved for order :ref', [
                    'ref' => $vars['refnum'] ?? '—',
                ]),
                'html' => View::make('mail.partials.quotation-deal-body', [
                    'refnum' => $vars['refnum'] ?? '—',
                    'supplier' => $vars['supplier'] ?? '—',
                    'customer' => $vars['customer'] ?? '—',
                    'price' => $vars['price'] ?? '—',
                    'app_name' => $vars['app_name'] ?? config('app.name'),
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
    public static function varsForSupplierAssigned(Order $order): array
    {
        $order->loadMissing('product');

        return [
            'order_code' => (string) ($order->code ?? ''),
            'product_name' => (string) (optional($order->product)->name ?? '—'),
            'app_name' => (string) config('app.name'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public static function varsForOrderPayload(array $payload): array
    {
        return [
            'greeting' => (string) ($payload['greeting'] ?? ''),
            'body' => (string) ($payload['body'] ?? ''),
            'action_url' => (string) ($payload['actionURL'] ?? '#'),
            'action_text' => (string) ($payload['actionText'] ?? __('View')),
            'thanks' => (string) ($payload['thanks'] ?? ''),
        ];
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
    public static function varsForInvitation(string $url, ?string $inviteCode): array
    {
        return [
            'app_name' => (string) config('app.name'),
            'url' => $url,
            'invite_code' => (string) ($inviteCode ?? ''),
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
    public static function varsForDelivery(array $data): array
    {
        return [
            'order_code' => (string) ($data['order_code'] ?? '—'),
            'tracking' => (string) ($data['tracking'] ?? ''),
            'status' => (string) ($data['status'] ?? ''),
            'tracking_url' => (string) ($data['tracking_url'] ?? ''),
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

    /**
     * @return array<string, string>
     */
    public static function varsForTicketCreated(OrderTicket $ticket): array
    {
        $ticket->loadMissing('order');

        return [
            'ticket_id' => (string) $ticket->id,
            'order_code' => (string) (optional($ticket->order)->code ?? '—'),
            'ticket_type' => ucfirst((string) ($ticket->type ?? '')),
            'ticket_notes' => (string) ($ticket->notes ?? ''),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function varsForTicketReply(OrderTicket $ticket, TicketMessage $message): array
    {
        $ticket->loadMissing('order');
        $message->loadMissing('user');

        return [
            'ticket_id' => (string) $ticket->id,
            'order_code' => (string) (optional($ticket->order)->code ?? '—'),
            'ticket_type' => ucfirst((string) ($ticket->type ?? '')),
            'message_body' => (string) ($message->body ?? ''),
            'sender_name' => (string) (optional($message->user)->name ?? __('System')),
            'message_created_at' => $message->created_at ? $message->created_at->format('d M Y H:i') : '',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function varsForTicketStatusChanged(OrderTicket $ticket, string $oldStatus): array
    {
        $ticket->loadMissing('order');

        return [
            'ticket_id' => (string) $ticket->id,
            'order_code' => (string) (optional($ticket->order)->code ?? '—'),
            'ticket_type' => ucfirst((string) ($ticket->type ?? '')),
            'old_status' => self::formatStatusLabel($oldStatus),
            'new_status' => self::formatStatusLabel($ticket->status),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public static function varsForQuotationDeal(array $data): array
    {
        $price = $data['price'] ?? null;
        $priceStr = $price !== null && $price !== '' ? number_format((float) $price, 2) : '—';

        return [
            'refnum' => (string) ($data['refnum'] ?? ''),
            'supplier' => (string) ($data['supplier'] ?? ''),
            'customer' => (string) ($data['customer'] ?? ''),
            'price' => $priceStr,
            'app_name' => (string) config('app.name'),
        ];
    }

    private static function formatStatusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return '—';
        }

        return str_replace('_', ' ', ucfirst($status));
    }
}
