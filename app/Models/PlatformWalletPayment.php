<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int $user_id
 * @property int $amount_milli
 * @property string $currency
 * @property string $status
 * @property bool $test_mode
 * @property array{api_key:string,vendor_key:string} $credentials
 * @property int|null $invoice_id
 * @property string|null $invoice_key
 * @property string|null $redirect_url
 * @property int|null $wallet_entry_id
 */
class PlatformWalletPayment extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['credentials', 'invoice_key', 'request_key'];

    protected $casts = ['credentials' => 'encrypted:array', 'test_mode' => 'boolean', 'amount_milli' => 'integer'];
}
