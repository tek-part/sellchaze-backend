<?php

namespace App\Services\Storefront;

class ResponsiveImageUrl
{
    /** @return array{src:string,srcset:?string,sizes:string,widths:list<int>}|null */
    public function for(string|null $source, array $widths = [320, 640, 960, 1280]): ?array
    {
        if (! filled($source)) {
            return null;
        }

        $source = (string) $source;
        $widths = array_values(array_unique(array_filter(array_map('intval', $widths),
            fn (int $width): bool => $width >= 64 && $width <= 3840)));
        sort($widths);
        $baseUrl = rtrim((string) config('sellchase.storefront.images.transformer_url'), '/');
        if ($baseUrl === '' || $widths === []) {
            return ['src' => $source, 'srcset' => null, 'sizes' => '100vw', 'widths' => []];
        }

        $urls = [];
        foreach ($widths as $width) {
            $query = [
                'format' => 'auto',
                'quality' => (int) config('sellchase.storefront.images.quality', 82),
                'url' => $source,
                'width' => $width,
            ];
            $unsigned = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $secret = (string) config('sellchase.storefront.images.signing_secret');
            if ($secret !== '') {
                $query['signature'] = hash_hmac('sha256', $unsigned, $secret);
            }
            $urls[$width] = $baseUrl.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $largest = $widths[array_key_last($widths)];

        return [
            'src' => $urls[$largest],
            'srcset' => implode(', ', array_map(fn (int $width): string => $urls[$width].' '.$width.'w', $widths)),
            'sizes' => '(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw',
            'widths' => $widths,
        ];
    }
}
