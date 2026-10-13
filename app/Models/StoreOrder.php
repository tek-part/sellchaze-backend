<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Phase 5: a storefront order. Separate from the legacy B2B Order model.
 *
 * @property string $subtotal
 * @property string $shipping_total
 * @property string $discount_total
 * @property string $tax_total
 * @property string $grand_total
 * @property array{source:string,campaign:string}|null $marketing_attribution
 * @property array{code:string,product_id:int}|null $referral_attribution
 * @property Carbon|null $deleted_at
 */
class StoreOrder extends Model
{
    use BelongsToStore, SoftDeletes;

    public const STATUSES = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];

    protected $fillable = [
        'store_id', 'store_customer_id', 'order_number', 'status', 'currency',
        'customer_name', 'customer_email', 'customer_phone', 'shipping_address',
        'customer_phone_normalized',
        'payment_method', 'payment_status', 'payment_reference',
        'subtotal', 'shipping_total', 'discount_total', 'tax_total', 'grand_total', 'notes',
        'placed_at', 'cancelled_at', 'analytics_session_hash', 'marketing_attribution', 'referral_attribution', 'coupon_code',
        'manual_actor_id', 'manual_owner_id', 'manual_request_key', 'manual_request_hash', 'manual_adjustment_total', 'manual_tax_included',
    ];

    protected $hidden = ['customer_phone_normalized', 'analytics_session_hash', 'marketing_attribution', 'referral_attribution', 'manual_request_key', 'manual_request_hash', 'manual_owner_id'];

    protected $casts = [
        'shipping_address' => 'array',
        'marketing_attribution' => 'array',
        'referral_attribution' => 'array',
        'subtotal' => 'decimal:2',
        'shipping_total' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'manual_adjustment_total' => 'decimal:2',
        'manual_tax_included' => 'boolean',
        'placed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** @return HasMany<StoreOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StoreOrderItem::class);
    }

    /** Immutable order snapshots determine shipping even after catalog deletion.
     * @return Builder<StoreOrderItem>
     */
    public function physicalItems(): Builder
    {
        return StoreOrderItem::query()->withoutGlobalScopes()->where('store_id', $this->store_id)
            ->where('store_order_id', $this->id)->whereNull('digital_delivery');
    }

    /** @return array{type:string,requires_shipping:bool,physical_quantity:int,digital_quantity:int} */
    public function fulfillment(): array
    {
        $physical = (int) $this->physicalItems()->sum('quantity');
        $digital = (int) $this->items()->withoutGlobalScopes()->where('store_id', $this->store_id)->whereNotNull('digital_delivery')->sum('quantity');

        return ['type' => $physical > 0 && $digital > 0 ? 'mixed' : ($digital > 0 ? 'digital' : 'physical'),
            'requires_shipping' => $physical > 0 || $digital === 0, 'physical_quantity' => $physical, 'digital_quantity' => $digital];
    }

    public function requiresShipping(): bool
    {
        return $this->fulfillment()['requires_shipping'];
    }

    /** @return HasMany<StoreOrderStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(StoreOrderStatusChange::class)->orderBy('id');
    }

    /** @return HasOne<StoreOrderReview, $this> */
    public function merchantReview(): HasOne
    {
        return $this->hasOne(StoreOrderReview::class);
    }

    /** @return BelongsTo<StoreCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(StoreCustomer::class, 'store_customer_id');
    }

    /**
     * The B2B order bridged from this storefront order (see StorefrontOrderBridge).
     *
     * @return HasOne<Order, $this>
     */
    public function b2bOrder(): HasOne
    {
        return $this->hasOne(Order::class, 'store_order_id');
    }
}
