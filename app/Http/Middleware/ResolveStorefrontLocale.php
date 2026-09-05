<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocalizedValue;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the storefront locale for a host-resolved request and makes it the
 * active app locale. Runs after `resolve.store`.
 *
 * Precedence: `?lang` → `?locale` → `Accept-Language` → `store.default_locale`,
 * always constrained to the store's `supported_locales` (the default is always
 * supported). Without a store (main app host) the platform locales apply.
 *
 * The response carries `Content-Language` and `Vary: Accept-Language` so shared
 * caches never serve one language's payload to another.
 */
class ResolveStorefrontLocale
{
    public function __construct(private readonly LocaleContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $store = $request->attributes->get('store');
        $store = $store instanceof Store ? $store : null;

        $fallback = $store ? LocaleContext::storeDefault($store) : (string) config('app.locale', 'en');
        $supported = $store ? LocaleContext::storeSupported($store) : array_values(array_unique([$fallback, ...LocalizedValue::PLATFORM_LOCALES]));

        $current = $this->requested($request, $supported)
            ?? $this->preferred($request, $supported)
            ?? $fallback;

        $this->context->set($current, $fallback, $supported);
        app()->setLocale($current);
        $request->attributes->set('locale', $current);

        $response = $next($request);

        $response->headers->set('Content-Language', $current);
        $vary = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Vary', ''))));
        if (! in_array('Accept-Language', $vary, true)) {
            $vary[] = 'Accept-Language';
        }
        $response->headers->set('Vary', implode(', ', $vary));

        return $response;
    }

    /** @param  list<string>  $supported */
    private function requested(Request $request, array $supported): ?string
    {
        foreach (['lang', 'locale'] as $param) {
            $value = $request->query($param);
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $match = $this->match($value, $supported);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /** @param  list<string>  $supported */
    private function preferred(Request $request, array $supported): ?string
    {
        if ($request->headers->get('Accept-Language', '') === '') {
            return null;
        }
        foreach ($request->getLanguages() as $language) {
            $match = $this->match($language, $supported);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /** Exact match first (`ar`), then by language part (`ar-EG` → `ar`). */
    private function match(string $candidate, array $supported): ?string
    {
        $candidate = strtolower(str_replace('_', '-', trim($candidate)));
        if (in_array($candidate, $supported, true)) {
            return $candidate;
        }
        $lang = substr($candidate, 0, 2);
        foreach ($supported as $locale) {
            if (substr($locale, 0, 2) === $lang) {
                return $locale;
            }
        }

        return null;
    }
}
