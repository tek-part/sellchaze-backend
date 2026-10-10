<?php

namespace App\Services\Commerce;

use App\Models\StoreOrder;
use Illuminate\Support\Facades\Config;

/** A purpose-specific capability; order numbers alone never grant access. */
class OrderReceipt
{
    public const TTL = 2592000;

    private function signature(string $payload): string
    {
        $key = (string) Config::get('app.key');
        if ($key === '') {
            throw new \LogicException('An application key is required for private order receipts.');
        }

        return hash_hmac('sha256', 'order-receipt:v1:'.$payload, $key);
    }

    public function make(StoreOrder $order, ?int $ttl = null): array
    {
        $payload = $order->store_id.':'.$order->id.':'.(time() + ($ttl ?? self::TTL));

        return ['token' => rtrim(strtr(base64_encode($payload), '+/', '-_'), '=').'.'.$this->signature($payload), 'expires_in' => $ttl ?? self::TTL];
    }

    public function verify(string $token, int $storeId): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        $payload = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($payload === false || ! preg_match('/^([1-9][0-9]*):([1-9][0-9]*):([0-9]+)$/', $payload, $values)
            || ! hash_equals($this->signature($payload), $parts[1]) || (int) $values[1] !== $storeId || (int) $values[3] <= time()) {
            return null;
        }

        return (int) $values[2];
    }
}
