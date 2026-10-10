<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class OrderDelivery extends Model
{
    protected static function booted(): void
    {
        static::creating(function (OrderDelivery $delivery) {
            $order = Order::query()->findOrFail($delivery->order_id);
            if (($order->storefrontFulfillment()['requires_shipping'] ?? true) === false) {
                throw ValidationException::withMessages(['order' => 'Digital orders are delivered through the private storefront receipt and cannot be shipped.']);
            }
        });
    }

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
