<?php

namespace App\Models;

use App\Services\Stores\StoreDomainResolver;
use App\Services\Stores\TrustedHostRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreDomain extends Model
{
    public const TYPES = ['subdomain', 'custom'];

    /** Lifecycle. Only VERIFIED rows are ever served (see StoreDomainResolver). */
    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DISABLED = 'disabled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VERIFIED,
        self::STATUS_REJECTED,
        self::STATUS_DISABLED,
    ];

    /** SSL lifecycle. Deliberately provider-agnostic — see ssl_provider. */
    public const SSL_NONE = 'none';

    public const SSL_PENDING = 'pending';

    public const SSL_ACTIVE = 'active';

    public const SSL_FAILED = 'failed';

    public const SSL_STATUSES = [self::SSL_NONE, self::SSL_PENDING, self::SSL_ACTIVE, self::SSL_FAILED];

    /** DNS TXT record name a tenant publishes to prove ownership. */
    public const VERIFICATION_TXT_NAME = '_sellchase-challenge';

    protected $fillable = [
        'store_id', 'host', 'type', 'status', 'is_primary',
        'verification_token', 'verified_at', 'last_checked_at', 'last_error',
        'ssl_status', 'ssl_provider', 'ssl_issued_at', 'ssl_expires_at',
        'created_by_user_id',
        // Sprint 2
        'ssl_issuer', 'ssl_fingerprint', 'ssl_san', 'ssl_renewal_attempts',
        'ssl_last_attempt_at', 'ssl_last_error',
        'dns_txt_ok', 'dns_target_ok', 'dns_target_type', 'health_score', 'health_report',
        'verification_attempts', 'verification_token_expires_at', 'locked_until',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'ssl_issued_at' => 'datetime',
        'ssl_expires_at' => 'datetime',
        // Sprint 2
        'ssl_san' => 'array',
        'ssl_last_attempt_at' => 'datetime',
        'ssl_renewal_attempts' => 'integer',
        'dns_txt_ok' => 'boolean',
        'dns_target_ok' => 'boolean',
        'health_report' => 'array',
        'health_score' => 'integer',
        'verification_attempts' => 'integer',
        'verification_token_expires_at' => 'datetime',
        'locked_until' => 'datetime',
    ];

    /**
     * Keep the host -> store resolution cache (Task 4) in sync with any
     * create/update/delete of a domain row, including cascade deletes.
     *
     * Also busts the TrustHosts allowlist generation, so a newly verified domain
     * becomes trusted immediately rather than after the cache TTL.
     */
    protected static function booted(): void
    {
        // Secure default: a platform-provisioned subdomain is owned by us and is
        // verified on sight; a custom domain must prove DNS ownership first.
        static::creating(static function (self $domain): void {
            if ($domain->getAttribute('status') === null) {
                $domain->status = $domain->type === 'custom'
                    ? self::STATUS_PENDING
                    : self::STATUS_VERIFIED;
            }
            if ($domain->status === self::STATUS_VERIFIED && $domain->verified_at === null) {
                $domain->verified_at = now();
            }
        });

        $forget = static function (self $domain): void {
            $resolver = app(StoreDomainResolver::class);
            $trusted = app(TrustedHostRegistry::class);

            $resolver->forgetHost($domain->host);
            $trusted->forget($domain->host);

            $original = $domain->getOriginal('host');
            if (is_string($original) && $original !== $domain->host) {
                $resolver->forgetHost($original);
                $trusted->forget($original);
            }

            // Warm the positive entry immediately so a freshly verified domain is
            // trusted on its very next request rather than after a cache miss.
            if ($domain->exists && $domain->isServable() && $domain->isCustom()) {
                $trusted->remember($domain->host);
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Only rows in this scope may resolve to a store or be trusted as a host. */
    public function scopeServable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VERIFIED);
    }

    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('type', 'custom');
    }

    public function isServable(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function isCustom(): bool
    {
        return $this->type === 'custom';
    }

    /** The exact DNS TXT record value a tenant must publish. */
    public function verificationTxtValue(): ?string
    {
        return $this->verification_token === null
            ? null
            : 'sellchase-domain-verification='.$this->verification_token;
    }

    // ------------------------------------------------------------- Sprint 2

    /** @return HasMany<StoreDomainCertificate, $this> */
    public function certificates(): HasMany
    {
        return $this->hasMany(StoreDomainCertificate::class);
    }

    /** @return HasMany<StoreDomainEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(StoreDomainEvent::class);
    }

    /** Verification is locked after too many failed attempts (abuse protection). */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * A challenge token is single-use-by-time: once it expires it can no longer
     * satisfy verification, so a stale TXT record cannot be replayed forever.
     */
    public function tokenHasExpired(): bool
    {
        return $this->verification_token_expires_at !== null
            && $this->verification_token_expires_at->isPast();
    }

    /** Days until the certificate expires; null when there is no certificate. */
    public function sslDaysRemaining(): ?int
    {
        if ($this->ssl_expires_at === null) {
            return null;
        }

        // Signed: negative once the certificate has expired.
        return (int) floor((float) (($this->ssl_expires_at->getTimestamp() - now()->getTimestamp()) / 86400));
    }

    /** Certificates are renewed this many days before expiry. */
    public function needsSslRenewal(int $thresholdDays = 30): bool
    {
        $remaining = $this->sslDaysRemaining();

        return $remaining !== null && $remaining <= $thresholdDays;
    }

    /** @param  Builder<StoreDomain>  $query */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('ssl_expires_at')
            ->where('ssl_expires_at', '<=', now()->addDays($days));
    }
}
