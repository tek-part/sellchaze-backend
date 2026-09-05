<?php

namespace App\Support\Localization;

/**
 * The single convention for a localized string across the platform.
 *
 * A localized value is either a plain `string` (legacy, locale-less) or a map
 * `{ar: "...", en: "..."}` keyed by locale code (`default` is accepted as the
 * "store default locale" bucket written by legacy coercion). Resolution order:
 * requested locale → fallback (store default) → `default` bucket → first
 * non-empty entry → ''.
 */
final class LocalizedValue
{
    /** Locales the platform ships UI for; store `supported_locales` is a subset. */
    public const PLATFORM_LOCALES = ['en', 'ar'];

    public const DEFAULT_KEY = 'default';

    /** Right-to-left locales (only the language part is considered). */
    private const RTL = ['ar', 'he', 'fa', 'ur'];

    /** True when the value is a locale-keyed map (`{en: "..", ar: ".."}`), false for scalars/lists. */
    public static function isLocalized(mixed $value): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (! is_string($key) || ! self::isLocaleKey($key)) {
                return false;
            }
            if ($item !== null && ! is_scalar($item)) {
                return false;
            }
        }

        return true;
    }

    /** `en`, `ar`, `pt-BR`, `default` — anything that can key a translation. */
    public static function isLocaleKey(string $key): bool
    {
        return $key === self::DEFAULT_KEY || preg_match('/^[a-z]{2,3}([_-][A-Za-z]{2,4})?$/', $key) === 1;
    }

    /**
     * Resolve a localized value to a single string for `$locale`, falling back to
     * `$fallback`, then the `default` bucket, then the first non-empty entry, then ''.
     */
    public static function pick(mixed $value, ?string $locale, ?string $fallback = null): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (! is_array($value)) {
            return '';
        }

        $candidates = array_filter([$locale, $fallback, self::DEFAULT_KEY], fn ($l) => is_string($l) && $l !== '');
        foreach ($candidates as $candidate) {
            $hit = self::entry($value, $candidate);
            if ($hit !== '') {
                return $hit;
            }
        }
        foreach ($value as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                return (string) $item;
            }
        }

        return '';
    }

    /**
     * Normalise any accepted input into a clean `{locale: string}` map. Plain strings
     * are placed under `$defaultLocale`; non-string entries and unknown keys are dropped.
     * When `$locales` is given only those keys (plus `default`) survive.
     *
     * @param  list<string>  $locales
     * @return array<string, string>
     */
    public static function normalize(mixed $value, string $defaultLocale, array $locales = []): array
    {
        if ($value === null) {
            return [];
        }
        if (is_scalar($value)) {
            $string = trim((string) $value);

            return $string === '' ? [] : [$defaultLocale => $string];
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (! is_string($key) || ! self::isLocaleKey($key) || ! (is_scalar($item) || $item === null)) {
                continue;
            }
            $key = self::canonicalKey($key);
            if ($locales !== [] && $key !== self::DEFAULT_KEY && ! in_array($key, $locales, true)) {
                continue;
            }
            $string = trim((string) $item);
            if ($string !== '') {
                $out[$key] = $string;
            }
        }

        return $out;
    }

    /**
     * Per-locale presence map — the "completeness dots" the editor renders.
     *
     * @param  list<string>  $locales
     * @return array<string, bool>
     */
    public static function completeness(mixed $value, array $locales): array
    {
        $out = [];
        foreach ($locales as $locale) {
            $out[$locale] = is_string($value)
                ? trim($value) !== ''
                : (is_array($value) && self::entry($value, $locale) !== '');
        }

        return $out;
    }

    /** `rtl` for Arabic-script locales, `ltr` otherwise. */
    public static function direction(?string $locale): string
    {
        $lang = strtolower(substr((string) $locale, 0, 2));

        return in_array($lang, self::RTL, true) ? 'rtl' : 'ltr';
    }

    /** `en-US` → `en-US`, `EN_us` → `en-US`; the platform keys are lower-case language codes. */
    public static function canonicalKey(string $key): string
    {
        if ($key === self::DEFAULT_KEY) {
            return $key;
        }
        $parts = preg_split('/[_-]/', $key, 2) ?: [$key];
        $lang = strtolower($parts[0]);

        return isset($parts[1]) ? $lang.'-'.strtoupper($parts[1]) : $lang;
    }

    private static function entry(array $value, string $locale): string
    {
        $item = $value[$locale] ?? $value[self::canonicalKey($locale)] ?? null;
        if ($item === null && ! str_contains($locale, '-') && ! str_contains($locale, '_')) {
            // A regional entry (ar-EG) satisfies its language (ar) when nothing more specific exists.
            foreach ($value as $key => $candidate) {
                if (is_string($key) && str_starts_with(strtolower($key), $locale.'-') && is_scalar($candidate)) {
                    $item = $candidate;
                    break;
                }
            }
        }

        return is_scalar($item) ? trim((string) $item) : '';
    }
}
