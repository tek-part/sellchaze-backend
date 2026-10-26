<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only gift card ledger: negative amounts redeem, positive amounts issue or restore.
 *
 * @property int $id
 * @property int $gift_card_id
 * @property int|null $store_order_id
 * @property string $amount
 * @property string $balance_after
 * @property string $reason
 */
class StoreGiftCardTransaction extends Model
{
    use BelongsToStore;

    /** restored = cancellation before fulfilment; refunded = a recorded refund's gift card part. */
    public const REASONS = ['issued', 'redeemed', 'restored', 'refunded', 'adjusted'];

    protected $fillable = ['store_id', 'gift_card_id', 'store_order_id', 'store_order_refund_id', 'amount', 'balance_after', 'reason', 'note', 'actor_id', 'idempotency_key'];

    protected $hidden = ['idempotency_key'];

    protected $casts = ['amount' => 'decimal:2', 'balance_after' => 'decimal:2'];

    /** @return BelongsTo<StoreGiftCard, $this> */
    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(StoreGiftCard::class, 'gift_card_id');
    }

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
