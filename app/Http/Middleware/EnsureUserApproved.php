<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        if ($user->hasRole('Admin')) {
            return $next($request);
        }

        $profile = $user->profile;
        $isApproved = $profile && !empty($profile->active);

        $excluded = ['pending-approval', 'pending-approval.status', 'logout', 'profile.complete', 'profile.complete.store'];
        if (!$isApproved && !$request->routeIs($excluded)) {
            return redirect()->route('pending-approval');
        }

        return $next($request);
    }
}
