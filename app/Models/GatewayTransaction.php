<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatewayTransaction extends Model
{
    protected $fillable = [
        'gateway_id', 'type', 'amount', 'reference_type',
        'reference_id', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function gateway()
    {
        return $this->belongsTo(PaymentGateway::class);
    }
}
