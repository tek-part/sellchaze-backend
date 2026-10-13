<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\Tenancy\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owner-management tenant binding. Reads the {store} route binding, authorizes
 * ownership via StorePolicy, and sets it as the CurrentStore so StoreScope
 * isolates all catalog queries/creates to that store automatically.
 */
class ScopeToStore
{
    public function __construct(private readonly CurrentStore $currentStore) {}

    public function handle(Request $request, Closure $next): Response
    {
        $store = $request->route('store');
        $resolvedNumeric = false;

        // Settings controllers use the request tenant rather than a typed Store
        // argument, so Laravel's implicit model binding does not resolve their ID.
        if ((is_string($store) && ctype_digit($store)) || is_int($store)) {
            $store = Store::find($store);
            if ($store instanceof Store) {
                $resolvedNumeric = true;
                $request->route()->setParameter('store', $store);
            }
        }

        if (! $store instanceof Store) {
            abort(404);
        }
        if (! $request->user() || ! $request->user()->can('view', $store)) {
            // Retain the concealed-resource response for request-only settings
            // controllers, while keeping existing typed-controller responses.
            abort($resolvedNumeric ? 404 : 403, 'Forbidden.');
        }

        $this->currentStore->set($store);
        $request->attributes->set('store', $store);

        return $next($request);
    }
}
