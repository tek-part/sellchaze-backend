<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StorePage;
use App\Services\Storefront\PublishedPageResolver;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StoreSeoService;
use App\Services\Themes\SectionRegistry;
use App\Services\Themes\ThemeResolver;
use App\Support\Localization\LocaleContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Public section layouts for the storefront SPA (Salla-style customizer output).
 * Only PUBLISHED snapshots are ever served: the merchant's draft lives behind the
 * owner endpoints and the postMessage live preview. Responses are cached per
 * store/locale/theme version/publication and invalidated through the store's page
 * cache generation (bumped on publish, unpublish, section sync, theme changes).
 */
class StorefrontLayoutController extends Controller
{
    use ResolvesStorefront;

    /** Templates the SPA can ask for; `product`/`category` still come from the manifest. */
    private const TEMPLATES = ['home'];

    public function __construct(
        private readonly PublishedPageResolver $pages,
        private readonly ThemeResolver $themes,
        private readonly SectionRegistry $sections,
        private readonly StorefrontPageCache $cache,
        private readonly StoreSeoService $seo,
        private readonly LocaleContext $locale,
    ) {}

    /**
     * GET /storefront/layout?template=home
     * → { data: { template, source: store|theme, page_id, locale, sections: [{id,type,settings}] } }
     */
    public function layout(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $data = $request->validate(['template' => ['nullable', 'string', Rule::in(self::TEMPLATES)]]);
        $template = $data['template'] ?? 'home';
        $locale = $this->locale->current();

        $theme = $this->themes->resolve($store, $locale) ?? [];
        $page = $this->pages->publishedHome($store, $locale);

        $key = $this->key($store, 'layout', $template, $locale, $theme, $page);
        $payload = Cache::remember($key, $this->ttl(), function () use ($template, $theme, $page) {
            $source = $page ? 'store' : 'theme';
            $raw = $page
                ? $this->pages->publicSections($page)
                : collect($theme['templates'][$template]['sections'] ?? [])->values()
                    ->map(fn ($section, int $index) => ['id' => 'theme-'.$index, ...(is_array($section) ? $section : [])])->all();

            return [
                'template' => $template,
                'source' => $source,
                'page_id' => $page?->id,
                'locale' => $page?->locale,
                'sections' => $this->sections->resolveSections($theme['sections_schema'] ?? [], $raw),
            ];
        });
        $payload['locale'] ??= $locale;

        return response()->json(['data' => $payload], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /storefront/pages/{slug} → { data: { id, title, slug, template, locale, seo, sections } }
     * for a published custom page (`page|landing`), picking the locale sibling like the
     * Blade route does; 404 JSON otherwise (drafts, future-scheduled, unknown, `home`).
     */
    public function page(Request $request, string $slug): JsonResponse
    {
        $store = $this->currentStore($request);
        $page = $this->pages->forSlug($store, $slug);
        if ($page === null || ! $page->isPubliclyVisible()) {
            return response()->json(['message' => 'Page not found.'], 404);
        }

        $locale = $this->locale->current();
        $theme = $this->themes->resolve($store, $locale) ?? [];
        $key = $this->key($store, 'page', $slug, $locale, $theme, $page);
        $payload = Cache::remember($key, $this->ttl(), function () use ($store, $theme, $page) {
            $publication = $this->pages->latestPublication($page);
            $snapshot = new StorePage($publication['page'] ?? []);
            $snapshot->forceFill(['id' => $page->id, 'store_id' => $page->store_id, 'status' => $page->status, 'publish_at' => $page->publish_at]);
            $title = $publication['page']['title'] ?? $page->title;

            return [
                'id' => $page->id,
                'title' => $title,
                'slug' => $page->published_slug ?? $page->slug,
                'template' => $publication['page']['template'] ?? $page->template,
                'locale' => $page->locale,
                'seo' => $this->seo->forPage($store, $publication ? $snapshot : $page),
                'sections' => $this->sections->resolveSections($theme['sections_schema'] ?? [], $this->pages->publicSections($page, $publication)),
            ];
        });

        return response()->json(['data' => $payload], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** Key = store + page-cache generation + theme version + locale + subject + publication version. */
    private function key(Store $store, string $kind, string $subject, string $locale, array $theme, ?StorePage $page): string
    {
        return 'sflayout:'.sha1(implode('|', [
            $store->id,
            $this->cache->generation($store->id),
            (int) ($theme['theme_version_id'] ?? 0),
            $kind,
            $subject,
            $locale,
            $page?->id ?? 0,
            $page ? $this->pages->publicationVersion($page) : 0,
        ]));
    }

    private function ttl(): int
    {
        return (int) config('sellchase.storefront.page_cache_ttl', 300);
    }
}
