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

    /**
     * Records one event. With $aggregate false (bot user agents) the raw counter is kept
     * but nothing reaches the product rollup or session campaigns. The raw row, the
     * session campaign and the rollup commit together, so a failed increment rolls the
     * event back and the browser's retry records it again.
     */
    public function capture(Store $store, array $data, bool $aggregate = true): void
    {
        $productView = $data['kind'] === 'product_view';
        // A product view counts once per visitor session and product, whatever event id the browser retries with.
        $event = $productView
            ? hash_hmac('sha256', 'store:'.$store->id.':view:'.strtolower($data['session_id']).':'.(int) $data['product_id'], (string) config('app.key'))
            : hash_hmac('sha256', 'store:'.$store->id.':event:'.strtolower($data['event_id']), (string) config('app.key'));
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
        $analytics = app(ProductAnalytics::class);
        $campaign = $analytics->normaliseCampaign($data);
        $session = $this->sessionKey($store, $data['session_id']);
        DB::transaction(function () use ($store, $data, $event, $session, $product, $campaign, $aggregate, $analytics) {
            // A retry preserves its first receipt time, product and source, including after deletion.
            $inserted = DB::table('storefront_events')->insertOrIgnore([
                'store_id' => $store->id, 'event_key' => $event, 'session_key' => $session,
                'kind' => $data['kind'], 'source' => $data['source'] ?? 'storefront', 'product_id' => $product?->id,
                'quantity' => $data['kind'] === 'add_to_cart' ? (int) $data['quantity'] : null, 'occurred_at' => now(),
            ] + $campaign);
            if (! $inserted || ! $aggregate) {
                return;
            }
            $campaign = $analytics->boundedCampaign($store, $campaign);
            if (array_filter($campaign)) {
                DB::table('storefront_session_campaigns')->upsert([['store_id' => $store->id, 'session_key' => $session, 'updated_at' => now()] + $campaign],
                    ['store_id', 'session_key'], ['utm_source', 'utm_medium', 'utm_campaign', 'updated_at']);
            }
            if ($product) {
                $analytics->increment($store, $product->id, $session, $data['kind'], (int) ($data['quantity'] ?? 0), $campaign);
            }
        });
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
