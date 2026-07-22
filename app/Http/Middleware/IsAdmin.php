<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows users with Admin role (Spatie). Legacy id/email kept for backward compatibility.
 */
class IsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();
        $allowed = $user->hasRole('Admin')
            || (int) $user->id === 1
            || $user->email === 'admin@admin.com';

        if (! $allowed) {
            abort(403, __('This action is unauthorized.'));
        }

        return $next($request);
    }
}
