<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit trail for every custom-domain event.
 *
 * Append-only by construction: `updated_at` does not exist, and updates/deletes
 * are blocked at the model layer. Rows keep a denormalised `host` and a
 * nullOnDelete FK so removing a domain can never erase its history.
 */
class StoreDomainEvent extends Model
{
    public const UPDATED_AT = null;

    // Lifecycle
    public const DOMAIN_ADDED = 'domain_added';

    public const DOMAIN_REMOVED = 'domain_removed';

    public const DISABLED = 'disabled';

    public const ENABLED = 'enabled';

    // Verification
    public const VERIFICATION_STARTED = 'verification_started';

    public const VERIFICATION_PASSED = 'verification_passed';

    public const VERIFICATION_FAILED = 'verification_failed';

    public const OWNERSHIP_REJECTED = 'ownership_rejected';

    // SSL
    public const SSL_ISSUED = 'ssl_issued';

    public const SSL_RENEWED = 'ssl_renewed';

    public const SSL_FAILED = 'ssl_failed';

    public const SSL_REVOKED = 'ssl_revoked';

    public const SSL_EXPIRING = 'ssl_expiring';

    // Routing
    public const PRIMARY_CHANGED = 'primary_changed';

    public const REDIRECT_CHANGED = 'redirect_changed';

    public const EVENTS = [
        self::DOMAIN_ADDED, self::DOMAIN_REMOVED, self::DISABLED, self::ENABLED,
        self::VERIFICATION_STARTED, self::VERIFICATION_PASSED, self::VERIFICATION_FAILED,
        self::OWNERSHIP_REJECTED,
        self::SSL_ISSUED, self::SSL_RENEWED, self::SSL_FAILED, self::SSL_REVOKED, self::SSL_EXPIRING,
        self::PRIMARY_CHANGED, self::REDIRECT_CHANGED,
    ];

    protected $fillable = [
        'store_id', 'store_domain_id', 'host', 'event',
        'actor_user_id', 'actor_type', 'ip', 'user_agent',
        'old_value', 'new_value', 'reason',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'created_at' => 'datetime',
    ];

    /** Enforce immutability at the model layer, not just by convention. */
    protected static function booted(): void
    {
        static::updating(static fn () => false);
        static::deleting(static fn () => false);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(StoreDomain::class, 'store_domain_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Free-text search across host, event and reason — backs the searchable
     * history endpoint.
     *
     * @param  Builder<StoreDomainEvent>  $query
     * @return Builder<StoreDomainEvent>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $q->where('host', 'like', $like)
                ->orWhere('event', 'like', $like)
                ->orWhere('reason', 'like', $like);
        });
    }
}
