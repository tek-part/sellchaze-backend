<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    public const KEY_SUPPLIER_ORDER_ASSIGNED = 'supplier_order_assigned';

    public const KEY_ORDER_CREATED = 'order_created';

    public const KEY_QUOTATION_CREATED = 'quotation_created';

    public const KEY_USER_WELCOME = 'user_welcome';

    public const KEY_INVITATION = 'invitation';

    public const KEY_LOGIN_SECURITY = 'login_security';

    public const KEY_DELIVERY_UPDATED = 'delivery_updated';

    public const KEY_INVENTORY_LOW = 'inventory_low';

    public const KEY_INVENTORY_OUT = 'inventory_out';

    public const KEY_AUTH_RESET_PASSWORD = 'auth_reset_password';

    public const KEY_TICKET_CREATED = 'ticket_created';

    public const KEY_TICKET_REPLY = 'ticket_reply';

    public const KEY_TICKET_STATUS_CHANGED = 'ticket_status_changed';

    public const KEY_QUOTATION_DEAL_APPROVED = 'quotation_deal_approved';

    /**
     * @var array<int, string>
     */
    public const KEYS = [
        self::KEY_SUPPLIER_ORDER_ASSIGNED,
        self::KEY_ORDER_CREATED,
        self::KEY_QUOTATION_CREATED,
        self::KEY_USER_WELCOME,
        self::KEY_INVITATION,
        self::KEY_LOGIN_SECURITY,
        self::KEY_DELIVERY_UPDATED,
        self::KEY_INVENTORY_LOW,
        self::KEY_INVENTORY_OUT,
        self::KEY_AUTH_RESET_PASSWORD,
        self::KEY_TICKET_CREATED,
        self::KEY_TICKET_REPLY,
        self::KEY_TICKET_STATUS_CHANGED,
        self::KEY_QUOTATION_DEAL_APPROVED,
    ];

    protected $fillable = [
        'key',
        'subject',
        'body_html',
    ];
}
