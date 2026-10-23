<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    public const KEY_USER_WELCOME = 'user_welcome';

    public const KEY_LOGIN_SECURITY = 'login_security';

    public const KEY_INVENTORY_LOW = 'inventory_low';

    public const KEY_INVENTORY_OUT = 'inventory_out';

    public const KEY_AUTH_RESET_PASSWORD = 'auth_reset_password';

    /**
     * @var array<int, string>
     */
    public const KEYS = [
        self::KEY_USER_WELCOME,
        self::KEY_LOGIN_SECURITY,
        self::KEY_INVENTORY_LOW,
        self::KEY_INVENTORY_OUT,
        self::KEY_AUTH_RESET_PASSWORD,
    ];

    protected $fillable = [
        'key',
        'subject',
        'body_html',
    ];
}
