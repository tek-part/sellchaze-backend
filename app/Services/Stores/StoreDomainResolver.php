<?php

namespace App\Services\Stores;

use App\Models\Product;
use App\Models\Scopes\ProductScope;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Services\StoreService;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves an incoming hostname to a Store (subdomains + custom domains, with
 * alias history for 301 redirects). Results are cached (Task 4).
 *
 * Security posture:
 *  - A persisted store_domains row is authoritative, but ONLY when it is
 *    VERIFIED. An unverified/rejected/disabled row never resolves, so attaching
 *    a domain you do not own grants nothing until DNS ownership is proven.
 *  - Only single-label subdomains of the configured base domain resolve via the
 *    slug fallback.
 *  - Reserved labels (www, api, admin, ...) never resolve to a store.
 *  - Any host we don't recognise returns null (no store context) — a spoofed
 *    Host header cannot be pointed at an arbitrary store.
 */
class StoreDomainResolver
{
    public function baseDomain(): string
    {
        return strtolower((string) config('sellchase.storefront.base_domain', 'sellchase.com'));
    }

    public function isReservedLabel(string $label): bool
    {
        return in_array(strtolower($label), StoreService::RESERVED_SLUGS, true);
    }

    /**
     * Normalise a hostname, or return null when it is not one.
     *
     * Hardening: the port is stripped, but anything carrying characters that
     * cannot appear in a hostname is REJECTED rather than truncated. Truncating
     * would let `victim.com:8080@evil.io` — or `victim.com/../x`, `victim.com#y`
     * — collapse onto a legitimate host and resolve to that tenant's store.
     */
    public function normalizeHost(?string $host): ?string
    {
        if ($host === null) {
            return null;
        }

        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }

        // Strip a trailing :port, but only a genuine numeric one.
        if (preg_match('/^(.*):(\d{1,5})$/', $host, $m) === 1) {
            $host = $m[1];
        }

        $host = rtrim($host, '.');
        if ($host === '') {
            return null;
        }

