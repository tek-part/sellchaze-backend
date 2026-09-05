<?php

namespace App\Support\Localization;

use App\Models\Store;
use App\Support\Tenancy\CurrentStore;

/**
 * Request-scoped holder for the resolved storefront locale (the locale twin of
 * {@see CurrentStore}). Set by ResolveStorefrontLocale on every
 * storefront request; read by services that pick localized values so callers never
 * have to thread a `$locale` argument through every layer.
 *
 * Registered as a *scoped* container binding so Octane resets it between requests.
 */
class LocaleContext
{
    private ?string $current = null;

    private ?string $fallback = null;

    /** @var list<string> */
    private array $supported = [];

    /** @param  list<string>  $supported */
    public function set(string $current, string $fallback, array $supported): void
    {
        $this->current = $current;
        $this->fallback = $fallback;
        $this->supported = array_values(array_unique(array_merge([$fallback], $supported)));
    }

    /** Seed the context from a store's locale settings without a request (jobs, tests, SSR helpers). */
    public function setFromStore(Store $store, ?string $current = null): void
    {
        $fallback = self::storeDefault($store);
        $supported = self::storeSupported($store);
        $this->set($current !== null && in_array($current, $supported, true) ? $current : $fallback, $fallback, $supported);
    }

    public function has(): bool
    {
        return $this->current !== null;
    }

    /** The resolved request locale, or the app locale when nothing was resolved. */
    public function current(): string
    {
        return $this->current ?? app()->getLocale();
    }

    /** The store default locale — what every localized value falls back to. */
    public function fallback(): string
    {
        return $this->fallback ?? (string) config('app.fallback_locale', 'en');
    }

    /** @return list<string> */
    public function supported(): array
    {
        return $this->supported !== [] ? $this->supported : LocalizedValue::PLATFORM_LOCALES;
    }

    public function dir(): string
    {
        return LocalizedValue::direction($this->current());
    }

    public function forget(): void
    {
        $this->current = null;
        $this->fallback = null;
        $this->supported = [];
    }

    /** The `locale` block every storefront payload carries. */
    public function toArray(): array
    {
        return [
            'current' => $this->current(),
            'fallback' => $this->fallback(),
            'supported' => $this->supported(),
            'dir' => $this->dir(),
        ];
    }

    public static function storeDefault(Store $store): string
    {
        $default = strtolower(trim((string) ($store->default_locale ?? '')));

        return $default !== '' ? $default : (string) config('app.locale', 'en');
    }

    /** @return list<string> */
    public static function storeSupported(Store $store): array
    {
        $default = self::storeDefault($store);
        $supported = collect(is_array($store->supported_locales) ? $store->supported_locales : [])
            ->map(fn ($l) => strtolower(trim((string) $l)))
            ->filter(fn ($l) => $l !== '')
            ->values()
            ->all();

        return array_values(array_unique(array_merge([$default], $supported)));
    }
}
