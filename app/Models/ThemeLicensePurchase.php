<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon|null $failed_at
 */
class ThemeLicensePurchase extends Model
{
    public const PENDING = 'pending';
    public const CHECKOUT_CREATED = 'checkout_created';
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const EXPIRED = 'expired';
    public const REFUNDED = 'refunded';
    public const DISPUTED = 'disputed';
    public const REVOKED = 'revoked';

    protected $fillable = [
        'store_id', 'theme_id', 'purchaser_user_id', 'provider', 'status',
        'amount', 'currency', 'idempotency_key', 'checkout_session_id',
        'payment_intent_id', 'expires_at', 'paid_at', 'failed_at',
        'failure_reason', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'encrypted:array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    public function purchaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchaser_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ThemeLicensePurchaseEvent::class);
    }
}
