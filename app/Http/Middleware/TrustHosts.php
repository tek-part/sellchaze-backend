<?php

namespace App\Http\Middleware;

use App\Services\Stores\TrustedHostRegistry;
use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Illuminate\Http\Request;

/**
 * Host allowlist for an unbounded number of tenant custom domains.
 *
 * Symfony's trusted-host mechanism takes regex patterns and tests them one by
 * one — inherently O(n) in the number of domains. Sprint 1 collapsed the tenant
 * list into a single alternation, which is fine for thousands but not for
 * hundreds of thousands: the pattern itself becomes megabytes and is recompiled
 * per request.
 *
 * Enforcement therefore happens here instead, via TrustedHostRegistry: a
 * cache-first, constant-time membership test with an indexed single-row database
 * fallback. Platform hosts are matched structurally with no I/O at all.
 *
 * Security posture is unchanged from Sprint 1 — this is still a strict
 * allowlist. Only VERIFIED custom domains are trusted, and anything unknown is
 * rejected before it can reach store resolution. It is NOT a blanket allow.
 */
class TrustHosts extends Middleware
{
    /**
     * Signature intentionally matches the parent (no return type, untyped
     * $next) — Laravel's TrustHosts declares it that way.
     */
    public function handle(Request $request, $next)
    {
        // Mirrors the framework's own guard: inert in local and under tests, so
        // development and the existing suite behave exactly as before.
        if (! $this->shouldSpecifyTrustedHosts()) {
            return $next($request);
        }

        if (! app(TrustedHostRegistry::class)->isTrusted($request->getHost())) {
            // 404, not 400: an untrusted Host must not reveal whether the
            // hostname is known to the platform.
            abort(404);
        }

        return $next($request);
    }

    /**
     * Static platform patterns.
     *
     * Retained for anything that introspects the middleware. Tenant domains are
     * deliberately NOT enumerated here — see the class docblock; enforcement
     * goes through handle() above.
     *
     * @return array<int, string|null>
     */
    public function hosts()
    {
        $base = preg_quote(strtolower((string) config('sellchase.storefront.base_domain', 'sellchase.com')), '#');

        return [
            '^(.+\.)?'.$base.'$',
            $this->allSubdomainsOfApplicationUrl(),
            '^localhost(:\d+)?$',
            '^127\.0\.0\.1(:\d+)?$',
        ];
    }
}
