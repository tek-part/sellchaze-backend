<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AttachRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->validRequestId($request->header('X-Request-ID'))
            ? (string) $request->header('X-Request-ID')
            : (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);
        Log::withContext([
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }

    private function validRequestId(?string $value): bool
    {
        return $value !== null
            && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $value) === 1;
    }
}
