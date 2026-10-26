<?php

namespace App\Services\Commerce\Apps;

use App\Support\Localization\LocaleContext;

/** Merchant-written Arabic/English copy with a polite platform default. */
final class LocalizedText
{
    /** @param array{ar?:string|null,en?:string|null}|null $custom */
    public static function pick(?array $custom, string $ar, string $en): string
    {
        $locale = app(LocaleContext::class)->current() === 'ar' ? 'ar' : 'en';
        $value = trim((string) ($custom[$locale] ?? ''));

        return $value !== '' ? $value : ($locale === 'ar' ? $ar : $en);
    }

    /** @return array<string, list<string>> */
    public static function rules(string $field, int $max): array
    {
        return [$field => ['sometimes', 'array:ar,en'], $field.'.ar' => ['nullable', 'string', 'max:'.$max], $field.'.en' => ['nullable', 'string', 'max:'.$max]];
    }

    public static function normalize(mixed $value): array
    {
        return ['ar' => trim((string) ($value['ar'] ?? '')), 'en' => trim((string) ($value['en'] ?? ''))];
    }
}
