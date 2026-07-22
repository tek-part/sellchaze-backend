<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDelivery extends Model
{
    protected $fillable = [
        'order_id', 'segment', 'shipping_company_id', 'delivery_company', 'tracking_number', 'status',
        'cod_amount', 'delivered_at', 'notes',
    ];

    protected $casts = [
        'cod_amount' => 'decimal:2',
        'delivered_at' => 'datetime',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<ShippingCompany, $this> */
    public function shippingCompany(): BelongsTo
    {
        return $this->belongsTo(ShippingCompany::class);
    }
}
