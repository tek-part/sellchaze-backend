<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $hidden = ['updated_at', 'deleted_at'];

    public const TYPE_ORDER_CHARGE = 'order_charge';

    public const TYPE_MANUAL_PAYMENT = 'manual_payment';

    protected $fillable = ['supplier_user_id', 'customer_user_id', 'type', 'quotation_id', 'orders', 'amount', 'transfer_method', 'image', 'notes'];

    public function quotation()
    {
        return $this->belongsTo(OrderQuotations::class, 'quotation_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function order()
    {
        return $this->hasOne(Order::class);
    }

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_user_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }
}
