<?php

namespace App\Http\Middleware;

use App\Services\Stores\StoreManagers;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;

/** Canonical store grants apply only to dedicated managers; legacy action grants stay exact. */
class StoreCatalogPermission extends PermissionMiddleware
{
    public function handle($request, Closure $next, $permission, $guard = null)
    {
        if ($request instanceof Request && $request->user()?->hasRole(StoreManagers::ROLE)) {
            return app(StoreManagerPermissions::class)->handle($request, $next);
        }

        return parent::handle($request, $next, $permission, $guard);
    }
}
