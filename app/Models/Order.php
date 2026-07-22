<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $hidden = ['updated_at', 'deleted_at'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code', 'quantity', 'image', 'product_id', 'user_id', 'assigned_supplier_id', 'attributes',
        'status', 'ref_number', 'notes', 'shipping_address', 'shipping_type',
        'payment_method', 'payment_type', 'paid_amount', 'delivery_path',
        'wigpleasure_order_id', 'wigpleasure_store_status', 'customer_name', 'customer_email', 'customer_phone',
        'shipping_address_json', 'currency', 'payment_transaction_id', 'wigpleasure_products',
    ];

    protected $casts = [
        'paid_amount' => 'decimal:2',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasOne<ProductOrders, $this> */
    public function product_order(): HasOne
    {
        return $this->hasOne(ProductOrders::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<OrderSuppliers, $this> */
    public function suppliers(): HasMany
    {
        return $this->hasMany(OrderSuppliers::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedSupplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_supplier_id');
    }

    /** @return HasMany<OrderQuotations, $this> */
    public function quotations(): HasMany
    {
        return $this->hasMany(OrderQuotations::class);
    }

    /** @return HasMany<OrderDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(OrderDelivery::class);
    }

    /** @return HasMany<OrderTicket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(OrderTicket::class);
    }

    /**
     * Wigpleasure store category id from synced payload (for routing / orders-out UI).
     */
    public function wigpleasureStoreCategoryId(): ?int
    {
        $raw = $this->wigpleasure_products;
        if ($raw === null || $raw === '') {
            return null;
        }
        $arr = is_array($raw) ? $raw : json_decode($raw, true);
        if (! is_array($arr)) {
            return null;
        }
        $pid = (int) $this->product_id;
        foreach ($arr as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($pid > 0 && (int) ($row['product_id'] ?? 0) === $pid) {
                $c = $row['wigpleasure_category_id'] ?? $row['category_id'] ?? null;
                if ($c !== null && (int) $c > 0) {
                    return (int) $c;
                }
            }
        }
        foreach ($arr as $row) {
            if (! is_array($row)) {
                continue;
            }
            $c = $row['wigpleasure_category_id'] ?? $row['category_id'] ?? null;
            if ($c !== null && (int) $c > 0) {
                return (int) $c;
            }
        }

        return null;
    }

    public function merchantIsPurchaseCustomer(User $user): bool
    {
        return $this->suppliers->contains(fn ($row) => (int) $row->customer === (int) $user->id);
    }
}
