<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StorePage;
use App\Models\StoreTheme;
use App\Models\ThemeVersion;
use App\Services\Storefront\StorefrontContextBuilder;
use App\Services\Storefront\StorefrontRenderer;
use App\Services\Storefront\StorefrontService;
use App\Services\Storefront\StoreSeoService;
use App\Services\Themes\ThemePreviewToken;
use App\Services\Themes\ThemeResolver;
use App\Services\Themes\ThemeSettingsMigrator;
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
                'app' => 'Sellchase API',
                'api' => url('/api/v1'),
                'frontend' => 'Run the React app separately (see frontend/README.md).',
            ]);
        }

        return $this->renderTemplate($request, $store, 'home');
    }

    private function isAppHost(string $host): bool
    {
        $host = strtolower($host);
        $appHost = strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'));
        $base = strtolower((string) config('sellchase.storefront.base_domain', 'sellchase.com'));

        return in_array($host, [$appHost, $base, 'localhost', '127.0.0.1'], true);
    }

    /** Lightweight non-theme listing (all products). */
    public function products(Request $request): Response
    {
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

        return response($this->renderer->render($request, $context));
    }

    /**
     * A valid, signed ?__preview token yields a resolved theme context to render
     * (the installed candidate, or a specific version for an upgrade preview).
     */
    private function resolvePreview(Request $request, Store $store): ?array
    {
        $token = $request->query('__preview');
        if (! is_string($token) || $token === '') {
            return null;
        }
        $ctx = app(ThemePreviewToken::class)->verifyContext($token, $store->id);
        if ($ctx === null) {
            return null;
        }

        $install = StoreTheme::query()->where('store_id', $store->id)->find($ctx['store_theme_id']);
        if ($install === null) {
            return null;
        }

        $resolver = app(ThemeResolver::class);

        // Upgrade preview: render a specific (newer) version with migrated settings.
        if (! empty($ctx['version_id'])) {
            $version = ThemeVersion::query()->find($ctx['version_id']);
            if ($version && (int) $version->theme_id === (int) $install->theme_id) {
                $current = ThemeVersion::query()->find($install->theme_version_id);
                $migrated = app(ThemeSettingsMigrator::class)->migrate(
                    $install->theme?->key ?? '',
                    $current?->version ?? '',
                    $version->version,
                    $install->settings ?? [],
                );

                return $resolver->resolveForVersion($version, $migrated);
            }
        }

        return $resolver->resolveForInstall($install);
    }

    /** GET /pages/{slug} — dynamic Page Builder page (Task 11). */
    public function page(Request $request, string $slug): Response
    {
        $store = $this->store($request);

        // Owner draft preview via a signed token (isolation: token binds store + page).
        $previewPageId = null;
        if (is_string($token = $request->query('__preview')) && $token !== '') {
            $previewPageId = app(ThemePreviewToken::class)->verify($token, $store->id);
        }

        $page = StorePage::query()->where('store_id', $store->id)->where('slug', $slug)->first();
        abort_if($page === null, 404, 'Page not found.');

        $isPreview = $previewPageId !== null && (int) $previewPageId === (int) $page->id;
        abort_unless($isPreview || $page->isPubliclyVisible(), 404, 'Page not found.'); // drafts/future hidden

        $context = $this->builder->buildPage($store, $page);

        if ($isPreview) {
            return response($this->renderer->renderFresh($context))->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return response($this->renderer->render($request, $context));
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
}
