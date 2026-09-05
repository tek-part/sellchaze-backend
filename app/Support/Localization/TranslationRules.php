<?php

namespace App\Support\Localization;

use Closure;
use Illuminate\Http\Request;

/**
 * Validation rules + request normalisation for the `translations` payload sent by
 * the catalog editors: `{ name: {en: "..", ar: ".."}, description: {...} }`.
 *
 * Multipart forms cannot encode nested objects, so the editor sends `translations`
 * as a JSON string — {@see decodeRequest()} turns it back into an array before
 * validation.
 */
final class TranslationRules
{
    /**
     * @param  list<string>  $attributes  translatable attributes (`name`, `description`, …)
     * @param  list<string>|null  $locales  allowed locale keys (platform locales by default)
     * @param  array<string, int>  $maxLengths  per-attribute max length (default 255 for `name`, none otherwise)
     * @return array<string, array<int, mixed>>
     */
    public static function for(array $attributes, ?array $locales = null, array $maxLengths = ['name' => 255]): array
    {
        $locales ??= LocalizedValue::PLATFORM_LOCALES;

        $rules = [
            'translations' => ['sometimes', 'nullable', 'array', self::keysIn($attributes, 'translations')],
        ];
        foreach ($attributes as $attribute) {
            $rules["translations.{$attribute}"] = ['sometimes', 'nullable', 'array', self::keysIn([...$locales, LocalizedValue::DEFAULT_KEY], "translations.{$attribute}")];
            $item = ['nullable', 'string'];
            if (isset($maxLengths[$attribute])) {
                $item[] = 'max:'.$maxLengths[$attribute];
            }
            $rules["translations.{$attribute}.*"] = $item;
        }

        return $rules;
    }

    /** Decode a JSON-string `translations` field (multipart forms) into the request input. */
    public static function decodeRequest(Request $request): void
    {
        $raw = $request->input('translations');
        if (! is_string($raw)) {
            return;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            $request->merge(['translations' => null]);

            return;
        }
        $decoded = json_decode($trimmed, true);
        $request->merge(['translations' => is_array($decoded) ? $decoded : $trimmed]);
    }

    /** @param  list<string>  $allowed */
    private static function keysIn(array $allowed, string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($allowed, $label): void {
            if (! is_array($value)) {
                return;
            }
            foreach (array_keys($value) as $key) {
                if (! in_array((string) $key, $allowed, true)) {
                    $fail("The {$label} field contains an unsupported key '{$key}'.");

                    return;
                }
            }
        };
    }
}
