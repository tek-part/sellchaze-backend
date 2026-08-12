<?php

namespace App\Http\Middleware;

use App\Models\AuthSession;
use App\Services\JwtTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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

        try {
            $jwt = JwtTokenService::fromConfig();
            $user = $jwt->validateAccessToken($token);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => __('Authentication service unavailable.'),
            ], 503);
        }
        if (! $user) {
            return response()->json(['message' => 'Invalid or expired token.'], 401);
        }

        if ($user->is_active === false) {
            // The machine-readable code lets the SPA end the session cleanly
            // (the message itself is localized, so clients must not match on it).
            return response()->json(['message' => __('Account is deactivated.'), 'code' => 'account_deactivated'], 403);
        }

        auth()->setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('jwt_organization_id', $jwt->accessOrganizationId($token));
        $sessionId = $jwt->accessSessionId($token);
        // Session telemetry must not turn every authenticated GET into a database
        // write. Touch at most once per five-minute window; revocation remains
        // governed by the short-lived access token and refresh-session checks.
        if (is_string($sessionId) && $sessionId !== ''
            && Cache::add('auth_session_touch:'.$sessionId, true, now()->addMinutes(5))) {
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
