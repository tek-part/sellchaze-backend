<?php

namespace App\Services\PageBuilder;

use App\Models\Product;
use App\Models\Store;
use App\Models\StorePage;

/** Funnel layouts have their own schema; changing a store theme cannot erase them. */
class NativeFunnelTemplates
{
    public const TEMPLATES = [
        'glasses-ar' => ['name' => ['ar' => 'النظارات — عربي', 'en' => 'Glasses — Arabic'], 'description' => ['ar' => 'غلاف ومعرض منتج وأقسام تحرير ونموذج طلب وشريط شراء ثابت', 'en' => 'Campaign, product gallery, editable sections, checkout and purchase dock'], 'color' => '#c6a75e', 'locale' => 'ar', 'native_layout' => 'glasses', 'preview_image' => '/media/funnel-previews/glasses-ar.png'],
        'glasses-en' => ['name' => ['ar' => 'النظارات — إنجليزي', 'en' => 'Glasses — English'], 'description' => ['ar' => 'تخطيط النظارات باللغة الإنجليزية واتجاه من اليسار لليمين', 'en' => 'The glasses composition with English content and left-to-right direction'], 'color' => '#c6a75e', 'locale' => 'en', 'native_layout' => 'glasses', 'preview_image' => '/media/funnel-previews/glasses-en.png'],
    ] + HairReviveFunnelTemplate::TEMPLATES + LitterRobotFunnelTemplate::TEMPLATES + ElectricBikerFunnelTemplate::TEMPLATES;

    public static function key(StorePage $page): ?string
    {
        $funnel = $page->funnel;

        return $funnel && (int) $funnel->store_id === (int) $page->store_id && isset(self::TEMPLATES[$funnel->template_key]) ? $funnel->template_key : null;
    }

    public static function schemaFor(StorePage $page, array $themeSchema): array
    {
        $key = self::key($page);
        if ($key === null) {
            return $themeSchema;
        }

        return match (self::TEMPLATES[$key]['native_layout']) {
            'hair-revive' => HairReviveFunnelTemplate::schema(),
            'litter-robot' => LitterRobotFunnelTemplate::schema(),
            'electric-biker' => ElectricBikerFunnelTemplate::schema(),
            default => self::schema(),
        };
    }

    public static function sectionsFor(string $key, Store $store, Product $product, string $locale, ?array $copy = null): array
    {
        return match (self::TEMPLATES[$key]['native_layout'] ?? null) {
            'hair-revive' => HairReviveFunnelTemplate::sections($store, $product, $locale, $copy),
            'litter-robot' => LitterRobotFunnelTemplate::sections($store, $product, $locale, $copy),
            'electric-biker' => ElectricBikerFunnelTemplate::sections($store, $product, $locale, $copy),
            default => self::sections($store, $product, $locale, $copy),
        };
    }

