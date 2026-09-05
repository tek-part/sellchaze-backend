<?php

namespace App\Services\Themes;

use App\Models\Store;
use App\Models\StoreTheme;
use App\Models\Theme;
use App\Models\ThemeVersion;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Registers theme manifests, validates them, and resolves theme versions
 * (including the active version for a store). Theme code is not executed here —
 * only the manifest contract is ingested.
 */
class ThemeRegistry
{
    /** Register (idempotent) a theme + immutable version from a manifest array. */
    public function register(array $manifest, string $status = 'published'): Theme
    {
        $errors = $this->validate($manifest);
        if ($errors !== []) {
            throw new InvalidArgumentException('Invalid theme manifest: '.implode('; ', $errors));
        }

        $theme = Theme::updateOrCreate(
            ['key' => $manifest['key']],
            [
                'name' => $manifest['name'],
                'description' => $manifest['description'] ?? null,
                'author' => $manifest['author'] ?? null,
                'category' => $manifest['category'] ?? null,
                'preview_image' => $manifest['preview_image'] ?? null,
                'is_marketplace' => (bool) ($manifest['is_marketplace'] ?? false),
                'is_featured' => (bool) ($manifest['is_featured'] ?? false),
                'status' => $status,
            ],
        );

        $version = ThemeVersion::updateOrCreate(
            ['theme_id' => $theme->id, 'version' => $manifest['version']],
            [
                'settings_schema' => $manifest['settings_schema'],
                'status' => 'published',
                'sections_schema' => $manifest['sections_schema'],
                'templates' => $manifest['templates'],
                'bundle_url' => $manifest['bundle_url'] ?? null,
                'min_platform_version' => $manifest['min_platform_version'] ?? null,
                'max_platform_version' => $manifest['max_platform_version'] ?? null,
                'supported_features' => $manifest['supported_features'] ?? null,
                'changelog' => $manifest['changelog'] ?? null,
                'published_at' => now(),
            ],
        );

        // latest_version_id points at the highest semver.
        $latest = $this->resolveThemeVersion($theme);
        $theme->latest_version_id = $latest?->id ?? $version->id;
        $theme->save();

        return $theme->fresh();
    }

    public function registerFromFile(string $path): Theme
    {
        if (! File::exists($path)) {
            throw new InvalidArgumentException("Theme manifest not found: {$path}");
        }
        $manifest = json_decode(File::get($path), true);
        if (! is_array($manifest)) {
            throw new InvalidArgumentException("Theme manifest is not valid JSON: {$path}");
        }

        return $this->register($manifest);
    }

    /** @return string[] list of validation errors (empty = valid) */
    public function validate(array $manifest): array
    {
        $errors = [];

        foreach (['key', 'name', 'version', 'settings_schema', 'sections_schema', 'templates'] as $key) {
            if (! array_key_exists($key, $manifest)) {
                $errors[] = "missing required field: {$key}";
            }
        }

        if (isset($manifest['key']) && ! preg_match('/^[a-z0-9\-]+$/', (string) $manifest['key'])) {
            $errors[] = 'key must match ^[a-z0-9-]+$';
        }
        if (isset($manifest['version']) && ! preg_match('/^\d+\.\d+\.\d+$/', (string) $manifest['version'])) {
            $errors[] = 'version must be semver (MAJOR.MINOR.PATCH)';
        }

        $sectionTypes = is_array($manifest['sections_schema'] ?? null)
            ? array_keys($manifest['sections_schema'])
            : [];
        $templates = is_array($manifest['templates'] ?? null) ? $manifest['templates'] : [];

        foreach (['home', 'product', 'category'] as $required) {
            if (! isset($templates[$required])) {
                $errors[] = "missing required template: {$required}";
            }
        }

        foreach ($templates as $name => $definition) {
            foreach (($definition['sections'] ?? []) as $section) {
                $type = $section['type'] ?? null;
                if ($type === null) {
                    $errors[] = "template '{$name}' has a section without a type";
                } elseif (! in_array($type, $sectionTypes, true)) {
                    $errors[] = "template '{$name}' references unknown section type '{$type}'";
                }
            }
        }

        if (is_array($manifest['sections_schema'] ?? null)) {
            foreach ($manifest['sections_schema'] as $type => $definition) {
                array_push($errors, ...$this->validateSectionDefinition((string) $type, $definition));
            }
        }
        if (is_array($manifest['settings_schema'] ?? null)) {
            foreach ($manifest['settings_schema'] as $index => $group) {
                $groupId = is_array($group) ? (string) ($group['id'] ?? $index) : (string) $index;
                foreach ((is_array($group) ? ($group['fields'] ?? []) : []) as $field) {
                    array_push($errors, ...$this->validateField("settings group '{$groupId}'", $field));
                }
            }
        }

        return $errors;
    }

