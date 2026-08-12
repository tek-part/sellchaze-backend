<?php

namespace App\Services\Themes;

/**
 * Validates/coerces theme settings against a version's settings_schema.
 *
 * - coerce(): fail-safe — always returns a valid, fully-defaulted settings map
 *   (StoreTheme settings can therefore never become invalid at runtime).
 * - errors(): strict — reports type/required problems (for a future settings UI).
 */
class ThemeSettingsValidator
{
    /** @return array<string,mixed> validated + defaulted settings */
    public function coerce(array $settings, array $schema): array
    {
        $out = [];
        foreach ($this->fields($schema) as $field) {
            $id = $field['id'];
            $value = $settings[$id] ?? ($field['default'] ?? null);
            $out[$id] = $this->coerceValue($value, $field);
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

            $ok = match ($type) {
                'toggle' => is_bool($value),
                'number', 'range' => is_numeric($value),
                'select' => in_array($value, $field['options'] ?? [], true),
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

    private function coerceValue(mixed $value, array $field): mixed
    {
        $type = $field['type'] ?? 'text';
        $default = $field['default'] ?? null;

        return match ($type) {
            'toggle' => (bool) $value,
            'number' => is_numeric($value) ? $value + 0 : ($default ?? 0),
            'range' => $this->clamp(is_numeric($value) ? $value + 0 : ($default ?? 0), $field),
            'select' => in_array($value, $field['options'] ?? [], true) ? $value : $default,
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
