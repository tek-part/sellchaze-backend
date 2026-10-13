<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Models\StoreApiKey;
use App\Models\User;
use App\Support\Tenancy\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateStoreApiKey
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $ipBucket = 'store-external-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipBucket, 120)) {
            return response()->json(['message' => 'Too many requests.'], 429, ['Retry-After' => RateLimiter::availableIn($ipBucket), 'Cache-Control' => 'private, no-store']);
        }
        RateLimiter::hit($ipBucket, 60);
        $api = trim((string) $request->header('Api-Key', ''));
        $bearer = $request->bearerToken();
        abort_if($api !== '' && $bearer && ! hash_equals($api, $bearer), 401, 'Conflicting API credentials.');
        $token = $api !== '' ? $api : ($bearer ?? '');
        abort_unless(preg_match('/^sc_[A-Za-z0-9]{64}$/D', $token), 401, 'Invalid API key.');
        $key = StoreApiKey::where('token_hash', hash('sha256', $token))->where('enabled', true)->first();
        abort_unless($key, 401, 'Invalid API key.');
        $store = Store::whereKey($key->store_id)->where('owner_user_id', $key->user_id)->where('status', 'active')->first();
        $owner = User::whereKey($key->user_id)->where('is_active', true)->where('pending_approval', false)->first();
        abort_unless($store && $owner && $owner->hasAnyRole(['Merchant', 'Supplier']), 401, 'Invalid API key.');
        $bucket = 'store-external-api:'.$key->id;
        if (RateLimiter::tooManyAttempts($bucket, 40)) {
            return response()->json(['message' => 'Too many requests.'], 429, ['Retry-After' => RateLimiter::availableIn($bucket), 'Cache-Control' => 'private, no-store']);
        }
        RateLimiter::hit($bucket, 60);
        abort_unless(in_array($permission, $key->permissions, true), 403, 'API key lacks this permission.');
        $previous = app(CurrentStore::class)->get();
        $previousUser = Auth::user();
        app(CurrentStore::class)->set($store);
        Auth::setUser($owner);
        $request->setUserResolver(fn () => $owner);
        $request->attributes->set('store', $store);
        $request->attributes->set('store_api_key', $key);
        $route = $request->route();
        if ($route) {
            (function ($value): void {
                $this->parameters = ['store' => $value] + $this->parameters;
            })->call($route, $store);
        }
        try {
            $response = $next($request);
            if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
                StoreApiKey::whereKey($key->id)->update(['last_used_at' => now()]);
            }

            return $response;
        } finally {
            app(CurrentStore::class)->set($previous);
            if ($previousUser) {
                Auth::setUser($previousUser);
            } else {
                Auth::forgetUser();
            }
        }
    }
}
