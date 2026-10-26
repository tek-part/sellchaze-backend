<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A billing invoice for a subscription. Matches the live `invoices` schema.
 *
 * @property int $id
 * @property string $code
 * @property int $user_id
 * @property int|null $subscription_id
 * @property int|null $coupon_id
 * @property string $amount
 * @property string $tax_amount
 * @property string $discount_amount
 * @property string $total
 * @property string $currency
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $due_at
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property string|null $gateway_slug
 * @property string|null $gateway_invoice_id
 * @property string|null $gateway_payment_url
 * @property string|null $pdf_path
 * @property array|null $metadata
 */
class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'user_id', 'subscription_id', 'coupon_id',
        'amount', 'tax_amount', 'discount_amount', 'total', 'currency',
        'status', 'due_at', 'paid_at', 'gateway_slug', 'gateway_invoice_id',
        'gateway_payment_url', 'pdf_path', 'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
        'metadata' => 'array',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public static function generateCode(string $prefix = 'INV'): string
    {
        do {
            $code = $prefix.'-'.now()->format('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        } while (self::query()->where('code', $code)->exists());

        return $code;
    }
}
