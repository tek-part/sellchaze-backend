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
 * @property string $refunded_total
 * @property string $gift_card_total
 * @property list<array{gift_card_id:int,last4:string,amount:string}>|null $gift_card_details
 * @property string $fulfillment_status
 * @property array{version:int,gateway:string,currency:string,rule:array,base_total:string,amount:string}|null $payment_adjustment
 * @property array{source:string,campaign:string}|null $marketing_attribution
 * @property array{code:string,product_id:int}|null $referral_attribution
 * @property bool|null $checkout_bot_verified
 * @property Carbon|null $deleted_at
 */
class StoreOrder extends Model
{
    use BelongsToStore, SoftDeletes;

    public const STATUSES = ['pending', 'confirmed', 'processing', 'waiting_for_pickup', 'shipped', 'delivered', 'cancelled',
        'returning_from_delivery', 'request_refund', 'refund_in_progress', 'refunded'];

    /** Physical return tracking; payment refunds and stock reconciliation are separate operations. */
    public const RETURN_STATUSES = ['returning_from_delivery', 'request_refund', 'refund_in_progress', 'refunded'];

    /** Shipping progress over physical lines; digital-only orders are fulfilled once delivered. */
    public const FULFILLMENT_STATUSES = ['unfulfilled', 'partial', 'fulfilled'];

    /** Payment states. Refund states are only ever set by StoreOrderRefunds (recorded money, never a gateway call). */
    public const PAYMENT_STATUSES = ['pending', 'paid', 'failed', 'partially_refunded', 'refunded'];

    protected $fillable = [
        'store_id', 'store_customer_id', 'order_number', 'status', 'currency',
        'customer_name', 'customer_email', 'customer_phone', 'shipping_address',
        'customer_phone_normalized',
        'payment_method', 'payment_status', 'payment_reference',
        'payment_adjustment',
        'subtotal', 'shipping_total', 'discount_total', 'tax_total', 'grand_total', 'refunded_total', 'gift_card_total', 'gift_card_details', 'notes',
        'placed_at', 'cancelled_at', 'analytics_session_hash', 'marketing_attribution', 'referral_attribution', 'coupon_code',
        'discount_details', 'fulfillment_status', 'manual_actor_id', 'manual_owner_id', 'manual_request_key', 'manual_request_hash', 'manual_adjustment_total', 'manual_tax_included',
    ];

    protected $hidden = ['customer_phone_normalized', 'analytics_session_hash', 'marketing_attribution', 'referral_attribution', 'manual_request_key', 'manual_request_hash', 'manual_owner_id', 'checkout_bot_verified', 'retained_bot_verified', 'protection_signals'];

    protected $casts = [
        'shipping_address' => 'array',
        'payment_adjustment' => 'array',
        'discount_details' => 'array',
        'protection_signals' => 'array',
        'marketing_attribution' => 'array',
        'referral_attribution' => 'array',
        'subtotal' => 'decimal:2',
        'shipping_total' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'refunded_total' => 'decimal:2',
        'gift_card_total' => 'decimal:2',
        'gift_card_details' => 'array',
        'manual_adjustment_total' => 'decimal:2',
        'manual_tax_included' => 'boolean',
        'checkout_bot_verified' => 'boolean',
        'placed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** What is left to pay after gift cards: grand_total stays the order total. */
    public function amountDue(): string
    {
        $due = bcsub((string) $this->grand_total, (string) ($this->gift_card_total ?? '0.00'), 2);

        return bccomp($due, '0', 2) < 0 ? '0.00' : $due;
    }

    /** @return HasMany<StoreOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StoreOrderItem::class)->whereNull('superseded_at');
    }

    /** Immutable order snapshots determine shipping even after catalog deletion.
     * @return Builder<StoreOrderItem>
     */
    public function physicalItems(): Builder
    {
        return StoreOrderItem::query()->withoutGlobalScopes()->where('store_id', $this->store_id)
            ->where('store_order_id', $this->id)->whereNull('superseded_at')->whereNull('digital_delivery');
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

    /** @return HasMany<StoreBotChallenge, $this> */
    public function botChallenges(): HasMany
    {
        return $this->hasMany(StoreBotChallenge::class);
    }

    /** Project retained proof on merchant rows without loading challenge secrets or adding per-row queries. */
    public function scopeWithBotVerification(Builder $query): Builder
    {
        return $query->withExists(['botChallenges as retained_bot_verified' => fn (Builder $proof) => $proof
            ->where('state', 'used')->whereColumn('store_bot_challenges.store_id', 'store_orders.store_id')]);
    }

    public function merchantBotVerified(): ?bool
    {
        if ($this->checkout_bot_verified !== null) {
            return $this->checkout_bot_verified;
        }
        $retained = array_key_exists('retained_bot_verified', $this->attributes)
            ? (bool) $this->attributes['retained_bot_verified']
            : $this->botChallenges()->where('store_id', $this->store_id)->where('state', 'used')->exists();

        // Legacy/manual orders without proof have no recorded result, rather than a failed check.
        return $retained ? true : null;
    }

    /** @return HasMany<StoreOrderRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(StoreOrderRefund::class)->orderBy('id');
    }

    /** @return HasMany<StoreOrderFulfillment, $this> */
    public function fulfillments(): HasMany
    {
        return $this->hasMany(StoreOrderFulfillment::class)->orderBy('id');
    }

    /** @return HasMany<StoreOrderReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(StoreOrderReturn::class)->orderBy('id');
    }

    /** @return BelongsTo<StoreCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(StoreCustomer::class, 'store_customer_id');
    }
}
