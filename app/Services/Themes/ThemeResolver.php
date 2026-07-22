<?php

namespace App\Services\Themes;

use App\Models\Store;
use App\Models\StoreTheme;
use App\Models\Theme;
use App\Models\ThemeVersion;

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
     * @return array{
     *   theme_version_id:int, key:string, version:string,
     *   settings:array, sections_schema:array, templates:array
     * }|null
     */
    public function resolve(Store $store): ?array
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
            'settings' => $this->validator->coerce($rawSettings, $schema),
            'sections_schema' => $version->sections_schema ?? [],
            'templates' => $version->templates ?? [],
            'bundle_url' => $version->bundle_url,
        ];
    }

    /** Resolve a theme context from a specific install (used for preview). */
    public function resolveForInstall(StoreTheme $install): array
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
            'settings' => $this->validator->coerce($install->settings ?? [], $schema),
            'sections_schema' => $version->sections_schema ?? [],
            'templates' => $version->templates ?? [],
            'bundle_url' => $version->bundle_url,
        ];
    }

    /** Resolve a theme context for an explicit version + settings (upgrade preview). */
    public function resolveForVersion(ThemeVersion $version, array $settings): array
    {
        $theme = Theme::query()->find($version->theme_id);
        $schema = $version->settings_schema ?? [];

        return [
            'theme_version_id' => $version->id,
            'key' => $theme?->key ?? 'unknown',
            'version' => $version->version,
            'settings' => $this->validator->coerce($settings, $schema),
            'sections_schema' => $version->sections_schema ?? [],
            'templates' => $version->templates ?? [],
            'bundle_url' => $version->bundle_url,
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
            'sections_schema' => array_fill_keys($core, ['settings' => []]),
            'templates' => [
                'home' => ['sections' => [['type' => 'hero'], ['type' => 'category-list'], ['type' => 'product-grid']]],
                'product' => ['sections' => [['type' => 'product-details']]],
                'category' => ['sections' => [['type' => 'category-header'], ['type' => 'product-grid']]],
            ],
            'bundle_url' => null,
        ];
    }
}
