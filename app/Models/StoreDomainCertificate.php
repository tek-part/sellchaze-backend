<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per certificate issuance / renewal attempt — the renewal history.
 *
 * The *current* certificate state is denormalised onto store_domains (ssl_*) so
 * the hot read path never joins; this table is the append-side record used for
 * history, debugging and metrics.
 */
class StoreDomainCertificate extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'store_domain_id', 'provider', 'status', 'issuer', 'fingerprint',
        'san', 'attempt', 'issued_at', 'expires_at', 'error',
    ];

    protected $casts = [
        'san' => 'array',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(StoreDomain::class, 'store_domain_id');
    }
}
