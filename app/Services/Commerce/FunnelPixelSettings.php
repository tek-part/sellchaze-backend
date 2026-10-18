<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreFunnel;
use App\Services\Storefront\PublishedPageResolver;

/** Browser identifiers only. Server conversion destinations remain store managed. */
class FunnelPixelSettings
{
    public function normalize(array $pixels): ?array
    {
        $clean = [];
        foreach (StoreTrackingSettings::PROVIDERS as $provider) {
            $clean[$provider] = app(StoreTrackingSettings::class)->ids($provider, $pixels[$provider] ?? []);
        }

        return array_filter($clean) ? $clean : null;
    }

    public static function rules(): array
    {
        $rules = ['browser_pixels' => ['sometimes', 'nullable', 'array:facebook,tiktok,snapchat']];
        foreach (StoreTrackingSettings::PROVIDERS as $provider) {
            $rules['browser_pixels.'.$provider] = ['sometimes', 'array', 'max:10'];
            $rules['browser_pixels.'.$provider.'.*'] = ['required', 'string', 'max:64'];
        }

        return $rules;
    }

    public function forPath(Store $store, ?string $path, array $productIds = []): ?StoreFunnel
    {
        if (! is_string($path) || ! preg_match('~^/(funnels|pages)/([a-z0-9]+(?:-[a-z0-9]+)*)/?$~D', $path, $match)) {
            return null;
        }
        $page = app(PublishedPageResolver::class)->forSlug($store, $match[2], true);
        if (! $page || ! $page->isPubliclyVisible()) {
            return null;
        }
        $funnel = StoreFunnel::withoutGlobalScopes()->where('store_id', $store->id)->where('store_page_id', $page->id)->first();
        if (! $funnel || ($productIds && array_unique(array_map('intval', $productIds)) !== [(int) $funnel->product_id])) {
            return null;
        }

        return $funnel;
    }

    public function snapshot(?StoreFunnel $funnel): array
    {
        return $funnel && $funnel->browser_pixels ? ['funnel_id' => $funnel->id, 'funnel_pixels' => $funnel->browser_pixels] : [];
    }

    /** Removed IDs stop receiving replays; newly added IDs do not inherit old purchases. */
    public function forEvent(Store $store, array $payload): array
    {
        $result = app(StoreTrackingSettings::class)->publicConfiguration($store);
        if (! isset($payload['funnel_id'], $payload['funnel_pixels'])) {
            return $result;
        }
        $funnel = StoreFunnel::withoutGlobalScopes()->where('store_id', $store->id)->with('page')->find($payload['funnel_id']);
        if (! $funnel || ! $funnel->page || (int) $funnel->page->store_id !== (int) $store->id || ! $funnel->page->isPubliclyVisible()) {
            return $result;
        }
        foreach (StoreTrackingSettings::PROVIDERS as $provider) {
            $current = $funnel->browser_pixels[$provider] ?? [];
            $captured = $payload['funnel_pixels'][$provider] ?? [];
            $result[$provider] = array_values(array_unique(array_merge($result[$provider], array_intersect($current, $captured))));
        }

        return $result;
    }
}
