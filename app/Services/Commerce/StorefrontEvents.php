<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** First-party counters only: no URL, referrer, IP, customer contact or advertising identifiers. */
class StorefrontEvents
{
    public function sessionKey(Store $store, string $session): string
    {
        return hash_hmac('sha256', 'store:'.$store->id.':session:'.strtolower($session), (string) config('app.key'));
    }

    public function capture(Store $store, array $data): void
    {
        $event = hash_hmac('sha256', 'store:'.$store->id.':event:'.strtolower($data['event_id']), (string) config('app.key'));
        if (DB::table('storefront_events')->where('store_id', $store->id)->where('event_key', $event)->exists()) {
            return;
        }
        $product = null;
        if ($data['kind'] !== 'visit') {
            $product = Product::withoutGlobalScopes()->where('store_id', $store->id)->where('is_active', true)->find($data['product_id']);
            if (! $product) {
                throw ValidationException::withMessages(['product_id' => 'Choose a current product from this store.']);
            }
        }
        // A retry preserves its first receipt time, product and source, including after deletion.
        DB::table('storefront_events')->insertOrIgnore([
            'store_id' => $store->id, 'event_key' => $event, 'session_key' => $this->sessionKey($store, $data['session_id']),
            'kind' => $data['kind'], 'source' => $data['source'] ?? 'storefront', 'product_id' => $product?->id,
            'quantity' => $data['kind'] === 'add_to_cart' ? (int) $data['quantity'] : null, 'occurred_at' => now(),
        ]);
    }

    /** Analytics failure/absence must never prevent checkout or assign a fabricated visit. */
    public function orderSession(Store $store, ?string $session): ?string
    {
        if (! $session) {
            return null;
        }
        $key = $this->sessionKey($store, $session);

        return DB::table('storefront_events')->where('store_id', $store->id)->where('session_key', $key)->where('kind', 'visit')
            ->where('occurred_at', '>=', now()->subDays(2))->exists() ? $key : null;
    }
}