    /**
     * A sections_schema entry: `{label, description?, category?, icon?, settings[], presets?}`.
     * Extra descriptive keys are allowed (the frontend section library generates them);
     * only the shape of `settings` and each field's `options`/`item` is enforced.
     *
     * @return string[]
     */
    private function validateSectionDefinition(string $type, mixed $definition): array
    {
        if (! is_array($definition)) {
            return ["section '{$type}' must be an object"];
        }
        $errors = [];
        if (array_key_exists('settings', $definition) && ! is_array($definition['settings'])) {
            $errors[] = "section '{$type}' settings must be a list of fields";

            return $errors;
        }
        foreach (($definition['settings'] ?? []) as $field) {
            array_push($errors, ...$this->validateField("section '{$type}'", $field));
        }

        return $errors;
    }

    /** @return string[] */
    private function validateField(string $where, mixed $field, bool $nested = false): array
    {
        if (! is_array($field) || ! isset($field['id']) || ! is_string($field['id'])) {
            return ["{$where} has a field without an id"];
        }
        $errors = [];
        $id = $field['id'];
        if (array_key_exists('options', $field) && ! SchemaOptions::isWellFormed($field['options'])) {
            $errors[] = "{$where} field '{$id}' options must be strings or {value,label} objects";
        }
        if (($field['type'] ?? null) === 'list') {
            if ($nested) {
                $errors[] = "{$where} field '{$id}' nests a list inside a list item";
            } elseif (array_key_exists('item', $field)) {
                if (! is_array($field['item'])) {
                    $errors[] = "{$where} field '{$id}' item must be a list of fields";
                } else {
                    foreach ($field['item'] as $itemField) {
                        array_push($errors, ...$this->validateField("{$where} list '{$id}'", $itemField, true));
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Every first-party manifest on disk, in registration order: the legacy hand-written
     * themes plus every generated `resources/themes/storefront/<key>.json`. The seeder and
     * the `themes:register` command both use this so the two can never diverge.
     *
     * @return list<string> absolute paths (existing files only, de-duplicated)
     */
    public static function manifestPaths(): array
    {
        $legacy = [
            resource_path('themes/default/theme.json'),
            resource_path('themes/default/v1.1.0.json'),   // Phase 4D: 2nd version
            resource_path('themes/aurora/theme.json'),      // Phase 4D: 2nd theme
            resource_path('themes/modern/theme.json'),      // Theme 01: premium theme-driven
            resource_path('themes/atlas/theme.json'),       // Industrial/B2B
            resource_path('themes/verde/theme.json'),       // Food & agriculture
        ];
        $generated = File::isDirectory(resource_path('themes/storefront'))
            ? array_map(fn ($file) => $file->getPathname(), File::files(resource_path('themes/storefront')))
            : [];
        sort($generated);

        $paths = [];
        foreach ([...$legacy, ...$generated] as $path) {
            if (str_ends_with($path, '.json') && File::exists($path) && ! in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    public function defaultTheme(): ?Theme
    {
        return Theme::query()->where('key', 'default')->first();
    }

    /** Resolve a specific version, or the highest-semver version of a theme. */
    public function resolveThemeVersion(Theme|int $theme, ?string $version = null): ?ThemeVersion
    {
        $themeId = $theme instanceof Theme ? $theme->id : (int) $theme;

        if ($version !== null) {
            return ThemeVersion::query()->where('theme_id', $themeId)->where('version', $version)->where('status', 'published')->first();
        }

        return ThemeVersion::query()->where('theme_id', $themeId)->where('status', 'published')->get()
            ->sort(fn ($a, $b) => version_compare($a->version, $b->version))
            ->last();
    }

    /** The theme version currently active for a store (falls back to the default theme). */
    public function resolveActiveTheme(Store $store): ?ThemeVersion
    {
        $active = StoreTheme::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->first();

        if ($active) {
            return ThemeVersion::query()->find($active->theme_version_id);
        }

        $default = $this->defaultTheme();

        return $default ? $this->resolveThemeVersion($default) : null;
    }
}
