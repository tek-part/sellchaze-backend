<?php

namespace App\Http\Middleware;

use App\Models\StoreCustomer;
use App\Services\Commerce\CustomerAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 5: authenticates a store customer from the bearer token and attaches
 * it to the request. Runs after resolve.store, so token lookup is already
 * store-scoped (a token from another store resolves to null -> 401).
 */
class AuthenticateStoreCustomer
{
    public function __construct(private readonly CustomerAuthService $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $customer = $this->auth->resolve($request);

        if (! $customer instanceof StoreCustomer) {
            abort(401, 'Unauthenticated.');
        }

        $request->attributes->set('store_customer', $customer);

        return $next($request);
    }
}
