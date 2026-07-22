<?php

namespace App\Services\Stores\Ssl;

use DateTimeInterface;

/**
 * Provider-neutral outcome of an SSL operation.
 *
 * Every provider — ACME/Let's Encrypt, Cloudflare, Caddy, a reverse proxy —
 * reports through this one shape, so nothing downstream ever branches on which
 * provider is configured.
 */
final class CertificateResult
{
    /**
     * @param  list<string>  $san
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $status,
        public readonly ?string $issuer = null,
        public readonly ?string $fingerprint = null,
        public readonly array $san = [],
        public readonly ?DateTimeInterface $issuedAt = null,
        public readonly ?DateTimeInterface $expiresAt = null,
        public readonly ?string $error = null,
        /** True when the failure is worth retrying (rate limit, transient DNS/network). */
        public readonly bool $retryable = true,
    ) {}

    /**
     * @param  list<string>  $san
     */
    public static function issued(
        ?string $issuer,
        ?string $fingerprint,
        array $san,
        ?DateTimeInterface $issuedAt,
        ?DateTimeInterface $expiresAt,
    ): self {
        return new self(true, 'active', $issuer, $fingerprint, $san, $issuedAt, $expiresAt);
    }

    /** Issuance is under way out-of-band; poll status later. */
    public static function pending(?string $reason = null): self
    {
        return new self(false, 'pending', error: $reason);
    }

    public static function failed(string $error, bool $retryable = true): self
    {
        return new self(false, 'failed', error: $error, retryable: $retryable);
    }

    public static function revoked(): self
    {
        return new self(true, 'none');
    }
}
