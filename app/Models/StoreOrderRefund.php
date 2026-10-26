<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded refund on a storefront order. Bookkeeping only: Sellchaze never moves the money,
 * the merchant returns it through the gateway dashboard, cash, a bank transfer or a credit note.
 *
 * @property int $id
 * @property int $store_id
 * @property int $store_order_id
 * @property string $amount
 * @property string $shipping_amount
 * @property string|null $reason
 * @property string|null $note
 * @property string $method
 * @property bool $restocked
 * @property list<array{store_order_item_id:int,quantity:int,amount:string,restocked_quantity?:int}> $items
 * @property int|null $actor_id
 */
class StoreOrderRefund extends Model
{
    use BelongsToStore;

    public const METHODS = ['original_payment', 'cash', 'bank_transfer', 'store_credit'];

    public const REASONS = ['customer_request', 'returned', 'damaged', 'wrong_item', 'not_delivered', 'order_cancelled', 'other'];

    protected $fillable = ['store_id', 'store_order_id', 'amount', 'shipping_amount', 'reason', 'note', 'method', 'restocked', 'items', 'actor_id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'restocked' => 'boolean',
        'items' => 'array',
    ];

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
