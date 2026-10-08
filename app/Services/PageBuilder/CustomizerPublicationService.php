<?php

namespace App\Services\PageBuilder;

use App\Models\Store;
use App\Models\StorePage;
use App\Models\StoreTheme;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Themes\StoreThemeService;
use Illuminate\Support\Facades\DB;

class CustomizerPublicationService
{
    public function __construct(private readonly StorePageService $pages, private readonly StoreThemeService $themes) {}

    public function pageChecksum(StorePage $page): string
    {
        return hash('sha256', json_encode($this->pages->snapshot($page), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function themeChecksum(StoreTheme $install): string
    {
        return hash('sha256', json_encode([$install->draft_settings ?? $install->settings ?? [], $install->draft_custom_css ?? $install->custom_css], JSON_THROW_ON_ERROR));
    }

    public function publish(Store $store, int $pageId, int $themeId, string $pageChecksum, string $themeChecksum, ?int $actorId): StorePage
    {
        return DB::transaction(function () use ($store, $pageId, $themeId, $pageChecksum, $themeChecksum, $actorId) {
            $lockedStore = Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $page = StorePage::query()->where('store_id', $store->id)->whereKey($pageId)->lockForUpdate()->firstOrFail();
            $install = StoreTheme::query()->where('store_id', $store->id)->where('theme_id', $themeId)->lockForUpdate()->firstOrFail();
            abort_unless($install->status === 'active', 422, 'Activate this theme before publishing page and theme changes together.');
            abort_unless(hash_equals($this->pageChecksum($page), $pageChecksum) && hash_equals($this->themeChecksum($install), $themeChecksum), 409, 'The saved draft changed in another session. Reload the editor before publishing.');
            $published = $this->pages->publish($page);
            $this->themes->publish($lockedStore, $install, $actorId);
            // Earlier service invalidations may occur inside this transaction;
            // invalidate again after commit so concurrent readers cannot retain old data.
            DB::afterCommit(fn () => app(StorefrontPageCache::class)->flushStore($store->id));

            return $published;
        });
    }
}
