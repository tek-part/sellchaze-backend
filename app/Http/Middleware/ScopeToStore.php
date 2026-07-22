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

        if (! $store instanceof Store) {
            abort(404);
        }
        if (! $request->user() || ! $request->user()->can('view', $store)) {
            abort(403, 'Forbidden.');
        }

        $this->currentStore->set($store);
        $request->attributes->set('store', $store);

        return $next($request);
    }
}
