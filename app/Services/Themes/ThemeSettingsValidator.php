<?php

namespace App\Services\Themes;

use App\Support\Localization\LocalizedValue;

/**
 * Validates/coerces theme settings against a version's settings_schema.
 *
 * - coerce(): fail-safe — always returns a valid, fully-defaulted settings map
 *   (StoreTheme settings can therefore never become invalid at runtime).
 * - errors(): strict — reports type/required problems (for a future settings UI).
 * - flatten(): resolves translatable fields to plain scalars for one locale.
 *
 * Translatable fields (`translatable: true` on text-like schema fields) are STORED
 * as `{locale: string}` maps: a legacy plain string is coerced to `{default: s}`
 * (the "store default locale" bucket) so nothing is lost. Themes never see the map —
 * ThemeResolver flattens settings for the request locale before rendering.
 */
class ThemeSettingsValidator
{
    /** Field types that may carry `translatable: true`. */
    public const TRANSLATABLE_TYPES = ['text', 'textarea', 'richtext'];

    /** @return array<string,mixed> validated + defaulted settings (translatable fields as locale maps) */
    public function coerce(array $settings, array $schema): array
    {
        $out = [];
        foreach ($this->fields($schema) as $field) {
            $id = $field['id'];
            $value = $settings[$id] ?? ($field['default'] ?? null);
            $out[$id] = $this->isTranslatable($field)
                ? $this->coerceTranslatable($value, $field)
                : $this->coerceValue($value, $field);
        }

        return $out;
    }

    /** @return string[] strict validation errors (empty = valid) */
    public function errors(array $settings, array $schema): array
    {
        $errors = [];
        foreach ($this->fields($schema) as $field) {
            $id = $field['id'];
            if (! array_key_exists($id, $settings)) {
                continue; // missing -> defaulted, not an error
            }
            $value = $settings[$id];
            $type = $field['type'] ?? 'text';

            // Laravel's ConvertEmptyStringsToNull middleware turns an empty
            // text/url value into null before this validator runs. A nullable
            // or empty-string default explicitly permits that empty value; the
            // coerce pass below restores the schema's canonical default.
            if ($value === null && in_array($field['default'] ?? null, [null, ''], true)) {
                continue;
            }

            if ($this->isTranslatable($field)) {
                if (! (is_string($value) || $this->isLocaleMap($value))) {
                    $errors[] = "setting '{$id}' must be a string or a {locale: string} map";
                }

                continue;
            }

            $ok = match ($type) {
                'toggle' => is_bool($value),
                'number', 'range' => is_numeric($value),
                'select' => SchemaOptions::contains($field['options'] ?? [], $value),
                'image' => $value === null || is_string($value),
                'url' => is_string($value) && ($value === '' || filter_var($value, FILTER_VALIDATE_URL) !== false),
                'text', 'textarea', 'color', 'richtext' => is_string($value),
                default => is_string($value),
            };
            if (! $ok) {
                $errors[] = "setting '{$id}' has an invalid value for type '{$type}'";
            }
        }

        return $errors;
    }

    /**
     * Resolve translatable fields to plain scalars for `$locale` (→ `$fallback` → the
     * `default` bucket → first non-empty). Non-translatable fields pass through, so
     * the result is exactly what a theme expects: `{announcement: "..."}`.
     *
     * @param  array<string,mixed>  $settings  coerced settings (maps for translatable fields)
     * @return array<string,mixed>
     */
    public function flatten(array $settings, array $schema, string $locale, ?string $fallback = null): array
    {
        $out = $settings;
        foreach ($this->fields($schema) as $field) {
            if (! $this->isTranslatable($field)) {
                continue;
            }
            $id = $field['id'];
            $value = $settings[$id] ?? ($field['default'] ?? '');
            $out[$id] = is_array($value) ? LocalizedValue::pick($value, $locale, $fallback) : (string) $value;
        }

        return $out;
    }

    /** True when the schema field is a text-like field flagged `translatable: true`. */
    public function isTranslatable(array $field): bool
    {
        return ! empty($field['translatable'])
            && in_array($field['type'] ?? 'text', self::TRANSLATABLE_TYPES, true);
    }

    /** @return array<string,string> `{locale: string}` — a legacy string lands in the `default` bucket */
    private function coerceTranslatable(mixed $value, array $field): array
    {
        $default = $field['default'] ?? '';
        if (is_string($value)) {
            return [LocalizedValue::DEFAULT_KEY => $value];
        }
        if (is_array($value)) {
            $map = [];
            foreach ($value as $locale => $item) {
                if (is_string($locale) && LocalizedValue::isLocaleKey($locale) && is_scalar($item)) {
                    $map[$locale] = (string) $item;
                }
            }
            if ($map !== []) {
                return $map;
            }
        }
        if (is_array($default)) {
            return $this->coerceTranslatable($default, ['default' => '']);
        }

        return [LocalizedValue::DEFAULT_KEY => (string) $default];
    }

    private function isLocaleMap(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $locale => $item) {
            if (! is_string($locale) || ! LocalizedValue::isLocaleKey($locale) || ! ($item === null || is_string($item))) {
                return false;
            }
        }

        return true;
    }

    private function coerceValue(mixed $value, array $field): mixed
    {
        $type = $field['type'] ?? 'text';
        $default = $field['default'] ?? null;

        return match ($type) {
            'toggle' => (bool) $value,
            'number' => is_numeric($value) ? $value + 0 : ($default ?? 0),
            'range' => $this->clamp(is_numeric($value) ? $value + 0 : ($default ?? 0), $field),
            'select' => SchemaOptions::contains($field['options'] ?? [], $value) ? (string) $value : $default,
            'image' => is_string($value) ? $value : ($default ?: null),
            'url' => (is_string($value) && ($value === '' || filter_var($value, FILTER_VALIDATE_URL) !== false)) ? $value : (string) ($default ?? ''),
            // text, textarea, color, richtext and anything else -> string
            default => is_string($value) ? $value : (string) ($default ?? ''),
        };
    }

    private function clamp(int|float $value, array $field): int|float
    {
        if (isset($field['min'])) {
            $value = max($field['min'], $value);
        }
        if (isset($field['max'])) {
            $value = min($field['max'], $value);
        }

        return $value;
    }

    /** @return array<int,array<string,mixed>> flattened field definitions */
    private function fields(array $schema): array
    {
        $fields = [];
        foreach ($schema as $group) {
            foreach (($group['fields'] ?? []) as $field) {
                if (isset($field['id'])) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }
}
