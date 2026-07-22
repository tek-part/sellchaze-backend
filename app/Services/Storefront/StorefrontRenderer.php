<?php

namespace App\Services\Storefront;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * Renders a storefront page from a render context.
 *
 * Pipeline (Phase 4B): full-page cache -> React SSR runtime (Node) -> Blade
 * section fallback. The SSR runtime receives ONLY the JSON context (no DB, no
 * credentials), so themes are tenant-safe by construction. If the SSR service is
 * unconfigured or unreachable, the Blade section renderer produces identical,
 * fully server-rendered HTML (Hybrid) — the storefront always renders.
 */
class StorefrontRenderer
{
    public function __construct(private readonly StorefrontPageCache $cache) {}

    public function render(Request $request, array $context): string
    {
        $path = '/'.ltrim($request->path(), '/');
        $locale = app()->getLocale();
        $key = $this->cache->key($context, $path, $locale);

        return $this->cache->remember($key, fn () => $this->renderHtml($context));
    }

    /** Uncached render — used for owner theme preview (never touches the page cache). */
    public function renderFresh(array $context): string
    {
        return $this->renderHtml($context);
    }

    private function renderHtml(array $context): string
    {
        $ssrUrl = (string) config('sellchase.storefront.ssr_url', '');

        if ($ssrUrl !== '') {
            try {
                $response = Http::timeout((int) config('sellchase.storefront.ssr_timeout', 2))
                    ->acceptJson()
                    ->post(rtrim($ssrUrl, '/').'/render', ['context' => $context]);

                if ($response->successful() && is_string($response->body()) && $response->body() !== '') {
                    return $response->body();
                }
                Log::warning('Storefront SSR returned non-usable response; falling back to Blade.');
            } catch (\Throwable $e) {
                Log::warning('Storefront SSR unreachable; falling back to Blade: '.$e->getMessage());
            }
        }

        // Hybrid fallback: server-rendered Blade, section-driven from the same context.
        // Theme-driven shell: a theme may ship its own Blade shell at
        // storefront.themes.<key>.render (mirroring its SSR Layout/styles). Themes
        // without one keep the built-in storefront.render view unchanged — so
        // Default/Aurora fallback output is byte-for-byte preserved.
        $themeKey = $context['theme']['key'] ?? null;
        $view = ($themeKey && View::exists("storefront.themes.{$themeKey}.render"))
            ? "storefront.themes.{$themeKey}.render"
            : 'storefront.render';

        return View::make($view, ['context' => $context])->render();
    }
}
