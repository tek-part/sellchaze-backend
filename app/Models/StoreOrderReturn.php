<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A return request on a delivered order: requested by the customer (or opened by the merchant),
 * approved or rejected, received back, and closed with a refund, exchange or store credit.
 *
 * @property int $id
 * @property int $store_id
 * @property int $store_order_id
 * @property int|null $store_customer_id
 * @property string $status
 * @property string $reason
 * @property string|null $customer_note
 * @property string|null $merchant_note
 * @property list<array{store_order_item_id:int,quantity:int}> $items
 * @property string|null $resolution
 * @property int|null $store_order_refund_id
 */
class StoreOrderReturn extends Model
{
    use BelongsToStore;

    public const STATUSES = ['requested', 'approved', 'rejected', 'received', 'closed'];

    /** Statuses that block a second request on the same order. */
    public const OPEN = ['requested', 'approved', 'received'];

    /** Fail-closed workflow: anything not listed is rejected. */
    public const TRANSITIONS = [
        'requested' => ['approved', 'rejected'],
        'approved' => ['received', 'rejected', 'closed'],
        'received' => ['closed'],
        'rejected' => [],
        'closed' => [],
    ];

    public const RESOLUTIONS = ['refund', 'exchange', 'store_credit'];

    public const REASONS = ['damaged', 'wrong_item', 'not_as_described', 'size_fit', 'changed_mind', 'other'];

    protected $fillable = ['store_id', 'store_order_id', 'store_customer_id', 'status', 'reason', 'customer_note', 'merchant_note', 'items', 'resolution', 'store_order_refund_id'];

    protected $casts = ['items' => 'array'];

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    /** @return BelongsTo<StoreOrderRefund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(StoreOrderRefund::class, 'store_order_refund_id');
    }
}
