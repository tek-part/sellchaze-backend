<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictPendingApiUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->pending_approval) {
            return $next($request);
        }

        $path = $request->path();

        if ($request->isMethod('GET') && $path === 'api/v1/auth/me') {
            return $next($request);
        }

        if ($request->isMethod('POST') && $path === 'api/v1/auth/logout') {
            return $next($request);
        }

        return response()->json([
            'message' => __('Your account is waiting for administrator approval.'),
            'code' => 'pending_approval',
        ], 403);
    }
}
