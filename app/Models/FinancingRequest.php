<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A funding request raised by a factory/merchant. Lifecycle:
 * pending → (admin) approved | rejected → funded | closed.
 *
 * @property int $id
 * @property int $user_id
 * @property string $amount
 * @property string $currency
 * @property string $purpose
 * @property int|null $repayment_months
 * @property bool $has_confirmed_order
 * @property string $description
 * @property string $status
 * @property string|null $review_note
 * @property int|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 */
class FinancingRequest extends Model
{
    use SoftDeletes;

    public const PURPOSES = ['order', 'materials', 'equipment', 'expansion'];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FUNDED = 'funded';
    public const STATUS_CLOSED = 'closed';

    /** Statuses visible to funders on the public board. */
    public const PUBLIC_STATUSES = [self::STATUS_APPROVED, self::STATUS_FUNDED];

    protected $fillable = [
        'user_id', 'amount', 'currency', 'purpose', 'repayment_months',
        'has_confirmed_order', 'description', 'status', 'review_note',
        'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'repayment_months' => 'integer',
        'has_confirmed_order' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Only requests funders are allowed to browse. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereIn('status', self::PUBLIC_STATUSES);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
