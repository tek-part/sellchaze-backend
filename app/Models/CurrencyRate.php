<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrencyRate extends Model
{
    protected $fillable = [
        'currency_code',
        'rate_to_usd',
        'source',
        'is_manual_override',
    ];

    protected $casts = [
        'rate_to_usd' => 'decimal:8',
        'is_manual_override' => 'boolean',
    ];
}
