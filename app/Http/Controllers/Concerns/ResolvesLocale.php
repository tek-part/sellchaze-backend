<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Single source of truth for resolving the request locale to 'en' or 'ar', shared by the public
 * directory, feed and subscription controllers that each previously carried their own copy.
 *
 * Two flavours are kept because the callers need slightly different fallbacks and effects:
 *   - resolveLocale(): returns the locale as a string, falling back to the already-active app locale.
 *     Used by callers (feed, subscriptions) that pass an explicit locale into presenters/services
 *     without mutating global state.
 *   - applyLocale(): resolves ?lang, falling back to the request's Accept-Language, and mutates the
 *     global app locale. Used by the directory controller so its locale-agnostic accessors resolve
 *     correctly for the rest of the request.
 */
trait ResolvesLocale
{
    /** Resolve ?lang to 'en'|'ar', else keep the currently active app locale. */
    protected function resolveLocale(Request $request): string
    {
        $lang = $request->query('lang');

        return in_array($lang, ['en', 'ar'], true) ? $lang : app()->getLocale();
    }

    /** Resolve ?lang (or Accept-Language) to 'en'|'ar' and set it as the active app locale. */
    protected function applyLocale(Request $request): void
    {
        $lang = $request->query('lang', $request->getPreferredLanguage(['en', 'ar']));
        if (in_array($lang, ['en', 'ar'], true)) {
            app()->setLocale($lang);
        }
    }
}
