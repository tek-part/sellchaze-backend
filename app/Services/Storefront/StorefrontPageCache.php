<?php

namespace App\Services\Storefront;

use Illuminate\Support\Facades\Cache;

/**
 * Full-page storefront cache. The cache key includes store_id, theme_version_id,
 * path, locale, and a settings hash. Invalidation is O(1) and instant: each store
 * has a "generation" counter folded into the key; bumping it (on theme activation,
 * settings change, store status/catalog change) makes all prior page entries
 * unreachable without needing wildcard deletes (works on the file cache driver).
 */
class StorefrontPageCache
{
    private const GEN_PREFIX = 'sfpage:gen:';

    private const PAGE_PREFIX = 'sfpage:html:';

    public function generation(int $storeId): int
    {
        return (int) Cache::get(self::GEN_PREFIX.$storeId, 1);
    }

    /** Instant invalidation for a store's full-page cache. */
    public function flushStore(int $storeId): void
    {
        Cache::forever(self::GEN_PREFIX.$storeId, $this->generation($storeId) + 1);
    }

    public function key(array $context, string $path, string $locale): string
    {
        $storeId = (int) ($context['store']['id'] ?? 0);
        $themeVersionId = (int) ($context['theme']['theme_version_id'] ?? 0);
        $settingsHash = sha1(json_encode($context['theme']['settings'] ?? [], JSON_UNESCAPED_UNICODE));

        $signature = implode('|', [
            $storeId,
            $this->generation($storeId),
            $themeVersionId,
            $path,
            $locale,
            $settingsHash,
        ]);

        return self::PAGE_PREFIX.sha1($signature);
    }

    public function remember(string $key, \Closure $render): string
    {
        $ttl = (int) config('sellchase.storefront.page_cache_ttl', 300);

        return Cache::remember($key, $ttl, $render);
    }
}
