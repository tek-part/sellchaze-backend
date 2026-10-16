<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Storefront\StorefrontUrlGenerator;
use App\Support\Localization\LocalizedValue;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Read-only, bounded-memory public catalog projection. No provider calls or delivery credentials. */
class ProductFeeds
{
    public const CHANNELS = ['google', 'meta', 'snapchat', 'tiktok'];

    public function __construct(private readonly StorefrontUrlGenerator $urls) {}

    public function information(Store $store, string $channel): array
    {
        return ['channel' => $channel, 'format' => $channel === 'google' ? 'tsv' : 'csv',
            'url' => $this->urls->publicUrl($store, '/api/v1/storefront/product-feed/'.$channel),
            'description' => 'The feed uses the current public catalog and base store currency.'];
    }

    public function download(Store $store, string $channel, string $locale): StreamedResponse
    {
        $google = $channel === 'google';
        $headers = ['id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand', 'gtin', 'sale_price', 'item_group_id', 'color', 'size', 'material', 'product_type'];
        if ($channel === 'tiktok') {
            $headers[0] = 'sku_id';
        }

        return response()->streamDownload(function () use ($store, $channel, $locale, $headers, $google) {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                throw new \RuntimeException('Cannot open catalog output.');
            }
            try {
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $headers, $google ? "\t" : ',', '"', '', "\r\n");
                foreach ($this->rows($store, $channel, $locale) as $row) {
                    fputcsv($out, array_map(fn ($field) => $this->cell((string) ($row[$field === 'sku_id' ? 'id' : $field] ?? '')), $headers), $google ? "\t" : ',', '"', '', "\r\n");
                }
            } finally {
                fclose($out);
            }
        }, 'products-'.$store->id.'-'.$channel.'.'.($google ? 'tsv' : 'csv'), ['Content-Type' => ($google ? 'text/tab-separated-values' : 'text/csv').'; charset=UTF-8',
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function rows(Store $store, string $channel, string $locale): \Generator
    {
        $query = Product::withoutGlobalScopes()->where('store_id', $store->id)->where('is_active', true)->listed()
            ->whereNotNull('slug')->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->with(['variants' => fn ($q) => $q->where('store_id', $store->id), 'media' => fn ($q) => $q->where('store_id', $store->id),
                'brand' => fn ($q) => $q->where('store_id', $store->id), 'category' => fn ($q) => $q->where('store_id', $store->id)]);
        foreach ($query->lazyById(5) as $product) {
            // Match the public product route so every exported landing page is resolvable.
            if (! preg_match('/^[a-z0-9\-]+$/D', (string) $product->slug)) {
                continue;
            }
            if ($product->variants->isEmpty()) {
                $row = $this->row($store, $product, null, $channel, $locale);
                if ($row !== null) {
                    yield $row;
                }
            } else {
                foreach ($product->variants->where('is_active', true) as $variant) {
                    $row = $this->row($store, $product, $variant, $channel, $locale);
                    if ($row !== null) {
                        yield $row;
                    }
                }
            }
        }
    }

    private function row(Store $store, Product $product, ?ProductVariant $variant, string $channel, string $locale): ?array
    {
        $image = $variant?->imageUrl() ?: $product->imageUrl();
        if (! $this->imageUrl($image, $channel)) {
            $image = $product->media->filter(fn ($m) => in_array($m->type, ['cover', 'gallery'], true) && $this->imageUrl($m->url(), $channel))->first()?->url();
        }
        $name = $this->plain(LocalizedValue::pick($product->translations['name'] ?? [], $locale, $store->default_locale ?: 'en') ?: $product->name, 150);
        $description = $this->plain(LocalizedValue::pick($product->translations['description'] ?? [], $locale, $store->default_locale ?: 'en') ?: ($product->short_description ?: $product->description ?: $product->long_description ?: $name), 5000);
        $price = $variant?->price_override ?? $product->price;
        $basePrice = $price;
        foreach (app(QuantityOffers::class)->publicOffers($product) as $offer) {
            if ($offer['quantity'] === 1 && bccomp($offer['unit_price'], (string) $price, 2) <= 0) {
                $price = $offer['unit_price'];
                break;
            }
        }
        if (! $image || $name === '' || bccomp((string) $price, '0', 2) < 0) {
            return null;
        }
        $brand = $this->plain($product->brand?->name ?: ($product->manufacturer ?? ''), 70);
        $gtin = $this->gtin($variant ? $variant->barcode : $product->barcode);
        // Never manufacture a brand/identifier or export private supplier/cost/digital data.
        if ($channel !== 'google' && $brand === '' && $gtin === '') {
            return null;
        }
        $currency = $store->currency ?: 'USD';
        $compare = $variant?->compare_price ?? $product->compare_price;
        if ($compare === null && bccomp((string) $basePrice, (string) $price, 2) > 0) {
            $compare = $basePrice;
        }
        $onSale = $compare !== null && bccomp((string) $compare, (string) $price, 2) > 0;
        $path = '/products/'.rawurlencode((string) $product->slug).($variant ? '?variant='.$variant->id : '');
        $link = $this->urls->publicUrl($store, $path);
        if ($link === null) {
            return null;
        }
        $available = $variant ? (! $variant->track_inventory || $variant->stock_quantity > $variant->reserved_quantity)
            : (! $product->track_inventory || $product->stock_quantity > $product->reserved_quantity);
        if ($product->digital_type === 'codes') {
            $available = (app(DigitalProducts::class)->available($product) ?? 0) > 0;
        }
        $options = $variant?->options ?? [];
        $axis = function (array $keys) use ($options): string {
            foreach ($options as $name => $value) {
                if (in_array(mb_strtolower(trim((string) $name)), $keys, true)) {
                    return $this->plain((string) $value, 100);
                }
            }

            return '';
        };

        return ['id' => CatalogItemId::selection($product->id, $variant?->id), 'title' => $variant ? $this->plain($name.' - '.$variant->name, 150) : $name,
            'description' => $description, 'availability' => $channel === 'google' ? ($available ? 'in_stock' : 'out_of_stock') : ($available ? 'in stock' : 'out of stock'),
            'condition' => 'new', 'price' => bcadd((string) ($onSale ? $compare : $price), '0', 2).' '.$currency,
            'sale_price' => $onSale ? bcadd((string) $price, '0', 2).' '.$currency : '', 'link' => $link, 'image_link' => $image, 'brand' => $brand, 'gtin' => $gtin,
            'item_group_id' => $variant ? (string) $product->id : '', 'color' => $axis(['color', 'colour', 'اللون', 'لون']), 'size' => $axis(['size', 'المقاس', 'مقاس']),
            'material' => $this->plain($product->material ?? '', 100), 'product_type' => $this->plain($product->category?->name ?? '', 200)];
    }

    private function imageUrl(?string $url, string $channel): bool
    {
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $extension = strtolower(pathinfo($parts['path'] ?? '', PATHINFO_EXTENSION));

        return in_array($extension, $channel === 'tiktok' ? ['jpg', 'jpeg', 'png'] : ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    private function plain(string $value, int $max): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f\s]+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''), 0, $max);
    }

    private function cell(string $value): string
    {
        $value = $this->plain($value, 6000);

        return preg_match('/^[=+@-]/', ltrim($value)) ? "'".$value : $value;
    }

    private function gtin(?string $value): string
    {
        $value = trim($value ?? '');
        if (! preg_match('/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/D', $value) || preg_match('/^0+$/D', $value)) {
            return '';
        }
        $sum = 0;
        for ($i = strlen($value) - 2, $weight = 3; $i >= 0; $i--, $weight = 4 - $weight) {
            $sum += (int) $value[$i] * $weight;
        }

        return (10 - $sum % 10) % 10 === (int) substr($value, -1) ? $value : '';
    }
}
