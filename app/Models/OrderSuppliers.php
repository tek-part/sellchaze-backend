<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderSuppliers extends Model
{
    use HasFactory;

    protected $hidden = ['updated_at', 'deleted_at'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['order_id', 'customer', 'supplier', 'seen'];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer');
    }

    /** @return BelongsTo<User, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier');
    }

    /** @return HasMany<OrderQuotations, $this> */
    public function quotations(): HasMany
    {
        return $this->hasMany(OrderQuotations::class, 'supplier_user_id', 'supplier');
    }
}
