<?php

namespace App\Services\Storefront;

use App\Models\Store;
use App\Models\StoreMenu;
use App\Models\StorePage;
use App\Models\StorePageSection;
use App\Services\PageBuilder\StoreMenuService;
use App\Services\Themes\SectionRegistry;
use App\Services\Themes\ThemeResolver;

/**
 * Builds the full, presentation-agnostic render context for a storefront page:
 * { store, seo, theme, page, data }. All catalog data is read under the active
 * CurrentStore tenant (fail-closed StoreScope), so the context can never contain
 * another store's data. This is the ONLY thing handed to the React SSR runtime —
 * themes never touch the DB or the API directly.
 */
class StorefrontContextBuilder
{
    public function __construct(
        private readonly ThemeResolver $resolver,
        private readonly SectionRegistry $sections,
        private readonly StorefrontService $storefront,
        private readonly StoreSeoService $seo,
        private readonly StoreMenuService $menus,
    ) {}

    /**
     * Build the render context for a dynamic page (Task 11): same shape as build(),
     * with the page's own (schema-validated, reusable-expanded) sections, page SEO,
     * navigation menus, and shared catalog data. Renders through the same pipeline.
     */
    public function buildPage(Store $store, StorePage $page, ?array $themeOverride = null): array
    {
        $theme = $themeOverride ?? $this->resolver->resolve($store);

        // Eager-load sections + their reusable blocks in a constant number of
        // queries (idempotent — no-op if the caller already loaded them). Prevents
        // an N+1 where each reusable section would otherwise lazy-load on render.
        $page->loadMissing('sections.reusable');

        $rawSections = $page->sections->map(fn (StorePageSection $s) => $this->expandSection($s))->all();
        $pageSections = $this->sections->resolveSections($theme['sections_schema'], $rawSections);

        return [
            'store' => $this->storeSummary($store),
            'seo' => $this->seo->forPage($store, $page),
            'theme' => $this->themeBlock($theme),
            'page' => [
                'template' => $page->template,
                'slug' => $page->slug,
                'sections' => $pageSections,
            ],
            'data' => $this->commonData($store),
            'navigation' => $this->navigation($store),
        ];
    }

    /** Reusable sections resolve to their global type + settings (page settings override). */
    private function expandSection(StorePageSection $section): array
    {
        if ($section->reusable_section_id && $section->reusable) {
            return [
                'type' => $section->reusable->type,
                'settings' => array_merge($section->reusable->settings ?? [], $section->settings ?? []),
            ];
        }

        return ['type' => $section->type, 'settings' => $section->settings ?? []];
    }

    private function commonData(Store $store): array
    {
        $home = $this->storefront->homepage($store);

        return ['products' => $home['featured_products'], 'categories' => $home['categories'], 'hero' => $home['hero']];
    }

    private function navigation(Store $store): array
    {
        // One query for both nav menus instead of two (hot public render path).
        $menus = StoreMenu::query()
            ->where('store_id', $store->id)
            ->whereIn('handle', ['header', 'footer'])
            ->get()->keyBy('handle');
        $header = $menus->get('header');
        $footer = $menus->get('footer');

        return [
            'branding' => ['name' => $store->name, 'logo_url' => $store->logoUrl()],
            'header' => $header ? $this->menus->tree($header) : [],
            'footer' => $footer ? $this->menus->tree($footer) : [],
        ];
    }

    private function themeBlock(array $theme): array
    {
        return [
            'key' => $theme['key'],
            'version' => $theme['version'],
            'theme_version_id' => $theme['theme_version_id'],
            'bundle_url' => $theme['bundle_url'] ?? null,
            'settings' => $theme['settings'],
        ];
    }

    /**
     * @param  string  $template  home|product|category
     * @param  array  $params  e.g. ['slug' => '...']
     * @return array|null null when the requested product/category does not exist
     */
    /**
     * @param  array|null  $themeOverride  a resolved theme context (preview / upgrade preview);
     *                                     when null the store's active theme is used.
     */
    public function build(Store $store, string $template, array $params = [], ?array $themeOverride = null): ?array
    {
        $theme = $themeOverride ?? $this->resolver->resolve($store);

        if ($theme === null) {
            return null;
        }

        $templateDef = $this->resolver->template($theme, $template) ?? ['sections' => []];
        $pageSections = $this->sections->resolveSections($theme['sections_schema'], $templateDef['sections'] ?? []);

        [$seo, $data] = $this->pageData($store, $template, $params);
        if ($data === null) {
            return null; // 404 (product/category not found)
        }

        return [
            'store' => $this->storeSummary($store),
            'seo' => $seo,
            'theme' => $this->themeBlock($theme),
            'page' => [
                'template' => $template,
                'sections' => $pageSections,
            ],
            'data' => $data,
            'navigation' => $this->navigation($store),
        ];
    }

    /** @return array{0:array,1:array|null} [seo, data] */
    private function pageData(Store $store, string $template, array $params): array
    {
        switch ($template) {
            case 'product':
                $product = $this->storefront->product($params['slug'] ?? '');
                if ($product === null) {
                    return [[], null];
                }

                $product->loadMissing('category:id,name,slug');
                $related = collect($this->storefront->products($product->category?->slug, 12)->items())
                    ->reject(fn ($p) => (int) $p->id === (int) $product->id)
                    ->take(8)
                    ->map(fn ($p) => $this->storefront->productArray($p))
                    ->values()
                    ->all();

                return [
                    $this->seo->forProduct($store, $product),
                    [
                        'product' => array_merge(
                            $this->storefront->productArray($product),
                            ['description' => $product->description],
                        ),
                        'related' => $related,
                    ],
                ];

            case 'category':
                $category = $this->storefront->category($params['slug'] ?? '');
                if ($category === null) {
                    return [[], null];
                }
                $products = $this->storefront->products($category->slug, 24);
                $rows = collect($products->items())->map(fn ($p) => $this->storefront->productArray($p))->all();
                $prices = array_map(fn ($p) => (float) $p['price'], $rows);

                return [
                    $this->seo->forCategory($store, $category),
                    [
                        'category' => ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug, 'description' => $category->description, 'image_url' => $category->imageUrl()],
                        'products' => $rows,
                        'categories' => $this->storefront->categories()->map(fn ($c) => [
                            'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug,
                            'products_count' => (int) $c->products_count,
                        ])->all(),
                        'total' => $products->total(),
                        'price_min' => $prices ? (int) floor(min($prices)) : 0,
                        'price_max' => $prices ? (int) ceil(max($prices)) : 0,
                    ],
                ];

            case 'home':
            default:
                $home = $this->storefront->homepage($store);

                return [
                    $this->seo->forStore($store),
                    [
                        'hero' => $home['hero'],
                        'products' => $home['featured_products'],
                        'categories' => $home['categories'],
                    ],
                ];
        }
    }

    private function storeSummary(Store $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'description' => $store->description,
            'currency' => $store->currency,
            'status' => $store->status,
            'email' => $store->email,
            'phone' => $store->phone,
            'logo_url' => $store->logoUrl(),
            'banner_url' => $store->bannerUrl(),
        ];
    }
}
