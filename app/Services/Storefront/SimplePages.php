<?php

namespace App\Services\Storefront;

use App\Models\Store;
use App\Models\StorePage;
use App\Support\Localization\LocalizedValue;
use App\Support\ProductDescription;
use Illuminate\Support\Facades\DB;

/** Rich-content pages share page URLs/publications without depending on a theme's section library. */
class SimplePages
{
    public function publicData(Store $store, StorePage $page, string $locale, ?array $publication = null): array
    {
        $publication ??= app(PublishedPageResolver::class)->latestPublication($page);
        $config = $publication['page']['simple_configuration'] ?? [];

        return ['title' => (string) LocalizedValue::pick($config['title'] ?? [], $locale, $store->default_locale ?: 'en'),
            'content_html' => ProductDescription::clean(LocalizedValue::pick($config['content'] ?? [], $locale, $store->default_locale ?: 'en'), true) ?? '',
            'show_in_header' => (bool) ($config['show_in_header'] ?? false),
            'show_in_footer' => (bool) ($config['show_in_footer'] ?? false),
            'position' => (int) ($config['position'] ?? 0)];
    }

    public function navigation(Store $store, array $navigation, string $locale): array
    {
        $pages = StorePage::withoutGlobalScopes()->where('store_id', $store->id)->where('template', 'simple')
            ->where('status', 'published')->orderBy('id')->get();
        $latest = DB::table('store_page_publications')->where('store_id', $store->id)->whereIn('store_page_id', $pages->modelKeys())
            ->select('store_page_id')->selectRaw('MAX(version) as latest_version')->groupBy('store_page_id');
        $snapshots = DB::table('store_page_publications as publication')->joinSub($latest, 'latest', fn ($join) => $join
            ->on('publication.store_page_id', '=', 'latest.store_page_id')->on('publication.version', '=', 'latest.latest_version'))
            ->where('publication.store_id', $store->id)->pluck('publication.snapshot', 'publication.store_page_id');
        $rows = $pages->map(function (StorePage $page) use ($store, $locale, $snapshots) {
            $publication = json_decode((string) ($snapshots[$page->id] ?? ''), true);
            if (! $publication || ($publication['page']['simple_configuration']['archived'] ?? false)) {
                return null;
            }

            return ['page' => $page, 'data' => $this->publicData($store, $page, $locale, $publication), 'labels' => $publication['page']['simple_configuration']['title'] ?? []];
        })->filter()->sortBy(fn ($row) => $row['data']['position'])->values();
        foreach (['header', 'footer'] as $handle) {
            $items = $navigation[$handle] ?? [];
            foreach ($rows as $row) {
                $page = $row['page'];
                $data = $row['data'];
                if (! $data['show_in_'.$handle] || $data['title'] === '' || $this->hasUrl($items, $page->publicPath())) {
                    continue;
                }
                $items[] = ['id' => 'simple-'.$page->id, 'label' => $data['title'], 'label_i18n' => $row['labels'], 'type' => 'internal', 'automatic_page' => true,
                    'target' => ltrim($page->publicPath(), '/'), 'url' => $page->publicPath(), 'children' => []];
            }
            $navigation[$handle] = $items;
        }

        return $navigation;
    }

    private function hasUrl(array $items, string $url): bool
    {
        foreach ($items as $item) {
            if (rtrim((string) ($item['url'] ?? ''), '/') === $url || $this->hasUrl($item['children'] ?? [], $url)) {
                return true;
            }
        }

        return false;
    }
}
