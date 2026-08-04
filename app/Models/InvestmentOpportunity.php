<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A factory listing itself as open to investment or partnership.
 * Lifecycle: pending → (admin) approved | rejected → closed.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $sector_id
 * @property string $kind
 * @property string $title
 * @property string $description
 * @property string|null $amount_sought
 * @property string $currency
 * @property string|null $equity_offered
 * @property string|null $city
 * @property string $status
 */
class InvestmentOpportunity extends Model
{
    use SoftDeletes;

    public const KIND_INVESTMENT = 'investment';
    public const KIND_PARTNERSHIP = 'partnership';
    public const KINDS = [self::KIND_INVESTMENT, self::KIND_PARTNERSHIP];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CLOSED = 'closed';

    /** Statuses visible on the public opportunities board. */
    public const PUBLIC_STATUSES = [self::STATUS_APPROVED];

    protected $fillable = [
        'user_id', 'sector_id', 'kind', 'title', 'description',
        'amount_sought', 'currency', 'equity_offered', 'city',
        'contact_email', 'contact_phone', 'status', 'review_note',
        'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'amount_sought' => 'decimal:2',
        'equity_offered' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Sector, $this> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Only listings investors are allowed to browse. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereIn('status', self::PUBLIC_STATUSES);
    }
}
