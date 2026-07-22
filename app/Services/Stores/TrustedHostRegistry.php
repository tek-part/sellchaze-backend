<?php

namespace App\Services\Stores;

use App\Models\StoreDomain;
use Illuminate\Support\Facades\Cache;

/**
 * Constant-time membership test for "is this host one we serve?".
 *
 * Sprint 1 built the trusted-host list as a single alternation regex. That is
 * O(n) in pattern length and becomes unworkable at scale — a million domains is
 * a multi-megabyte pattern recompiled on every request.
 *
 * This replaces it with a cache-first hash-map lookup:
 *
 *   - `Cache::rememberForever` on a PER-HOST key: `domain_trusted:{host}`.
 *     A hit is one cache read and an array/String comparison — O(1), and the
 *     memory cost is bounded by the working set (hosts actually being requested)
 *     rather than by total domains.
 *   - A miss falls through to ONE indexed database lookup, then memoises.
 *   - Negative results are cached too (with a short TTL), so a flood of unknown
 *     hosts cannot turn into a flood of queries.
 *   - Invalidation is exact: StoreDomain model events forget the specific host.
 *
 * This deliberately does NOT hold every domain in one cache entry — that would
 * reintroduce an O(n) payload that has to be deserialised on every request.
 */
class TrustedHostRegistry
{
    /** Cache key prefix for the per-host membership map. */
    public const PREFIX = 'domain_trusted:';

    /** Marker for "known-good host" (kept tiny — this is read on every request). */
    private const HIT = 1;

    /** Marker for "known-unknown host", cached briefly to absorb enumeration floods. */
    private const MISS = 0;

    public function __construct(private readonly StoreDomainResolver $resolver) {}

    public static function key(string $host): string
    {
        return self::PREFIX.strtolower($host);
    }

    /**
     * Is this host trusted? O(1) on a cache hit.
     *
     * Platform hosts are matched structurally (no lookup needed); only tenant
     * custom domains consult the cache/database.
     */
    public function isTrusted(?string $host): bool
    {
        $host = $this->resolver->normalizeHost($host);
        if ($host === null) {
            return false;
        }

        if ($this->isPlatformHost($host)) {
            return true;
        }

        $cached = Cache::get(self::key($host));

        if ($cached !== null) {
            return (int) $cached === self::HIT;
        }

        $exists = StoreDomain::query()
            ->servable()
            ->custom()
            ->where('host', $host)
            ->exists();

        if ($exists) {
            // Verified domains change rarely and are invalidated explicitly by
            // model events, so they can be held indefinitely.
            Cache::forever(self::key($host), self::HIT);

            return true;
        }

        // Short TTL for negatives: a domain that is verified moments from now
        // must not stay untrusted, but a scan of random hosts still gets absorbed.
        Cache::put(self::key($host), self::MISS, now()->addSeconds($this->negativeTtl()));

        return false;
    }

    /** Warm a host into the cache (called when a domain becomes verified). */
    public function remember(string $host): void
    {
        $host = $this->resolver->normalizeHost($host);
        if ($host !== null) {
            Cache::forever(self::key($host), self::HIT);
        }
    }

    /** Drop a single host — exact invalidation, no broad flush. */
    public function forget(?string $host): void
    {
        $host = $this->resolver->normalizeHost($host);
        if ($host !== null) {
            Cache::forget(self::key($host));
        }
    }

    /**
     * Structural match for hosts the platform owns: the storefront base domain
     * and its subdomains, the application URL host, and local development hosts.
     * No I/O.
     */
    public function isPlatformHost(string $host): bool
    {
        $base = $this->resolver->baseDomain();

        if ($host === $base || str_ends_with($host, '.'.$base)) {
            return true;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        $appHost = strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: ''));

        return $appHost !== '' && ($host === $appHost || str_ends_with($host, '.'.$appHost));
    }

    private function negativeTtl(): int
    {
        return (int) config('sellchase.storefront.resolve_cache_ttl', 300);
    }
}
