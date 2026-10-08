<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StorePage;
use App\Models\Theme;
use App\Services\Storefront\PublishedPageResolver;
use App\Services\Storefront\SpaShell;
use App\Services\Storefront\StorefrontContextBuilder;
use App\Services\Storefront\StorefrontRenderer;
use App\Services\Storefront\StorefrontService;
use App\Services\Storefront\StoreSeoService;
use App\Services\Themes\ThemePreviewToken;
use App\Services\Themes\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public storefront pages, now rendered by the Phase 4B theme runtime:
 * host -> resolved store -> theme context -> (React SSR | Blade fallback) -> HTML.
 * Store resolution is fully host-based (nike.sellchase.com and future nike.com).
 * The "/products" listing, sitemap, and robots remain lightweight non-theme pages.
 */
class StorefrontPageController extends Controller
{
    public function __construct(
        private readonly StorefrontService $storefront,
        private readonly StoreSeoService $seo,
        private readonly StorefrontContextBuilder $builder,
        private readonly StorefrontRenderer $renderer,
    ) {}

    /** Serve a same-origin manifest instead of letting the SPA fallback return HTML. */
    public function manifest(Request $request): JsonResponse
    {
        $store = $this->store($request);

        return response()->json([
            'id' => '/',
            'name' => $store->name,
            'short_name' => $store->name,
            'start_url' => '/?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#073f4b',
            'lang' => app()->getLocale(),
            'dir' => app()->getLocale() === 'ar' ? 'rtl' : 'ltr',
            'icons' => [[
                'src' => app(SpaShell::class)->assetOrigin().'/pwa/icon.svg',
                'sizes' => 'any',
                'type' => 'image/svg+xml',
                'purpose' => 'any maskable',
            ]],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'private, no-cache'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Host-agnostic root. A resolved store renders its themed homepage; the main
     * app domain (no store) returns the API welcome payload.
     */
    public function root(Request $request): Response|JsonResponse
    {
        $store = $request->attributes->get('store');

        if (! $store instanceof Store) {
            abort_unless($this->isAppHost($request->getHost()), 404, 'Store not found.');

            return response()->json([
                'app' => 'Sellchaze API',
                'api' => url('/api/v1'),
                'frontend' => 'Run the React app separately (see frontend/README.md).',
            ]);
        }

        // Owner draft preview of the `home` template page (signed page token, bound to this store).
        if (is_string($token = $request->query('__preview')) && $token !== '') {
            $previewPageId = app(ThemePreviewToken::class)->verifyPage($token, $store->id);
            $page = $previewPageId !== null ? StorePage::query()->where('store_id', $store->id)->where('template', 'home')->find($previewPageId) : null;
            if ($page) {
                $context = $this->builder->buildPage($store, $page);

                return response($this->renderer->renderFresh($context))->header('X-Robots-Tag', 'noindex, nofollow');
            }
        }

        return $this->renderTemplate($request, $store, 'home');
    }

    /**
     * Fallback for every other GET on a tenant host (cart, checkout, account, search, …): the
     * React storefront owns client-side routing, so serve the shell with the store's SEO defaults.
     */
    public function spa(Request $request): Response
    {
        abort_unless($request->isMethod('GET') || $request->isMethod('HEAD'), 404);
        abort_if($request->is('api/*') || $request->is('api'), 404, 'Not found.');
        $store = $this->store($request);
        abort_unless($this->renderer->usesSpaShell(), 404, 'Not found.');

        $context = $this->builder->build($store, 'home');
        abort_if($context === null, 404, 'Store not found.');

        return $this->publicResponse($this->renderer->render($request, $context), $context);
    }

    private function isAppHost(string $host): bool
    {
        $host = strtolower($host);
        $appHost = strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'));
        $base = strtolower((string) config('sellchase.storefront.base_domain', 'sellchaze.com'));

        return in_array($host, [$appHost, $base, 'localhost', '127.0.0.1'], true);
    }

    /** Lightweight non-theme listing (all products). */
    public function products(Request $request): Response
    {
        // The React storefront owns the catalogue listing when the shell is deployed.
        if ($this->renderer->usesSpaShell()) {
            return $this->spa($request);
        }

        $store = $this->store($request);

        return response(
            view('storefront.products', [
                'store' => $store,
                'seo' => $this->seo->forStore($store),
                'products' => $this->storefront->products($request->query('category'), 24),
            ])->render()
        );
    }

    public function product(Request $request, string $slug): Response
    {
        return $this->renderTemplate($request, $this->store($request), 'product', ['slug' => $slug]);
    }

    public function category(Request $request, string $slug): Response
    {
        return $this->renderTemplate($request, $this->store($request), 'category', ['slug' => $slug]);
    }

    /** Build the theme context for a template and render it (SSR -> Blade fallback, cached). */
    private function renderTemplate(Request $request, Store $store, string $template, array $params = []): Response
    {
        $preview = $this->resolvePreview($request, $store);

        $context = $this->builder->build($store, $template, $params, $preview);
        abort_if($context === null, 404, ucfirst($template).' not found.');

        // Preview: uncached fresh render, never indexed — active theme + cache untouched.
        if ($preview !== null) {
            return response($this->renderer->renderFresh($context))
                ->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $this->publicResponse($this->renderer->render($request, $context), $context);
    }

    /**
     * A valid, signed ?__preview token yields a resolved theme context to render
     * (the installed candidate, or a specific version for an upgrade preview).
     */
    private function resolvePreview(Request $request, Store $store): ?array
    {
        return app(ThemeResolver::class)->resolvePreview($store, $request->query('__preview'));
    }

    /** GET /pages/{slug} — dynamic Page Builder page (Task 11). */
    public function page(Request $request, string $slug): Response
    {
        $store = $this->store($request);

        // Owner draft preview via a signed token (isolation: token binds store + page).
        $previewPageId = null;
        if (is_string($token = $request->query('__preview')) && $token !== '') {
            $previewPageId = app(ThemePreviewToken::class)->verifyPage($token, $store->id);
        }

        $page = $previewPageId !== null
            ? StorePage::query()->where('store_id', $store->id)->find($previewPageId)
            : $this->pageForLocale($store, $slug);
        abort_if($page === null, 404, 'Page not found.');

        $isPreview = $previewPageId !== null && (int) $previewPageId === (int) $page->id;
        abort_unless($isPreview || $page->isPubliclyVisible(), 404, 'Page not found.'); // drafts/future hidden

        $publication = $isPreview ? null : app(PublishedPageResolver::class)->latestPublication($page);
        $context = $this->builder->buildPage($store, $page, null, $publication);

        if ($isPreview) {
            return response($this->renderer->renderFresh($context))->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $this->publicResponse($this->renderer->render($request, $context), $context);
    }

    /** Sibling selection (one slug per locale) lives in PublishedPageResolver, shared with the JSON layout API. */
    private function pageForLocale(Store $store, string $slug): ?StorePage
    {
        return app(PublishedPageResolver::class)->forSlug($store, $slug);
    }

    public function sitemap(Request $request): Response
    {
        return response($this->seo->sitemap($this->store($request)), 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(Request $request): Response
    {
        return response($this->seo->robots($this->store($request)), 200, ['Content-Type' => 'text/plain']);
    }

    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404, 'Store not found.');

        return $store;
    }

    private function publicResponse(string $html, array $context): Response
    {
        $etag = '"'.hash('sha256', $html).'"';
        $isSpa = str_contains($html, 'data-rendered-by="spa"');

        return response($html)->withHeaders([
            // A cached SPA entry can keep loading old bundles after a deployment.
            'Cache-Control' => $isSpa ? 'private, no-store, no-cache, must-revalidate, max-age=0' : 'public, max-age=60, s-maxage=300, stale-while-revalidate=86400, stale-if-error=86400',
            'ETag' => $etag,
            'Vary' => 'Accept-Encoding, Accept-Language',
            'Surrogate-Key' => 'store-'.($context['store']['id'] ?? 0).' theme-'.($context['theme']['theme_version_id'] ?? 0),
            'X-Storefront-Renderer' => str_contains($html, 'data-rendered-by="spa"') ? 'spa' : (str_contains($html, 'data-rendered-by="blade"') ? 'blade-fallback' : 'ssr'),
        ]);
    }
}
