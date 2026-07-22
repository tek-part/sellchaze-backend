<?php

namespace App\Http\Middleware;

use App\Services\Stores\ResolvedStore;
use App\Services\Stores\StoreDomainResolver;
use App\Support\Tenancy\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request hostname to a Store and attaches it to the request.
 *
 * - Canonicalises the request in a SINGLE 301: an old alias host and an http
 *   request are corrected together, so a customer never pays two redirect hops
 *   (which would also dilute link equity) — Task 3.
 * - Otherwise attaches the resolved Store (or null) to request attributes only;
 *   no container-global binding (Octane-safe) — Task 2.
 * - Reserved / unknown / spoofed / unverified hosts resolve to null (see
 *   StoreDomainResolver).
 */
class ResolveStoreFromHost
{
    public function __construct(
        private readonly StoreDomainResolver $resolver,
        private readonly CurrentStore $currentStore,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->resolver->resolveContext($request->getHost());

        $redirect = $this->canonicalRedirect($request, $context);
        if ($redirect !== null) {
            return redirect()->away($redirect, 301);
        }

        // Establish the tenant for StoreScope + attach for controllers.
        $this->currentStore->set($context?->store);
        $request->attributes->set('store', $context?->store);

        return $next($request);
    }

    /**
     * The canonical absolute URL for this request, or null when it is already
     * canonical. Collapses the host correction and the scheme upgrade into one.
     */
    private function canonicalRedirect(Request $request, ?ResolvedStore $context): ?string
    {
        $targetHost = $context !== null && $context->isAlias
            ? $context->canonicalHost
            : $request->getHost();

        $hostChanged = $targetHost !== $request->getHost();

        // Only upgrade the scheme for a host we actually serve; an unknown host
        // must 404 rather than be handed a redirect it can follow.
        $shouldUpgradeScheme = $context !== null
            && ! $request->isSecure()
            && $this->shouldForceHttps();

        if (! $hostChanged && ! $shouldUpgradeScheme) {
            return null;
        }

        $scheme = $shouldUpgradeScheme ? 'https' : $request->getScheme();

        return $scheme.'://'.$targetHost.$request->getRequestUri();
    }

    /**
     * HTTPS is forced from configuration, defaulting to on outside local/testing
     * so development over http keeps working unchanged.
     *
     * Requires TRUSTED_PROXIES to be set behind a TLS-terminating proxy, else
     * X-Forwarded-Proto is ignored and isSecure() would loop.
     */
    private function shouldForceHttps(): bool
    {
        $configured = config('sellchase.storefront.force_https');

        if ($configured !== null) {
            return (bool) $configured;
        }

        return ! app()->environment(['local', 'testing']);
    }
}