        // A hostname is labels of a-z 0-9 and inner hyphens, separated by dots.
        // Anything else (userinfo '@', path '/', query '?', fragment '#',
        // whitespace, a stray ':') is not a host and must not be coerced into one.
        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) !== 1) {
            return null;
        }

        return $host;
    }

    public static function cacheKey(string $host): string
    {
        return 'store_resolve:'.strtolower($host);
    }

    /** Invalidate a cached resolution for a host (called on domain changes). */
    public function forgetHost(?string $host): void
    {
        $host = $this->normalizeHost($host);
        if ($host !== null) {
            Cache::forget(self::cacheKey($host));
        }
    }

    private function cacheTtl(): int
    {
        return (int) config('sellchase.storefront.resolve_cache_ttl', 300);
    }

    /** Convenience: just the store (used by existing callers/tests). */
    public function resolve(?string $host): ?Store
    {
        return $this->resolveContext($host)?->store;
    }

    /**
     * Cached host -> resolution. The cache holds a small descriptor (ids/hosts),
     * and the Store model is re-loaded fresh so its attributes are never stale.
     */
    public function resolveContext(?string $host): ?ResolvedStore
    {
        $host = $this->normalizeHost($host);
        if ($host === null) {
            return null;
        }

        $payload = Cache::remember(self::cacheKey($host), $this->cacheTtl(), function () use ($host) {
            $ctx = $this->resolveContextUncached($host);

            return $ctx === null
                ? ['store_id' => null]
                : [
                    'store_id' => $ctx->store->id,
                    'matched' => $ctx->matchedHost,
                    'canonical' => $ctx->canonicalHost,
                    'alias' => $ctx->isAlias,
                ];
        });

        if (empty($payload['store_id'])) {
            return null;
        }

        $store = Store::find($payload['store_id']);
        if ($store === null) {
            Cache::forget(self::cacheKey($host)); // store vanished; drop stale mapping

            return null;
        }

        return new ResolvedStore($store, $payload['matched'], $payload['canonical'], (bool) $payload['alias']);
    }

    private function resolveContextUncached(string $host): ?ResolvedStore
    {
        $ctx = $this->resolveContextStrict($host);
        if ($ctx !== null) {
            return $ctx;
        }

        // Local-dev convenience ONLY: a bare `localhost`/`127.0.0.1` has no store subdomain, so the
        // storefront could never resolve a tenant in development. In the local environment we fall
        // back to a demo store so `npm run dev` on http://localhost works without host juggling.
        // Configurable via STOREFRONT_DEV_STORE (slug or id); never active outside `local`.
        return $this->devFallbackStore($host);
    }

    private function devFallbackStore(string $host): ?ResolvedStore
    {
        if (! app()->environment('local')) {
            return null;
        }

        $configured = config('sellchase.storefront.dev_store');
        $store = null;
        if ($configured) {
            $store = is_numeric($configured)
                ? Store::query()->find($configured)
                : Store::query()->where('slug', $configured)->first();
        }

        // Otherwise prefer a store whose owner actually has an active catalogue, so the dev
        // storefront isn't an empty shell; fall back to the first store as a last resort.
        if ($store === null) {
            $store = Store::query()->get()->first(function (Store $s): bool {
                return $s->owner_user_id !== null && Product::query()
                    ->withoutGlobalScope(ProductScope::class)
                    ->where('user_id', $s->owner_user_id)
                    ->whereNull('store_id')
                    ->where('is_active', true)
                    ->exists();
            }) ?? Store::query()->orderBy('id')->first();
        }

        return $store !== null ? new ResolvedStore($store, $host, $host, false) : null;
    }

    private function resolveContextStrict(string $host): ?ResolvedStore
    {
        // 1) Authoritative: an explicit store_domains row (subdomain or custom).
        //    Only VERIFIED rows are servable — an unproven domain resolves to nothing.
        $domain = StoreDomain::query()->servable()->where('host', $host)->first();
        if ($domain) {
            $label = $this->subdomainLabel($host);
            if ($label !== null && $this->isReservedLabel($label)) {
                return null; // never serve a reserved label, even if a row exists
            }
            $store = $domain->store;
            if ($store === null) {
                return null;
            }
            $canonical = $this->canonicalHostFor($store) ?? $host;

            return new ResolvedStore($store, $host, $canonical, ! $domain->is_primary && $canonical !== $host);
        }

        // 2) Fallback: a single-label subdomain of the base domain -> match by slug.
        $label = $this->subdomainLabel($host);
        if ($label === null || $this->isReservedLabel($label)) {
            return null;
        }
        $store = Store::query()->where('slug', $label)->first();
        if ($store === null) {
            return null;
        }

        return new ResolvedStore($store, $host, $this->canonicalHostFor($store) ?? $host, false);
    }

    /**
     * The store's canonical public host: its verified primary row.
     *
     * A custom primary outranks a subdomain primary so that promoting a custom
     * domain consolidates traffic onto it — matching StorefrontUrlGenerator's
     * publicHost() precedence exactly, so the 301 target and the <link rel=canonical>
     * can never disagree.
     */
    private function canonicalHostFor(Store $store): ?string
    {
        return $store->servableDomains()
            ->where('is_primary', true)
            ->orderByRaw("CASE WHEN type = 'custom' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->value('host');
    }

    /**
     * Return the single subdomain label of the base domain, or null when the
     * host is the base domain itself, a foreign host, or multi-label.
     */
    private function subdomainLabel(string $host): ?string
    {
        $suffix = '.'.$this->baseDomain();
        if (! str_ends_with($host, $suffix)) {
            return null;
        }
        $label = substr($host, 0, -strlen($suffix));
        if ($label === '' || str_contains($label, '.')) {
            return null; // no nested subdomains (a.b.sellchase.com)
        }

        return $label;
    }
}
