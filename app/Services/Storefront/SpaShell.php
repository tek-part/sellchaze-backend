<?php

namespace App\Services\Storefront;

/**
 * Serves the React storefront on tenant hosts.
 *
 * The storefront is a Vite SPA built in the frontend repo; its entry document
 * (`dist/storefront.html`) is copied to `storage/app/storefront/shell.html` by the
 * frontend deploy. On a tenant host this class returns that document with:
 *   - asset URLs made absolute to the dashboard origin that hosts the bundles,
 *   - the request locale/direction on <html>,
 *   - SEO head tags (title, description, canonical, robots, og/twitter, JSON-LD)
 *     from the same context the Blade fallback used.
 * The SPA fetches everything else from `/api/v1/storefront/*` on the same host.
 * When the shell file is absent the caller falls back to the Blade renderer.
 */
class SpaShell
{
    /** @var array<string, array{mtime: int, html: string}> */
    private static array $cache = [];

    public function path(): string
    {
        return (string) config('sellchase.storefront.spa_shell', storage_path('app/storefront/shell.html'));
    }

    public function available(): bool
    {
        $path = $this->path();

        return $path !== '' && is_file($path) && is_readable($path);
    }

    /** Origin that serves the SPA bundles (no trailing slash). */
    public function assetOrigin(): string
    {
        $origin = (string) config('sellchase.storefront.spa_origin', '');
        if ($origin === '') {
            $origin = (string) config('sellchase.frontend_url', '');
        }

        return rtrim($origin, '/');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function render(array $context): string
    {
        $html = $this->shell();
        $seo = is_array($context['seo'] ?? null) ? $context['seo'] : [];
        $locale = (string) ($context['locale']['current'] ?? app()->getLocale());
        $dir = (string) ($context['locale']['dir'] ?? ($locale === 'ar' ? 'rtl' : 'ltr'));

        // <html lang dir>
        $html = preg_replace(
            '/<html\b[^>]*>/i',
            '<html lang="'.e($locale).'" dir="'.e($dir).'">',
            $html,
            1
        ) ?? $html;

        // Replace the build's <title>, then append SEO tags before </head>.
        $title = (string) ($seo['title'] ?? ($context['store']['name'] ?? ''));
        if ($title !== '') {
            $html = preg_replace('/<title>.*?<\/title>/is', '<title>'.e($title).'</title>', $html, 1) ?? $html;
        }

        $head = [];
        if (! empty($seo['description'])) {
            $head[] = '<meta name="description" content="'.e((string) $seo['description']).'">';
        }
        $head[] = '<meta name="robots" content="'.e((string) ($seo['robots'] ?? 'index, follow')).'">';
        if (! empty($seo['canonical'])) {
            $head[] = '<link rel="canonical" href="'.e((string) $seo['canonical']).'">';
        }
        foreach ((array) ($seo['og'] ?? []) as $property => $content) {
            $head[] = '<meta property="'.e((string) $property).'" content="'.e((string) $content).'">';
        }
        foreach ((array) ($seo['twitter'] ?? []) as $name => $content) {
            $head[] = '<meta name="'.e((string) $name).'" content="'.e((string) $content).'">';
        }
        if (! empty($seo['json_ld'])) {
            $head[] = '<script type="application/ld+json">'
                .json_encode($seo['json_ld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
                .'</script>';
        }
        $head[] = '<meta name="x-storefront-renderer" content="spa">';

        $html = preg_replace('/<\/head>/i', implode("\n", $head)."\n</head>", $html, 1) ?? $html;

        return str_replace('id="storefront-root"', 'id="storefront-root" data-rendered-by="spa"', $html);
    }

    /** Raw shell with asset URLs made absolute; cached per file mtime. */
    private function shell(): string
    {
        $path = $this->path();
        $mtime = (int) @filemtime($path);
        $cached = self::$cache[$path] ?? null;
        if ($cached !== null && $cached['mtime'] === $mtime) {
            return $cached['html'];
        }

        $html = (string) file_get_contents($path);
        $origin = $this->assetOrigin();
        if ($origin !== '') {
            // src="/assets/…", href="/assets/…", href="/icon.png", href="/manifest…", "/media/…"
            $html = preg_replace(
                '~((?:src|href)=")/(assets|media|icon|favicon|manifest|apple-touch)~',
                '$1'.$origin.'/$2',
                $html
            ) ?? $html;
        }

        self::$cache[$path] = ['mtime' => $mtime, 'html' => $html];

        return $html;
    }
}
