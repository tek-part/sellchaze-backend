<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $headerKey = $request->header('X-API-Key') ?? $request->header('Authorization');
        if (is_string($headerKey) && str_starts_with($headerKey, 'Bearer ')) {
            $headerKey = substr($headerKey, 7);
        }
        $headerKey = is_string($headerKey) ? trim($headerKey) : '';

        // Wigpleasure → Sellchase: POST/PATCH order ingress is authenticated by payload + OrderSyncService
        // (merchant resolution). Do not require X-API-Key even when global API_KEY / SELLCHASE_API_KEY exist in .env.
        if ($headerKey === '' && $this->isWigpleasureOrderIngress($request)) {
            return $next($request);
        }

        $globalKeys = array_filter(array_map('trim', [
            config('services.api_key'),
            config('services.sellchase_api_key'),
        ]));

        $allowGlobal = $this->globalKeysAllowed();

        if (empty($headerKey)) {
            if (empty($globalKeys)) {
                return $next($request);
            }

            return response()->json(['success' => false, 'message' => 'Invalid API key'], 401);
        }

        // 1. User-owned key: only Merchants may call the store order API
        $user = User::where('api_key', $headerKey)->first();
        if ($user) {
            if (! $user->hasRole('Merchant')) {
                Log::warning('SELLCHASE API: API key belongs to a non-merchant user', ['user_id' => $user->id]);

                return response()->json([
                    'success' => false,
                    'message' => 'Store API keys are only valid for merchant accounts.',
                ], 403);
            }
            $request->attributes->set('api_user', $user);

            return $next($request);
        }

        // 2. Global .env keys (optional; off by default outside local)
        if (! $allowGlobal || empty($globalKeys)) {
            return response()->json(['success' => false, 'message' => 'Invalid API key'], 401);
        }

        foreach ($globalKeys as $gk) {
            if (hash_equals($gk, $headerKey)) {
                $merchantUser = $this->resolveMerchantForApiKey($headerKey);
                if (! $merchantUser || ! $merchantUser->hasRole('Merchant')) {
                    Log::warning('SELLCHASE API: Global key could not resolve a merchant user');

                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid API key configuration: set SELLCHASE_MERCHANT_USER_ID to a merchant user id, or use a merchant profile API key.',
                    ], 401);
                }
                $request->attributes->set('api_user', $merchantUser);

                return $next($request);
            }
        }

        return response()->json(['success' => false, 'message' => 'Invalid API key'], 401);
    }

    /**
     * Machine ingress from the Wigpleasure store (HTTP sync). Matches routes in routes/api.php
     * that intentionally omit api_user when no key is sent.
     */
    private function isWigpleasureOrderIngress(Request $request): bool
    {
        $path = $request->path();

        if ($request->isMethod('post') && ($request->is('api/v1/orders') || $request->is('api/orders'))) {
            return true;
        }

        if ($request->isMethod('patch') && preg_match('#^api/v1/orders/\d+$#', (string) $path) === 1) {
            return true;
        }

        return false;
    }

    private function globalKeysAllowed(): bool
    {
        $configured = config('services.sellchase_allow_global_api_key');

        return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Map a shared .env key to exactly one Merchant (never Admin/Supplier by default).
     */
    private function resolveMerchantForApiKey(string $apiKey): ?User
    {
        $user = User::where('api_key', $apiKey)->first();
        if ($user && $user->hasRole('Merchant')) {
            return $user;
        }

        $configuredId = config('services.sellchase_merchant_user_id');
        if ($configuredId !== null && $configuredId !== '') {
            $merchant = User::find((int) $configuredId);
            if ($merchant && $merchant->hasRole('Merchant')) {
                $this->assignApiKeyToUser($apiKey, $merchant);

                return $merchant->fresh();
            }
        }

        $merchant = User::whereHas('roles', fn ($q) => $q->where('name', 'Merchant'))
            ->orderBy('id')
            ->first();

        if ($merchant) {
            $this->assignApiKeyToUser($apiKey, $merchant);

            return $merchant->fresh();
        }

        return null;
    }

    private function assignApiKeyToUser(string $apiKey, User $merchant): void
    {
        DB::table('users')->where('api_key', $apiKey)->where('id', '!=', $merchant->id)->update(['api_key' => null]);
        DB::table('users')->where('id', $merchant->id)->update(['api_key' => $apiKey]);
        Log::info('SELLCHASE API: رُبط المفتاح بالتاجر', ['user_id' => $merchant->id, 'email' => $merchant->email]);
    }
}
