<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatewayWallet extends Model
{
    protected $fillable = ['gateway_id', 'balance'];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function gateway()
    {
        return $this->belongsTo(PaymentGateway::class);
    }
}
