<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appends baseline hardening headers to every response.
 *
 * The Content-Security-Policy is emitted in REPORT-ONLY mode: browsers report
 * (console-log) violations but never block any asset, script, style, image, or
 * theme resource. This is telemetry only — it does not enforce CSP and does not
 * change rendering. Existing per-response headers are never overwritten.
 */
class SecurityHeaders
{
    /**
     * @var array<string, string>
     */
    private const HEADERS = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
    ];

    /**
     * Report-Only policy: permissive enough to avoid noise, never enforced.
     */
    private const CSP_REPORT_ONLY = "default-src 'self'; "
        ."img-src 'self' data: https:; "
        ."style-src 'self' 'unsafe-inline' https:; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; "
        ."font-src 'self' data: https:; "
        ."connect-src 'self' https: wss:; "
        ."frame-ancestors 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $header => $value) {
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        if (! $response->headers->has('Content-Security-Policy-Report-Only')) {
            $response->headers->set('Content-Security-Policy-Report-Only', self::CSP_REPORT_ONLY);
        }

        return $response;
    }
}
