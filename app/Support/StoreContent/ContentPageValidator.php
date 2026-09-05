<?php

namespace App\Support\StoreContent;

use App\Support\Localization\LocalizedValue;

/**
 * Validates + sanitises one content page payload against {@see ContentPageSchema}.
 *
 * Storage shape (unchanged, "full copy per locale"): `{en: {field: value}, ar: {...}}`.
 * Locale keys must be store-supported locales; each value is typed per field:
 *   text|textarea|richtext|url|image|date → string|null
 *   toggle → bool (loose "1"/"0"/"true"/"false" accepted)
 *   lines → string[] (a newline-delimited string is split)
 *   repeater → list of objects validated against `item`
 * Unknown field keys are dropped; wrong types/locales are reported as errors keyed
 * `data.{locale}.{field}[.{index}.{sub}]` so the editor can highlight them.
 */
class ContentPageValidator
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param  list<string>  $locales  the store's supported locales
     * @return array{data: array<string, array<string, mixed>>, errors: array<string, string>}
     */
    public function validate(string $key, mixed $data, array $locales): array
    {
        $this->errors = [];
        $fields = ContentPageSchema::fields($key);

        if ($data === null || $data === '' || $data === []) {
            return ['data' => [], 'errors' => []];
        }
        if (! is_array($data)) {
            return ['data' => [], 'errors' => ['data' => 'The content payload must be an object keyed by locale.']];
        }

        // Legacy flat payload (`{heading: ...}`) → the store default locale bucket.
        $fieldKeys = array_column($fields, 'key');
        if (array_intersect(array_keys($data), $fieldKeys) !== [] && array_intersect(array_keys($data), $locales) === []) {
            $data = [$locales[0] => $data];
        }

        $out = [];
        foreach ($data as $locale => $copy) {
            $locale = (string) $locale;
            if (! in_array($locale, $locales, true)) {
                $this->errors["data.{$locale}"] = "Locale '{$locale}' is not enabled for this store.";

                continue;
            }
            if ($copy === null || $copy === []) {
                $out[$locale] = [];

                continue;
            }
            if (! is_array($copy)) {
                $this->errors["data.{$locale}"] = 'Each locale must hold an object of fields.';

                continue;
            }
            $out[$locale] = $this->validateFields($fields, $copy, "data.{$locale}");
        }

        return ['data' => $out, 'errors' => $this->errors];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function validateFields(array $fields, array $values, string $path): array
    {
        $out = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $out[$key] = $this->validateValue($field, $values[$key], "{$path}.{$key}");
        }

        return $out;
    }

    private function validateValue(array $field, mixed $value, string $path): mixed
    {
        $type = $field['type'] ?? 'text';

        switch ($type) {
            case 'toggle':
                if (is_bool($value) || $value === null) {
                    return (bool) $value;
                }
                if (is_int($value) || (is_string($value) && in_array(strtolower($value), ['1', '0', 'true', 'false', ''], true))) {
                    return in_array(strtolower((string) $value), ['1', 'true'], true);
                }
                $this->errors[$path] = 'Must be true or false.';

                return false;

            case 'lines':
                if (is_string($value)) {
                    $value = preg_split('/\r\n|\r|\n/', $value) ?: [];
                }
                if ($value === null) {
                    return [];
                }
                if (! is_array($value)) {
                    $this->errors[$path] = 'Must be a list of lines.';

                    return [];
                }
                $lines = [];
                foreach ($value as $index => $line) {
                    if ($line === null || is_scalar($line)) {
                        $lines[] = (string) $line;
                    } else {
                        $this->errors["{$path}.{$index}"] = 'Each line must be text.';
                    }
                }

                return $lines;

            case 'repeater':
                if ($value === null) {
                    return [];
                }
                if (! is_array($value) || (array_keys($value) !== range(0, count($value) - 1) && $value !== [])) {
                    $this->errors[$path] = 'Must be a list of items.';

                    return [];
                }
                $items = [];
                foreach ($value as $index => $item) {
                    if (! is_array($item)) {
                        $this->errors["{$path}.{$index}"] = 'Each item must be an object.';

                        continue;
                    }
                    $items[] = $this->validateFields($field['item'] ?? [], $item, "{$path}.{$index}");
                }

                return $items;

            case 'url':
                if ($value === null || $value === '') {
                    return '';
                }
                if (! is_string($value)) {
                    $this->errors[$path] = 'Must be a URL.';

                    return '';
                }
                $trimmed = trim($value);
                // Allow bare paths (/about), anchors and http(s) — reject scripts and non-string junk.
                if (preg_match('/^(javascript|data|vbscript):/i', $trimmed)) {
                    $this->errors[$path] = 'Must be a safe URL.';

                    return '';
                }

                return $trimmed;

            case 'text':
            case 'textarea':
            case 'richtext':
            case 'image':
            case 'date':
            default:
                if ($value === null) {
                    return '';
                }
                if (is_array($value) && LocalizedValue::isLocalized($value)) {
                    // Tolerate a nested localized map in a per-locale bucket: keep the first entry.
                    $value = LocalizedValue::pick($value, null);
                }
                if (! is_scalar($value)) {
                    $this->errors[$path] = 'Must be text.';

                    return '';
                }

                return is_bool($value) ? ($value ? '1' : '') : (string) $value;
        }
    }
}
