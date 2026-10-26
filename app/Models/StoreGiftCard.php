<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Services\Commerce\GiftCards;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A store gift card: a stored-value tender. The code itself is never stored, only a keyed digest
 * and its last four characters ({@see GiftCards}).
 *
 * @property int $id
 * @property int $store_id
 * @property string $code_digest
 * @property string $last4
 * @property string $initial_value
 * @property string $balance
 * @property string $currency
 * @property Carbon|null $expires_at
 * @property string $status
 * @property string $source
 */
class StoreGiftCard extends Model
{
    use BelongsToStore;

    public const STATUSES = ['active', 'disabled'];

    public const SOURCES = ['manual', 'purchase'];

    protected $fillable = [
        'store_id', 'code_digest', 'last4', 'initial_value', 'balance', 'currency', 'expires_at', 'status',
        'customer_name', 'customer_email', 'customer_phone', 'note', 'issued_by', 'source',
    ];

    protected $hidden = ['code_digest'];

    protected $attributes = ['status' => 'active', 'source' => 'manual'];

    protected $casts = [
        'initial_value' => 'decimal:2',
        'balance' => 'decimal:2',
        'expires_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** active, disabled, expired or depleted — what the merchant sees. */
    public function state(): string
    {
        return match (true) {
            $this->status === 'disabled' => 'disabled',
            $this->isExpired() => 'expired',
            bccomp((string) $this->balance, '0', 2) <= 0 => 'depleted',
            default => 'active',
        };
    }

    /** @return HasMany<StoreGiftCardTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(StoreGiftCardTransaction::class, 'gift_card_id');
    }
}
