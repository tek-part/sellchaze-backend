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
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        'Cross-Origin-Opener-Policy' => 'unsafe-none',
        'Cross-Origin-Embedder-Policy' => 'unsafe-none',
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

        // Framing is ENFORCED (not report-only): the storefront may only be embedded by itself, the
        // dashboard (theme editor / customizer live preview) and tenant hosts on the base domain.
        // Replaces X-Frame-Options, which cannot express more than one origin.
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', 'frame-ancestors '.implode(' ', $this->frameAncestors()));
        }

        if (! $response->headers->has('Content-Security-Policy-Report-Only')) {
            $response->headers->set('Content-Security-Policy-Report-Only', self::CSP_REPORT_ONLY);
        }

        return $response;
    }

    /**
     * @return list<string>
     */
    private function frameAncestors(): array
    {
        $configured = trim((string) config('sellchase.storefront.frame_ancestors', ''));
        if ($configured !== '') {
            return array_values(array_filter(preg_split('/\s+/', $configured) ?: []));
        }

        $ancestors = ["'self'"];
        $frontend = (string) config('sellchase.frontend_url', '');
        if ($frontend !== '' && ($origin = $this->origin($frontend)) !== null) {
            $ancestors[] = $origin;
        }
        $base = strtolower((string) config('sellchase.storefront.base_domain', ''));
        if ($base !== '') {
            $ancestors[] = 'https://'.$base;
            $ancestors[] = 'https://*.'.$base;
        }

        return array_values(array_unique($ancestors));
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$parts['host'].$port;
    }
}
