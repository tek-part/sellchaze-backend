<?php

namespace App\Services\Storefront;

use App\Models\Scopes\StoreScope;
use App\Models\Store;
use App\Models\StorePage;
use App\Models\StorePageSection;
use App\Support\Localization\LocaleContext;
use Illuminate\Support\Facades\DB;

/**
 * Picks the public Page Builder page for a request (custom pages by slug, the
 * `home` template page by locale) and exposes its PUBLISHED snapshot. Shared by the
 * Blade/SSR controller, the JSON layout API and the render-context builder so all
 * three always agree on which page — and which sections — the storefront shows.
 *
 * Queries opt out of the fail-closed StoreScope and filter by store id explicitly:
 * the resolver is used from host-resolved storefront requests as well as from owner
 * routes and commands.
 */
class PublishedPageResolver
{
    public const HOME = 'home';

    /**
     * A custom (`page|landing`) page by public slug. A slug may exist once per locale
     * (sibling rows): prefer a publicly visible sibling in the request locale, then the
     * store default locale, then any visible one; keep hidden rows as a last resort so
     * the caller's visibility check yields a 404 rather than a silent locale fallback.
     * The `home` template page is never resolvable by slug.
     */
    public function forSlug(Store $store, string $slug): ?StorePage
    {
        $siblings = StorePage::query()->withoutGlobalScope(StoreScope::class)
            ->where('store_id', $store->id)
            ->where('template', '!=', self::HOME)
            ->where(function ($query) use ($slug) {
                $query->where('published_slug', $slug)->orWhere(fn ($legacy) => $legacy->whereNull('published_slug')->where('slug', $slug));
            })
            ->orderBy('id')->get();

        if ($siblings->isEmpty()) {
            return null;
        }

        [$current, $fallback] = $this->locales($store);

        foreach ([$current, $fallback] as $locale) {
            $match = $siblings->first(fn (StorePage $p) => $p->locale === $locale && $p->isPubliclyVisible());
            if ($match) {
                return $match;
            }
        }

        return $siblings->first(fn (StorePage $p) => $p->isPubliclyVisible())
            ?? $siblings->first(fn (StorePage $p) => $p->locale === $current)
            ?? $siblings->first();
    }

    /**
     * The publicly visible `home` page for the request locale, falling back to the
     * store default locale's home page. Null when the store has not published a home
     * layout yet (callers fall back to the theme manifest template).
     */
    public function publishedHome(Store $store, ?string $locale = null): ?StorePage
    {
        [$current, $fallback] = $this->locales($store);
        $locale ??= $current;

        $pages = StorePage::query()->withoutGlobalScope(StoreScope::class)
            ->where('store_id', $store->id)
            ->where('template', self::HOME)
            ->whereIn('locale', array_unique([$locale, $fallback]))
            ->orderBy('id')->get();

        foreach (array_unique([$locale, $fallback]) as $candidate) {
            $match = $pages->first(fn (StorePage $p) => $p->locale === $candidate && $p->isPubliclyVisible());
            if ($match) {
                return $match;
            }
        }

        return null;
    }

    /** The latest publication snapshot `{page, sections}` for a page, or null if never published. */
    public function latestPublication(StorePage $page): ?array
    {
        $json = DB::table('store_page_publications')->where('store_page_id', $page->id)->orderByDesc('version')->value('snapshot');
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /** The latest publication version number (0 = never published). Cheap: used in cache keys. */
    public function publicationVersion(StorePage $page): int
    {
        return (int) DB::table('store_page_publications')->where('store_page_id', $page->id)->max('version');
    }

    /**
     * The visible sections the public should see for a page: the published snapshot
     * when one exists (the normal case), otherwise the live rows (a scheduled page that
     * became visible before the publisher ran). Reusable sections are already expanded.
     * Ids are positional (`published-{i}`) and stable across renders of one snapshot.
     *
     * @return list<array{id:string,type:string,settings:array}>
     */
    public function publicSections(StorePage $page, ?array $publication = null): array
    {
        $publication ??= $this->latestPublication($page);

        if ($publication !== null) {
            $rows = collect($publication['sections'] ?? [])
                ->filter(fn ($section) => is_array($section) && ($section['is_visible'] ?? true))
                ->values();
        } else {
            $page->loadMissing('sections.reusable');
            $rows = $page->sections->where('is_visible', true)->values()->map(fn (StorePageSection $s) => $this->expand($s));
        }

        return $rows->map(fn ($section, int $index) => [
            'id' => 'published-'.$index,
            'type' => (string) ($section['type'] ?? ''),
            'settings' => is_array($section['settings'] ?? null) ? $section['settings'] : [],
        ])->all();
    }

    /** @return array{0:string,1:string} [request locale (or store default), store default] */
    private function locales(Store $store): array
    {
        $context = app(LocaleContext::class);
        $fallback = LocaleContext::storeDefault($store);

        return [$context->has() ? $context->current() : $fallback, $fallback];
    }

    private function expand(StorePageSection $section): array
    {
        if ($section->reusable_section_id && $section->reusable) {
            return [
                'type' => $section->reusable->type,
                'settings' => array_merge($section->reusable->settings ?? [], $section->settings ?? []),
            ];
        }

        return ['type' => $section->type, 'settings' => $section->settings ?? []];
    }
}
