<?php

namespace App\Http\Middleware;

use App\Services\Stores\StoreProvisioner;
use App\Support\Tenancy\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owner auto-context. Resolves the authenticated Merchant/Supplier's single
 * store (provisioning it on first access if missing) and binds it as the
 * CurrentStore tenant, so /my-store/* routes never carry a store id and
 * StoreScope isolates every catalog query automatically.
 *
 * This is the single-store counterpart to ScopeToStore: the same downstream
 * controllers work unchanged because the resolved Store is injected as the
 * `store` route parameter. Admins have no single store of their own and must
 * use the explicit /stores/{store} routes instead.
 */
class ResolveOwnStore
{
    public function __construct(
        private readonly CurrentStore $currentStore,
        private readonly StoreProvisioner $provisioner,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $store = $this->provisioner->ensureFor($user);
        if (! $store) {
            abort(403, 'No store is associated with this account.');
        }

        $this->currentStore->set($store);
        $request->attributes->set('store', $store);

        $route = $request->route();
        if ($route !== null) {
            // The owner URL omits {store}. Appending it after an existing child
            // parameter (for example {theme} or {domain}) makes Laravel invoke
            // shared controller methods in the wrong positional order. Prepend
            // the synthetic store binding so it matches /stores/{store}/... .
            (function ($value): void {
                $this->parameters = ['store' => $value] + $this->parameters;
            })->call($route, $store);
        }

        return $next($request);
    }
}
