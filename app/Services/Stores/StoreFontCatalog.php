<?php

namespace App\Services\Stores;

class StoreFontCatalog
{
    public static function stylesheet(?string $family): ?string
    {
        $catalog = json_decode((string) file_get_contents(resource_path('store-fonts.json')), true, 512, JSON_THROW_ON_ERROR);
        if (! $family || ! isset($catalog[$family])) {
            return null;
        }
        $families = [$family];
        if (! $catalog[$family]['arabic']) {
            $families[] = 'Cairo';
        }
        sort($families);

        return 'https://fonts.googleapis.com/css2?'.implode('&', array_map(function ($name) use ($catalog) {
            $entry = $catalog[$name];
            $axes = ! empty($entry['italic']) ? 'ital,wght@'.implode(';', array_map(fn ($weight) => '1,'.$weight, $entry['weights'])) : 'wght@'.implode(';', $entry['weights']);

            return 'family='.str_replace('%20', '+', rawurlencode($name)).':'.$axes;
        }, $families)).'&display=swap';
    }

    public static function families(): array
    {
        static $families;
        $families ??= array_merge(['system'], array_keys(json_decode((string) file_get_contents(resource_path('store-fonts.json')), true, 512, JSON_THROW_ON_ERROR)));

        return $families;
    }
}
