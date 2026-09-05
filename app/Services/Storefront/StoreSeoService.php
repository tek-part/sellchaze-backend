<?php

namespace App\Services\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorePage;

/**
 * Generates all storefront SEO metadata from store data: title, meta description,
 * canonical URL, OpenGraph, Twitter cards, and JSON-LD structured data, plus
 * sitemap.xml / robots.txt foundations. Canonical URLs use the store's primary host.
 */
class StoreSeoService
{
    /**
     * @param  StorefrontUrlGenerator|null  $urls  nullable + container fallback so
     *                                             plain `new StoreSeoService` (tests) keeps working while DI is preferred.
     */
    public function __construct(private ?StorefrontUrlGenerator $urls = null)
    {
        $this->urls ??= app(StorefrontUrlGenerator::class);
    }

    public function primaryHost(Store $store): string
    {
        return (string) $this->urls->publicHost($store);
    }

    public function baseUrl(Store $store): string
    {
        return rtrim((string) $this->urls->publicUrl($store, '/'), '/');
    }

    public function canonical(Store $store, string $path = '/'): string
    {
        return (string) $this->urls->publicUrl($store, $path);
    }

    private function ogTwitter(string $title, string $description, string $url, ?string $image): array
    {
        return [
            'og' => array_filter([
                'og:type' => 'website',
                'og:site_name' => $title,
                'og:title' => $title,
                'og:description' => $description,
                'og:url' => $url,
                'og:image' => $image,
            ]),
            'twitter' => array_filter([
                'twitter:card' => $image ? 'summary_large_image' : 'summary',
                'twitter:title' => $title,
                'twitter:description' => $description,
                'twitter:image' => $image,
            ]),
        ];
    }

    public function forStore(Store $store): array
    {
        $title = $store->name;
        $description = $store->description ?: ('Shop at '.$store->name);
        $url = $this->canonical($store, '/');
        $image = $store->logoUrl() ?: $store->bannerUrl();

        return array_merge([
            'title' => $title,
            'description' => $description,
            'canonical' => $url,
            'robots' => $store->status === 'active' ? 'index, follow' : 'noindex, nofollow',
            'json_ld' => [
                '@context' => 'https://schema.org',
                '@type' => 'Store',
                'name' => $store->name,
                'description' => $description,
                'url' => $url,
                'image' => $image,
                'email' => $store->email,
                'telephone' => $store->phone,
                'priceRange' => $store->currency,
            ],
        ], $this->ogTwitter($title, $description, $url, $image));
    }

    public function forProduct(Store $store, Product $product): array
    {
        $name = $product->translated('name') ?: $product->name;
        $title = $name.' — '.$store->name;
        $description = $product->translated('description') ?: $name;
        $url = $this->canonical($store, 'products/'.$product->slug);
        $image = $product->imageUrl();

        return array_merge([
            'title' => $title,
            'description' => $description,
            'canonical' => $url,
            'robots' => ($store->status === 'active' && $product->is_active) ? 'index, follow' : 'noindex, nofollow',
            'json_ld' => [
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => $name,
                'description' => $description,
                'url' => $url,
                'image' => $image,
                'sku' => (string) $product->id,
                'offers' => [
                    '@type' => 'Offer',
                    'price' => (string) $product->price,
                    'priceCurrency' => $store->currency,
                    'availability' => $product->is_active ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                    'url' => $url,
                ],
            ],
        ], $this->ogTwitter($title, $description, $url, $image));
    }

    public function forCategory(Store $store, Category $category): array
    {
        $name = $category->translated('name') ?: $category->name;
        $title = $name.' — '.$store->name;
        $description = $category->translated('description') ?: ($name.' at '.$store->name);
        $url = $this->canonical($store, 'categories/'.$category->slug);
        $image = $category->imageUrl() ?: $store->logoUrl();

        return array_merge([
            'title' => $title,
            'description' => $description,
            'canonical' => $url,
            'robots' => ($store->status === 'active' && $category->is_active) ? 'index, follow' : 'noindex, nofollow',
            'json_ld' => [
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'name' => $name,
                'description' => $description,
                'url' => $url,
            ],
        ], $this->ogTwitter($title, $description, $url, $image));
    }

    public function forPage(Store $store, StorePage $page): array
    {
        $seo = $page->seo ?? [];
        $title = ($seo['title'] ?? $page->title).' — '.$store->name;
        $description = $seo['description'] ?? $page->title;
        $url = $this->canonical($store, 'pages/'.$page->slug);
        $image = $seo['og_image'] ?? $store->logoUrl();

        return array_merge([
            'title' => $title,
            'description' => $description,
            'canonical' => $url,
            'robots' => $page->isPubliclyVisible() ? ($seo['robots'] ?? 'index, follow') : 'noindex, nofollow',
            'json_ld' => [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => $seo['title'] ?? $page->title,
                'description' => $description,
                'url' => $url,
            ],
        ], $this->ogTwitter($title, $description, $url, $image));
    }

    /** Store-specific sitemap.xml (home + product + category URLs). */
    public function sitemap(Store $store): string
    {
        $base = $this->baseUrl($store);
        $urls = [$base.'/', $base.'/products'];

        Product::query()->where('is_active', true)->orderBy('id')
            ->get(['slug'])->each(function ($p) use (&$urls, $base) {
                $urls[] = $base.'/products/'.$p->slug;
            });
        Category::query()->where('is_active', true)->orderBy('id')
            ->get(['slug'])->each(function ($c) use (&$urls, $base) {
                $urls[] = $base.'/categories/'.$c->slug;
            });

        $body = '';
        foreach ($urls as $u) {
            $body .= '  <url><loc>'.htmlspecialchars($u, ENT_XML1).'</loc></url>'."\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .$body
            .'</urlset>'."\n";
    }

    /** Store-specific robots.txt referencing the store sitemap. */
    public function robots(Store $store): string
    {
        $allow = $store->status === 'active';

        return "User-agent: *\n"
            .($allow ? "Allow: /\n" : "Disallow: /\n")
            .'Sitemap: '.$this->baseUrl($store)."/sitemap.xml\n";
    }
}
