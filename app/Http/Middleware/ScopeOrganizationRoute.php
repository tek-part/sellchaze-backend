<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ScopeOrganizationRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->route('organization');
        if (! $organization instanceof Organization) {
            $organization = Organization::query()->findOrFail($organization);
        }
        abort_unless($request->user()?->can('view', $organization), 403);
        $request->attributes->set('scoped_organization', $organization);

        $store = $request->route('store');
        if ($store !== null) {
            if (! $store instanceof Store) {
                $store = Store::query()->findOrFail($store);
            }
            abort_unless((int) $store->organization_id === (int) $organization->id, 404);
            $request->route()->setParameter('store', $store);
        }

        if ($request->routeIs('v2.organization.posts.store')) {
            $request->merge(['acting_organization_id' => $organization->id]);
        }
        $request->route()->forgetParameter('organization');

        return $next($request);
    }
}
