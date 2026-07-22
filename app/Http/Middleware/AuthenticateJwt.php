<?php

namespace App\Http\Middleware;

use App\Models\AuthSession;
use App\Services\JwtTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $token = trim(substr($header, 7));
        if ($token === '') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $jwt = JwtTokenService::fromConfig();
        $user = $jwt->validateAccessToken($token);
        if (! $user) {
            return response()->json(['message' => 'Invalid or expired token.'], 401);
        }

        if ($user->is_active === false) {
            return response()->json(['message' => __('Account is deactivated.')], 403);
        }

        auth()->setUser($user);
        $request->setUserResolver(fn () => $user);
        $sessionId = $jwt->accessSessionId($token);
        if (is_string($sessionId) && $sessionId !== '') {
            AuthSession::query()
                ->where('id', $sessionId)
                ->whereNull('revoked_at')
                ->update([
                    'last_used_at' => now(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
        }

        return $next($request);
    }
}
