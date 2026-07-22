<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * Resolve the trusted proxies from config (env-baked, config:cache-safe).
     *
     * Returns '*' to trust the forwarding proxy (Cloudflare / managed LB), an
     * array of IP/CIDR strings for a known proxy layer, or the parent default
     * (null = trust nothing) when TRUSTED_PROXIES is unset. Nothing is trusted
     * unless it is explicitly configured, so X-Forwarded-* cannot be spoofed by
     * a direct client in the default configuration.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $configured = config('app.trusted_proxies');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured) === '*'
                ? '*'
                : array_map('trim', explode(',', $configured));
        }

        return parent::proxies();
    }
}
