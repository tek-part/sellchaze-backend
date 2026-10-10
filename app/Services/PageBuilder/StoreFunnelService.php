<?php

namespace App\Services\PageBuilder;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreFunnel;
use App\Services\Themes\ThemeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreFunnelService
{
    public const TEMPLATES = [
        'spotlight' => ['name' => ['ar' => 'واجهة المنتج', 'en' => 'Product spotlight'], 'description' => ['ar' => 'عرض بصري للمنتج وزر شراء واضح', 'en' => 'A focused product hero and purchase action'], 'color' => '#146c43'],
        'story' => ['name' => ['ar' => 'قصة المنتج', 'en' => 'Product story'], 'description' => ['ar' => 'عرض المنتج وتفاصيله في أقسام قابلة للتحرير', 'en' => 'Product presentation followed by editable details'], 'color' => '#7c3aed'],
        'minimal' => ['name' => ['ar' => 'عرض مختصر', 'en' => 'Minimal offer'], 'description' => ['ar' => 'صفحة بسيطة تركز على العرض والطلب', 'en' => 'A concise offer focused on the purchase action'], 'color' => '#0369a1'],
    ];

    public function __construct(private readonly StorePageService $pages, private readonly ThemeResolver $themes) {}

    public function validateProduct(Store $store, array $data): Product
    {
        $product = Product::query()->where('store_id', $store->id)->find($data['product_id']);
        if (! $product || ! $product->slug) {
            throw ValidationException::withMessages(['product_id' => ['Select a product with a public slug from this store.']]);
        }
        $schema = $this->themes->resolve($store)['sections_schema'] ?? [];
        if (! isset($schema['hero-banner'], $schema['rich-text'])) {
            throw ValidationException::withMessages(['template_key' => ['The active theme must support hero-banner and rich-text sections.']]);
        }

        return $product;
    }

    public function create(Store $store, array $data, ?int $actor, ?array $copy = null): StoreFunnel
    {
        $product = $this->validateProduct($store, $data);

        return DB::transaction(function () use ($store, $data, $product, $actor, $copy) {
            $page = $this->pages->create($store, [
                'title' => $data['title'], 'slug' => $data['slug'], 'template' => 'landing', 'locale' => $data['locale'],
            ]);
            $arabic = $data['locale'] === 'ar';
            $hero = ['type' => 'hero-banner', 'settings' => [
                'heading' => $product->translated('name', $data['locale']),
                'text' => strip_tags((string) ($product->translated('short_description', $data['locale']) ?: $product->translated('description', $data['locale']))),
                'image' => $product->imageUrl() ?? '',
                'cta_label' => $arabic ? 'اطلب الآن' : 'Shop now',
                'cta_url' => '#funnel-checkout',
                'cta2_label' => '', 'cta2_url' => '', 'eyebrow' => $store->name,
            ]];
            $details = ['type' => 'rich-text', 'settings' => [
                'heading' => $arabic ? 'تفاصيل المنتج' : 'Product details',
                'body' => '<p>'.e(strip_tags((string) $product->translated('description', $data['locale']))).'</p>',
            ]];
            $sections = match ($data['template_key']) {
                'minimal' => [$hero],
                'story' => [$hero, $details, ['type' => 'hero-banner', 'settings' => array_merge($hero['settings'], ['text' => '', 'image' => ''])]],
                default => [$hero, $details],
            };
            if ($copy !== null) {
                $hero['settings']['heading'] = strip_tags($copy['heading']);
                $hero['settings']['text'] = strip_tags($copy['text']);
                $hero['settings']['cta_label'] = strip_tags($copy['cta_label']);
                $details['settings']['heading'] = strip_tags($copy['details_heading']);
                $details['settings']['body'] = implode('', array_map(fn (string $paragraph) => '<p>'.e($paragraph).'</p>', $copy['paragraphs']));
                $sections = [$hero, $details];
                if ($copy['faqs'] !== []) {
                    $sections[] = ['type' => 'rich-text', 'settings' => [
                        'heading' => strip_tags($copy['faq_heading']),
                        'body' => implode('', array_map(fn (array $faq) => '<h3>'.e($faq['question']).'</h3><p>'.e($faq['answer']).'</p>', $copy['faqs'])),
                    ]];
                }
                if ($data['template_key'] === 'story') {
                    $sections[] = ['type' => 'hero-banner', 'settings' => array_merge($hero['settings'], ['text' => '', 'image' => ''])];
                }
            }
            $this->pages->syncSections($page, $sections, $actor);

            return StoreFunnel::create(['store_id' => $store->id, 'store_page_id' => $page->id, 'product_id' => $product->id, 'template_key' => $data['template_key']]);
        });
    }

    public function duplicate(Store $store, StoreFunnel $source, ?int $actor): StoreFunnel
    {
        return DB::transaction(function () use ($store, $source, $actor) {
            $page = $source->page;
            $copy = $this->pages->create($store, ['title' => $page->title.' (copy)', 'slug' => $page->slug.'-copy', 'template' => 'landing', 'locale' => $page->locale, 'seo' => $page->seo]);
            // Resolve reusable sections into independent values so edits to the copy cannot
            // unexpectedly change the source funnel through a shared section reference.
            $sections = array_map(function (array $section): array {
                unset($section['reusable_section_id']);

                return $section;
            }, $this->pages->snapshot($page)['sections']);
            $this->pages->syncSections($copy, $sections, $actor);

            return StoreFunnel::create(['store_id' => $store->id, 'store_page_id' => $copy->id, 'product_id' => $source->product_id, 'template_key' => $source->template_key]);
        });
    }
}
