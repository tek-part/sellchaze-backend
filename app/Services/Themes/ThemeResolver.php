<?php

namespace App\Services\Themes;

use App\Models\Store;
use App\Models\StoreTheme;
use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Support\Localization\LocaleContext;

/**
 * Resolves a Store into its Theme Context: the active theme + pinned version +
 * manifest (sections_schema, templates) + validated settings. This is the single
 * source that drives storefront rendering (React SSR or the Blade fallback).
 */
class ThemeResolver
{
    public function __construct(
        private readonly ThemeRegistry $registry,
        private readonly ThemeSettingsValidator $validator,
    ) {}

    /**
     * `settings` are flat scalars resolved for `$locale` (request locale by default,
     * then the store default) so themes stay locale-agnostic; `settings_i18n` keeps
     * the raw `{locale: string}` maps for editors.
     *
     * @return array{
     *   theme_version_id:int, key:string, version:string,
     *   settings:array, settings_i18n:array, sections_schema:array, templates:array
     * }|null
     */
    public function resolve(Store $store, ?string $locale = null): ?array
    {
        $active = StoreTheme::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->first();

        if ($active) {
            $version = ThemeVersion::query()->find($active->theme_version_id);
            $rawSettings = $active->settings ?? [];
        } else {
            // Fallback: default theme's latest version, defaulted settings.
            $version = $this->registry->resolveActiveTheme($store);
            $rawSettings = [];
        }

        if (! $version instanceof ThemeVersion) {
            return $this->builtInContext(); // resilience: render even with no theme registered
        }

        $theme = Theme::query()->find($version->theme_id);
        $schema = $version->settings_schema ?? [];

        return [
            'theme_version_id' => $version->id,
            'key' => $theme?->key ?? 'unknown',
            'version' => $version->version,
            ...$this->settingsBlocks($rawSettings, $schema, $store, $locale),
            'sections_schema' => $version->sections_schema ?? [],
            'templates' => $version->templates ?? [],
            'bundle_url' => $version->bundle_url,
            'bundle_integrity' => $version->bundle_integrity,
            'custom_css' => $active?->custom_css,
        ];
    }

    /** Resolve a theme context from a specific install (used for preview). */
    public function resolveForInstall(StoreTheme $install, ?string $locale = null): array
    {
        $version = ThemeVersion::query()->find($install->theme_version_id);
        if (! $version instanceof ThemeVersion) {
            return $this->builtInContext();
        }
        $theme = Theme::query()->find($version->theme_id);
        $schema = $version->settings_schema ?? [];

        return [
            'theme_version_id' => $version->id,
            'key' => $theme?->key ?? 'unknown',
            'version' => $version->version,
            ...$this->settingsBlocks($install->draft_settings ?? $install->settings ?? [], $schema, $install->store, $locale),
            'sections_schema' => $version->sections_schema ?? [],
            'templates' => $version->templates ?? [],
            'bundle_url' => $version->bundle_url,
            'bundle_integrity' => $version->bundle_integrity,
            'custom_css' => $install->draft_custom_css ?? $install->custom_css,
        ];
    }

    /** Resolve a theme context for an explicit version + settings (upgrade preview). */
    public function resolveForVersion(ThemeVersion $version, array $settings, ?string $locale = null, ?Store $store = null): array
    {
        $theme = Theme::query()->find($version->theme_id);
        $schema = $version->settings_schema ?? [];

        return [
            'theme_version_id' => $version->id,
            'key' => $theme?->key ?? 'unknown',
            'version' => $version->version,
            ...$this->settingsBlocks($settings, $schema, $store, $locale),
            'sections_schema' => $version->sections_schema ?? [],
            'templates' => $version->templates ?? [],
            'bundle_url' => $version->bundle_url,
            'bundle_integrity' => $version->bundle_integrity,
            'custom_css' => null,
        ];
    }

    /**
     * `settings` (flat, for the locale) + `settings_i18n` (coerced maps). The locale
     * defaults to the request LocaleContext, then the store default, then the app locale.
     *
     * @return array{settings: array, settings_i18n: array}
     */
    private function settingsBlocks(array $raw, array $schema, ?Store $store, ?string $locale): array
    {
        $context = app(LocaleContext::class);
        $fallback = $store ? LocaleContext::storeDefault($store) : $context->fallback();
        $locale ??= $context->has() ? $context->current() : $fallback;

        $coerced = $this->validator->coerce($raw, $schema);

        return [
            'settings' => $this->validator->flatten($coerced, $schema, $locale, $fallback),
            'settings_i18n' => $coerced,
        ];
    }

    public function template(array $themeContext, string $template): ?array
    {
        return $themeContext['templates'][$template] ?? null;
    }

    /**
     * Minimal built-in theme context so the storefront always renders, even
     * before any theme is registered/installed. Uses only shared core sections.
     */
    private function builtInContext(): array
    {
        $core = ['hero', 'category-list', 'product-grid', 'category-header', 'product-details', 'rich-text'];

        return [
            'theme_version_id' => 0,
            'key' => 'builtin',
            'version' => '0.0.0',
            'settings' => [],
            'settings_i18n' => [],
            'sections_schema' => array_fill_keys($core, ['settings' => []]),
            'templates' => [
                'home' => ['sections' => [['type' => 'hero'], ['type' => 'category-list'], ['type' => 'product-grid']]],
                'product' => ['sections' => [['type' => 'product-details']]],
                'category' => ['sections' => [['type' => 'category-header'], ['type' => 'product-grid']]],
            ],
            'bundle_url' => null,
            'bundle_integrity' => null,
            'custom_css' => null,
        ];
    }
}
