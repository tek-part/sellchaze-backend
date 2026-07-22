<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user || !$user->profile) {
            return $next($request);
        }

        $profile = $user->profile;
        $required = ['phone', 'company', 'country'];
        $isComplete = true;
        foreach ($required as $field) {
            if (empty(trim($profile->{$field} ?? ''))) {
                $isComplete = false;
                break;
            }
        }

        if (!$isComplete && !$request->routeIs('profile.complete') && !$request->routeIs('profile.complete.store')) {
            return redirect()->route('profile.complete');
        }

        return $next($request);
    }
}
