<?php

namespace App\Services\Storefront;

use App\Models\Store;

/**
 * Single source of truth for every storefront URL the platform emits — the
 * dashboard "view store" button, API resources, SEO canonicals, owner theme /
 * page previews, and (future) customer emails & notifications.
 *
 * URLs are built from dedicated storefront configuration + the store's own
 * domains, NEVER from APP_URL. APP_URL addresses the dashboard/API application;
 * it carries the wrong host (and, in split-port dev setups, the wrong port) for
 * tenant storefronts, so binding storefront links to it produces links that
 * open the wrong app entirely.
 *
 * Two audiences, two host/scheme policies:
 *
 *   • Owner-facing, environment-local links (dashboard button, owner previews):
 *     the tenant subdomain on the *current* base domain, over the active
 *     environment's scheme + port — so the link opens in whatever environment is
 *     running (http://nike.localhost:8002 in dev, https://nike.sellchase.com in
 *     prod). Custom/production domains never point at a dev box, so they are not
 *     used here.
 *
 *   • Public, customer-facing links (SEO canonicals, email / notification links):
 *     the store's canonical public host — its custom domain if attached, else its
 *     primary domain, else the tenant subdomain — always over the canonical
 *     (https) scheme with no port.
 *
 * Inputs (all from config/sellchase.php `storefront`, never APP_URL):
 *   base_domain      — tenant/fallback base ("sellchase.com" | "localhost")
 *   scheme / port    — the active environment for owner-facing links
 *   canonical_scheme — scheme for public, customer-facing links
 *   preview_domain   — optional dedicated base for owner preview links
 */
class StorefrontUrlGenerator
{
    /** Tenant base domain (e.g. "sellchase.com" in prod, "localhost" in dev). */
    public function baseDomain(): string
    {
        return strtolower((string) config('sellchase.storefront.base_domain', 'sellchase.com'));
    }

    /** Scheme for owner-facing, directly-openable links in the active environment. */
    public function scheme(): string
    {
        return strtolower((string) config('sellchase.storefront.scheme', 'https'));
    }

    /** Optional port for the active environment (e.g. 8002 in local dev); null in prod. */
    public function port(): ?int
    {
        $port = config('sellchase.storefront.port');

        return ($port === null || $port === '') ? null : (int) $port;
    }

    /** Scheme for public, customer-facing links (canonical URLs, emails). */
    public function canonicalScheme(): string
    {
        return strtolower((string) config('sellchase.storefront.canonical_scheme', 'https'));
    }

    /** Dedicated preview base domain, when configured; null => use the store host. */
    public function previewDomain(): ?string
    {
        $domain = config('sellchase.storefront.preview_domain');

        return is_string($domain) && $domain !== '' ? strtolower($domain) : null;
    }

    // ---------------------------------------------------------------- hosts

    /** The tenant subdomain on the current base domain — the always-resolving fallback host. */
    public function tenantHost(Store $store): ?string
    {
        return $store->slug ? strtolower($store->slug.'.'.$this->baseDomain()) : null;
    }

    /**
     * The store's attached custom domain (primary, type=custom), if any.
     *
     * Verified only — an unproven domain must never become the canonical URL a
     * customer sees or a crawler indexes.
     */
    public function customHost(Store $store): ?string
    {
        $host = $store->servableDomains()
            ->where('type', 'custom')
            ->where('is_primary', true)
            ->orderByDesc('id')
            ->value('host');

        return $host ? strtolower((string) $host) : null;
    }

    /** The store's primary verified domain of any type (custom or an explicit subdomain row). */
    public function primaryDomainHost(Store $store): ?string
    {
        $host = $store->servableDomains()
            ->where('is_primary', true)
            ->orderByRaw("CASE WHEN type = 'custom' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->value('host');

        return $host ? strtolower((string) $host) : null;
    }

    /**
     * Canonical public host a customer should see: custom domain, else the
     * primary domain, else the tenant subdomain on the base domain.
     */
    public function publicHost(Store $store): ?string
    {
        return $this->customHost($store)
            ?? $this->primaryDomainHost($store)
            ?? $this->tenantHost($store);
    }

    /**
     * Host for owner-facing, environment-local links. The tenant subdomain always
     * resolves in the running environment (custom / production domains do not
     * point at a dev box), so it — not the public host — backs the dashboard
     * button and owner previews.
     */
    public function environmentHost(Store $store): ?string
    {
        return $this->tenantHost($store);
    }

    /** Host for owner preview links: the dedicated preview subdomain when configured, else the env host. */
    public function previewHost(Store $store): ?string
    {
        $base = $this->previewDomain();
        if ($base !== null && $store->slug) {
            return strtolower($store->slug.'.'.$base);
        }

        return $this->environmentHost($store);
    }

    // ----------------------------------------------------------------- urls

    /** Owner-facing, directly-openable storefront URL for the active environment (dashboard button). */
    public function url(Store $store, string $path = '/'): ?string
    {
        $host = $this->environmentHost($store);

        return $host ? $this->build($this->scheme(), $host, $this->port(), $path) : null;
    }

    /** Public, canonical (customer-facing) storefront URL — SEO, emails, notifications. */
    public function publicUrl(Store $store, string $path = '/'): ?string
    {
        $host = $this->publicHost($store);

        return $host ? $this->build($this->canonicalScheme(), $host, null, $path) : null;
    }

    /** Signed owner preview URL. The caller mints the token; this attaches it to the preview host. */
    public function previewUrl(Store $store, string $token, string $path = '/'): ?string
    {
        $host = $this->previewHost($store);
        if (! $host) {
            return null;
        }

        $url = $this->build($this->scheme(), $host, $this->port(), $path);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'__preview='.$token;
    }

    /** Assemble scheme://host[:port]/path with a normalised, leading-slash path. */
    private function build(string $scheme, string $host, ?int $port, string $path): string
    {
        $authority = $host.($port !== null ? ':'.$port : '');
        $path = '/'.ltrim($path, '/');

        return $scheme.'://'.$authority.$path;
    }
}
