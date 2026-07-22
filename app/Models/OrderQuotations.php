<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderQuotations extends Model
{
    use HasFactory;

    protected $hidden = ['updated_at', 'deleted_at'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'price', 'delivery_date', 'notes', 'status', 'order_id',
        'supplier_user_id', 'customer_user_id', 'price_includes_shipping',
        'currency', 'shipping_company', 'tracking_number', 'shipped_at',
    ];

    protected $casts = [
        'price_includes_shipping' => 'boolean',
        'shipped_at' => 'datetime',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<OrderSuppliers, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(OrderSuppliers::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function supplierUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_user_id');
    }
}