    public static function schema(): array
    {
        $text = fn ($id, $label, $type = 'text') => ['id' => $id, 'label' => $label, 'type' => $type];
        $heading = $text('heading', 'العنوان / Heading');
        $body = $text('text', 'النص / Text', 'textarea');
        $image = $text('image', 'الصورة / Image', 'image');
        $cta = $text('cta_label', 'زر الطلب / Purchase button');
        $list = fn ($id, $label, $item, $max = 12) => ['id' => $id, 'label' => $label, 'type' => 'list', 'item' => $item, 'max' => $max];
        $section = fn ($label, $settings) => ['label' => $label, 'category' => 'Glasses', 'settings' => $settings];

        return [
            'glasses-hero' => $section('الغلاف والتنقل / Campaign & navigation', [$heading, $body, $image, $cta, $text('announcement', 'شريط العرض / Announcement'), $text('expires_at', 'انتهاء العرض ISO 8601 / Offer expiry with timezone'), $text('image_alt', 'وصف الصورة / Image description')]),
            'glasses-purchase' => ['max_instances' => 1] + $section('المنتج والطلب / Product & checkout', [$heading, $body, $cta]),
            'glasses-details' => $section('المواصفات / Specifications', [$heading, $list('rows', 'المواصفات الإضافية / Additional specifications', [$text('label', 'البند / Label'), $text('value', 'القيمة / Value')])]),
            'glasses-story' => $section('قصة المنتج / Product story', [$heading, $body, $image, $cta, $text('image_alt', 'وصف الصورة / Image description')]),
            'glasses-features' => $section('الميزات / Features', [$heading, $list('items', 'الميزات / Features', [$heading, $body, $image, $text('image_alt', 'وصف الصورة / Image description')], 8)]),
            'glasses-media' => $section('صورة أو فيديو / Image or video', [$heading, $image, $text('image_alt', 'وصف الصورة / Image description'), $text('video_url', 'رابط ملف فيديو HTTPS / HTTPS video file URL')]),
            'glasses-quote' => $section('اقتباس / Quote', [$body, $text('attribution', 'صاحب الاقتباس / Attribution')]),
            'glasses-reviews' => $section('تقييمات المنتج الفعلية / Actual product reviews', [$heading]),
            'glasses-faq' => $section('الأسئلة الشائعة / Frequently asked questions', [$heading, $image, $text('image_alt', 'وصف الصورة / Image description'), $list('items', 'الأسئلة والإجابات / Questions & answers', [$text('question', 'السؤال / Question'), $text('answer', 'الإجابة / Answer', 'textarea')])]),
            'glasses-closing' => $section('الخاتمة / Closing statement', [$heading, $body, $cta]),
        ];
    }

    public static function sections(Store $store, Product $product, string $locale, ?array $copy = null): array
    {
        $ar = $locale === 'ar';
        $name = $product->translated('name', $locale);
        $description = strip_tags((string) $product->translated('description', $locale));
        $image = $product->imageUrl() ?? '';
        $cta = $ar ? 'اطلب الآن' : 'Shop now';
        $row = fn ($type, $settings, $visible = true) => ['type' => 'glasses-'.$type, 'settings' => $settings, 'is_visible' => $visible];

        return [
            $row('hero', ['heading' => strip_tags($copy['heading'] ?? $name), 'text' => strip_tags($copy['text'] ?? (string) ($product->translated('short_description', $locale) ?: $description)), 'image' => $image, 'image_alt' => $name, 'cta_label' => strip_tags($copy['cta_label'] ?? $cta), 'announcement' => '', 'expires_at' => '']),
            $row('purchase', ['heading' => $name, 'text' => '', 'cta_label' => $cta]),
            $row('details', ['heading' => $ar ? 'تفاصيل المنتج' : 'The details', 'rows' => []]),
            $row('story', ['heading' => strip_tags($copy['details_heading'] ?? $name), 'text' => $copy ? implode("\n\n", array_map('strip_tags', $copy['paragraphs'])) : $description, 'image' => $image, 'image_alt' => $name, 'cta_label' => $cta]),
            $row('features', ['heading' => $ar ? 'تفاصيل تستحق الاهتمام' : 'A closer look', 'items' => []], false),
            $row('media', ['heading' => '', 'image' => $image, 'image_alt' => $name, 'video_url' => ''], $image !== ''),
            $row('quote', ['text' => '', 'attribution' => ''], false),
            $row('reviews', ['heading' => $ar ? 'آراء العملاء' : 'Customer reviews']),
            $row('faq', ['heading' => strip_tags($copy['faq_heading'] ?? ($ar ? 'لديك سؤال؟' : 'Questions?')), 'image' => $image, 'image_alt' => $name, 'items' => array_map(fn ($faq) => ['question' => strip_tags($faq['question']), 'answer' => strip_tags($faq['answer'])], $copy['faqs'] ?? [])], ! empty($copy['faqs'])),
            $row('closing', ['heading' => $name, 'text' => $store->name, 'cta_label' => $cta]),
        ];
    }
}
