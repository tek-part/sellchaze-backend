<?php

namespace App\Services\Commerce;

use Illuminate\Support\Facades\Config;

class PaymentRetryToken
{
    private function key(): string
    {
        $key = (string) Config::get('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        return $key ?: 'sellchase-payment-retry';
    }

    public function make(int $storeId, int $orderId, int $ttlSeconds = 7200): string
    {
        $payload = $storeId.':'.$orderId.':'.(time() + $ttlSeconds);
        $signature = hash_hmac('sha256', $payload, $this->key());

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=').'.'.$signature;
    }

    public function verify(?string $token, int $storeId): ?int
    {
        if (! $token || ! str_contains($token, '.')) {
            return null;
        }

        [$encoded, $signature] = explode('.', $token, 2);
        $payload = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($payload === false || ! hash_equals(hash_hmac('sha256', $payload, $this->key()), $signature)) {
            return null;
        }

        $parts = array_map('intval', explode(':', $payload));
        if (count($parts) !== 3 || $parts[0] !== $storeId || $parts[1] <= 0 || $parts[2] < time()) {
            return null;
        }

        return $parts[1];
    }
}
