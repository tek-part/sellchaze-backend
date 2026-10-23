<?php

namespace App\Services\Storefront;

use App\Services\Media\ImageDerivatives;

class ResponsiveImageUrl
{
    public function __construct(private readonly ImageDerivatives $derivatives) {}

    /** @return array{src:string,srcset:?string,sizes:string,widths:list<int>}|null */
    public function for(?string $source, array $widths = [320, 640, 960, 1280]): ?array
    {
        if (! filled($source)) {
            return null;
        }

        $source = (string) $source;
        $widths = array_values(array_unique(array_filter(array_map('intval', $widths),
            fn (int $width): bool => $width >= 64 && $width <= 3840)));
        sort($widths);
        $baseUrl = rtrim((string) config('sellchase.storefront.images.transformer_url'), '/');
        if ($baseUrl === '') {
            return $this->builtIn($source, $widths);
        }
        if ($widths === []) {
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

    /**
     * Without an external transformer, images on our own disk use the built-in WebP derivatives.
     *
     * @param  list<int>  $widths
     * @return array{src:string,srcset:?string,sizes:string,widths:list<int>}
     */
    private function builtIn(string $source, array $widths): array
    {
        $widths = array_values(array_unique(array_map(ImageDerivatives::snap(...), $widths)));
        sort($widths);
        if ($widths === [] || $this->derivatives->relativePath($source) === null) {
            return ['src' => $source, 'srcset' => null, 'sizes' => '100vw', 'widths' => []];
        }
        $urls = array_map(fn (int $width): string => (string) $this->derivatives->url($source, $width), $widths);

        return [
            'src' => $urls[array_key_last($urls)],
            'srcset' => implode(', ', array_map(fn (string $url, int $width): string => $url.' '.$width.'w', $urls, $widths)),
            'sizes' => '(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw',
            'widths' => $widths,
        ];
    }
}
