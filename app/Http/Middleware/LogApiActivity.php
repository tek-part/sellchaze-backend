<?php

namespace App\Http\Middleware;

use App\Services\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogApiActivity
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $response = $next($request);

        if (! $this->shouldLog($request)) {
            return $response;
        }

        $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
        $level = $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info');
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        ActivityLogger::log(
            action: sprintf('api.%s.%s', strtolower($request->method()), $request->route()?->getName() ?: 'unnamed'),
            subject: null,
            properties: [
                'url' => $request->fullUrl(),
            ],
            eventType: 'user_action',
            channel: 'http',
            level: $level,
            context: [
                'module' => 'api',
                'method' => $request->method(),
                'path' => $request->path(),
                'route' => $request->route()?->uri(),
                'route_name' => $request->route()?->getName(),
                'status_code' => $status,
                'duration_ms' => $durationMs,
            ],
        );

        return $response;
    }

    private function shouldLog(Request $request): bool
    {
        if (! $request->is('api/v1/*')) {
            return false;
        }
        if ($request->isMethod('GET')) {
            return false;
        }
        if (! $request->user()) {
            return false;
        }

        $excluded = [
            'api/v1/admin/activity-logs',
            'api/v1/auth/me',
        ];
        foreach ($excluded as $path) {
            if ($request->is($path)) {
                return false;
            }
        }

        return true;
    }
}
