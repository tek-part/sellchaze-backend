<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        // SPA + Wavex polls/lists can burst; keep abuse protection without blocking normal use.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('performance.api_ip_per_minute', 1200))
                ->by('api-ip:'.$request->ip());
        });

        // Applied after jwt.auth. A noisy company is capped independently while
        // a second company behind the same office/NAT address keeps its quota.
        RateLimiter::for('tenant-read', function (Request $request) {
            $user = $request->user();
            $organizationId = $request->attributes->get('jwt_organization_id');
            if (! $organizationId) {
                $organizationId = $user?->organizationMemberships()
                    ->where('status', 'active')
                    ->orderBy('id')
                    ->value('organization_id');
            }
            $tenantKey = $organizationId ? 'org:'.$organizationId : 'user:'.($user?->id ?: $request->ip());

            return Limit::perMinute((int) config('performance.tenant_read_per_minute', 300))
                ->by('tenant-read:'.$tenantKey);
        });

        // ResolveStoreFromHost runs first, so the bucket is based on the real
        // store identity rather than a spoofable Host string.
        RateLimiter::for('storefront-read', function (Request $request) {
            $storeId = $request->attributes->get('store')?->id;

            return [
                Limit::perMinute((int) config('performance.storefront_read_per_minute', 600))
                    ->by('storefront:'.($storeId ?: 'unknown:'.$request->ip())),
                Limit::perMinute((int) config('performance.storefront_ip_per_minute', 1800))
                    ->by('storefront-ip:'.$request->ip()),
            ];
        });

        /*
        |----------------------------------------------------------------------
        | Custom domains
        |----------------------------------------------------------------------
        | Verification triggers outbound DNS lookups and can lead to certificate
        | issuance, so these endpoints are limited on several axes at once —
        | returning multiple Limits means EVERY limit must pass. A single abusive
        | store cannot exhaust a shared IP budget, and one IP cannot spray across
        | many stores.
        |
        | Laravel emits Retry-After and X-RateLimit-* headers automatically.
        */

        // Mutating a domain: connect / delete / promote.
        RateLimiter::for('domain-write', function (Request $request) {
            return [
                Limit::perMinute(10)->by('dw-user:'.($request->user()?->id ?: $request->ip())),
                Limit::perMinute(20)->by('dw-store:'.$this->storeKey($request)),
                Limit::perHour(60)->by('dw-ip:'.$request->ip()),
            ];
        });

        // Verification: the most abuse-sensitive path (DNS amplification, brute force).
        RateLimiter::for('domain-verify', function (Request $request) {
            return [
                // Burst allowance for the setup wizard's polling, then a hard hourly cap.
                Limit::perMinute(6)->by('dv-user:'.($request->user()?->id ?: $request->ip())),
                Limit::perHour(30)->by('dv-user-hr:'.($request->user()?->id ?: $request->ip())),
                Limit::perHour(60)->by('dv-store:'.$this->storeKey($request)),
                Limit::perMinute(20)->by('dv-ip:'.$request->ip()),
            ];
        });

        // Read-only health/audit polling — generous, but not unlimited.
        RateLimiter::for('domain-read', function (Request $request) {
            return [
                Limit::perMinute(60)->by('dr-user:'.($request->user()?->id ?: $request->ip())),
                Limit::perMinute(120)->by('dr-ip:'.$request->ip()),
            ];
        });
    }

    /**
     * Per-store limiter key. Falls back to the user so an unauthenticated or
     * store-less request still gets a stable bucket rather than sharing one.
     */
    private function storeKey(Request $request): string
    {
        $store = $request->route('store');
        $storeId = is_object($store) ? ($store->id ?? null) : $store;

        return (string) ($storeId ?: ('u'.($request->user()?->id ?: $request->ip())));
    }
}
