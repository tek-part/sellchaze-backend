<?php

namespace App\Http\Middleware;

use App\Services\Commerce\CheckoutAttempts;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class IdempotentCheckout
{
    public function __construct(private readonly CheckoutAttempts $attempts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $attempts = $this->attempts;
        // Legacy integrations remain compatible; the current storefront always supplies a key.
        if (! $request->hasHeader(CheckoutAttempts::HEADER)) {
            return $next($request);
        }
        abort_unless($request->isJson(), 415, 'Checkout replay requires a JSON request.');
        $claim = $attempts->begin($request);
        if ($claim instanceof JsonResponse) {
            return $claim;
        }
        $request->attributes->set(CheckoutAttempts::ATTRIBUTE, $claim);
        try {
            $response = $next($request);
        } catch (ValidationException $exception) {
            $response = response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        }
        if ($response instanceof JsonResponse) {
            $attempts->finish($claim, $response);
        }
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
