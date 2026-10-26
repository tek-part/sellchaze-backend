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

    public function capture(Store $store, array $data, bool $bot = false): void
    {
        $productView = $data['kind'] === 'product_view';
        // A product view counts once per visitor session and product, whatever event id the browser retries with.
        $event = $productView
            ? hash_hmac('sha256', 'store:'.$store->id.':view:'.strtolower($data['session_id']).':'.(int) $data['product_id'], (string) config('app.key'))
            : hash_hmac('sha256', 'store:'.$store->id.':event:'.strtolower($data['event_id']), (string) config('app.key'));
        if (($productView && $bot) || DB::table('storefront_events')->where('store_id', $store->id)->where('event_key', $event)->exists()) {
            return;
        }
        $product = null;
        if ($data['kind'] !== 'visit') {
            $product = Product::withoutGlobalScopes()->where('store_id', $store->id)->where('is_active', true)->find($data['product_id']);
            if (! $product) {
                throw ValidationException::withMessages(['product_id' => 'Choose a current product from this store.']);
            }
        }
        $campaign = $this->campaign($data);
        $session = $this->sessionKey($store, $data['session_id']);
        // A retry preserves its first receipt time, product and source, including after deletion.
        $inserted = DB::table('storefront_events')->insertOrIgnore([
            'store_id' => $store->id, 'event_key' => $event, 'session_key' => $session,
            'kind' => $data['kind'], 'source' => $data['source'] ?? 'storefront', 'product_id' => $product?->id,
            'quantity' => $data['kind'] === 'add_to_cart' ? (int) $data['quantity'] : null, 'occurred_at' => now(),
        ] + $campaign);
        if (! $inserted || $bot) {
            return;
        }
        if (array_filter($campaign)) {
            DB::table('storefront_session_campaigns')->upsert([['store_id' => $store->id, 'session_key' => $session, 'updated_at' => now()] + $campaign],
                ['store_id', 'session_key'], ['utm_source', 'utm_medium', 'utm_campaign', 'updated_at']);
        }
        if ($product) {
            app(ProductAnalytics::class)->increment($store, $product->id, $session, $data['kind'], (int) ($data['quantity'] ?? 0), $campaign);
        }
    }

    /** @return array{utm_source:?string,utm_medium:?string,utm_campaign:?string} */
    private function campaign(array $data): array
    {
        $clean = fn (?string $value): ?string => $value === null ? null : (trim((string) preg_replace('/\s+/u', ' ', $value)) ?: null);

        return ['utm_source' => $clean($data['utm_source'] ?? null), 'utm_medium' => $clean($data['utm_medium'] ?? null), 'utm_campaign' => $clean($data['utm_campaign'] ?? null)];
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
