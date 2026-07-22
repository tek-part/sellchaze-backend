<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Product `image` column may be: a Sellchase-local filename, a Wigpleasure path under /upload/, or a full URL.
 */
class ProductImageUrl
{
    public static function storefrontBase(): string
    {
        $fromDb = Setting::get('wigpleasure_storefront_url');
        if (is_string($fromDb) && trim($fromDb) !== '') {
            return rtrim(trim($fromDb), '/');
        }

        return rtrim((string) config('services.wigpleasure_storefront_url', 'https://wigpleasure.com'), '/');
    }

    /**
     * Value to store when syncing from Wigpleasure export (full absolute URL on the public storefront).
     */
    public static function canonicalizeFromSource(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $url = trim($url);
        if (preg_match('#\Ahttps?://#i', $url)) {
            return mb_substr($url, 0, 512);
        }

        $base = self::storefrontBase();
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && str_contains($path, '/upload/')) {
            $rel = ltrim(substr($path, strpos($path, '/upload/') + strlen('/upload/')), '/');

            return $base.'/upload/'.$rel;
        }

        $rel = ltrim($url, '/');
        if ($rel === '') {
            return null;
        }

        return $base.'/upload/'.$rel;
    }

    /**
     * Public thumbnail URL for API consumers (e.g. React products list).
     */
    public static function thumbUrl(?string $image): ?string
    {
        if ($image === null || trim($image) === '') {
            return null;
        }
        $image = trim($image);
        if (preg_match('#\Ahttps?://#i', $image)) {
            return $image;
        }

        $base = self::storefrontBase();
        if (str_contains($image, '/')) {
            return $base.'/upload/'.ltrim($image, '/');
        }

        return url('/storage/uploads/products/thumbnails/'.$image);
    }
}
