<?php

namespace App\Services\PageBuilder;

use App\Models\Product;
use App\Models\Store;

/** Independent dark campaign; sample catalog photographs never seed a merchant page. */
class ElectricBikerFunnelTemplate
{
    public const TEMPLATES = [
        'electric-biker-ar' => ['name' => ['ar' => 'Electric Biker — عربي', 'en' => 'Electric Biker — Arabic'], 'description' => ['ar' => 'حملة داكنة وصور تفاصيل ولوحات متبادلة ومعرض وأسئلة وطلب', 'en' => 'Dark campaign, detail stories, alternating panels, mosaic, questions and checkout'], 'color' => '#202020', 'locale' => 'ar', 'native_layout' => 'electric-biker', 'preview_image' => '/media/funnel-previews/electric-biker-ar.png'],
        'electric-biker-en' => ['name' => ['ar' => 'Electric Biker — إنجليزي', 'en' => 'Electric Biker — English'], 'description' => ['ar' => 'تخطيط الدراجات الداكن باللغة الإنجليزية', 'en' => 'The dark bicycle campaign with English content and left-to-right direction'], 'color' => '#202020', 'locale' => 'en', 'native_layout' => 'electric-biker', 'preview_image' => '/media/funnel-previews/electric-biker-en.png'],
    ];

    public static function schema(): array
    {
        $field = fn ($id, $label, $type = 'text') => ['id' => $id, 'label' => $label, 'type' => $type];
        $heading = $field('heading', 'العنوان / Heading');
        $body = $field('text', 'النص / Text', 'textarea');
        $image = $field('image', 'الصورة / Image', 'image');
        $alt = $field('image_alt', 'وصف الصورة / Image description');
        $cta = $field('cta_label', 'زر الطلب / Purchase button');
        $list = fn ($label, $items, $max) => ['id' => 'items', 'label' => $label, 'type' => 'list', 'item' => $items, 'max' => $max];
        $cards = fn ($max, $button = false) => $list('البطاقات / Cards', $button ? [$heading, $body, $image, $alt, $cta] : [$heading, $body, $image, $alt], $max);
        $section = fn ($label, $settings) => ['label' => $label, 'category' => 'Electric Biker', 'settings' => $settings];

        return [
            'electric-hero' => $section('الغلاف والتنقل / Campaign & navigation', [$field('eyebrow', 'العنوان التمهيدي / Eyebrow'), $heading, $body, $image, $alt, $cta, $field('announcement', 'شريط الإعلان / Announcement'), $field('expires_at', 'انتهاء العرض مع المنطقة الزمنية / Offer expiry with timezone')]),
            'electric-purchase' => ['max_instances' => 1] + $section('المنتج ونموذج الطلب / Product & checkout', [$heading, $body, $cta]),
            'electric-technical' => $section('ستة تفاصيل مصورة / Six illustrated details', [$heading, $cards(6)]),
            'electric-stories' => $section('قصص التفاصيل / Detail stories', [$heading, $cards(3)]),
            'electric-benefits' => $section('لوحات المزايا المتبادلة / Alternating feature panels', [$cards(4, true)]),
            'electric-editorial' => $section('لوحتان تحريريّتان / Two editorial panels', [$cards(2, true)]),
            'electric-closing' => $section('الغلاف الختامي / Closing campaign', [$heading, $body, $image, $alt, $cta]),
            'electric-marquee' => $section('الشريط المتحرك / Moving strip', [$list('العبارات / Phrases', [$field('label', 'العبارة / Phrase')], 8)]),
            'electric-mosaic' => $section('معرض الصور / Photograph mosaic', [$heading, $list('الصور / Photographs', [$image, $alt, $field('caption', 'تعليق الصورة / Caption')], 6)]),
            'electric-reviews' => $section('تقييمات المنتج الفعلية / Actual product reviews', [$heading]),
            'electric-faq' => $section('الأسئلة والصورة / Questions & photograph', [$heading, $image, $alt, $list('الأسئلة / Questions', [$field('question', 'السؤال / Question'), $field('answer', 'الإجابة / Answer', 'textarea')], 12)]),
        ];
    }

    public static function sections(Store $store, Product $product, string $locale, ?array $copy = null): array
    {
        $tr = fn ($ar, $en) => $locale === 'ar' ? $ar : $en;
        $name = strip_tags((string) $product->translated('name', $locale));
        $description = strip_tags((string) $product->translated('description', $locale));
        $image = $product->imageUrl() ?? '';
        $cta = $tr('اطلب الآن', 'Order now');
        $row = fn ($type, $settings, $visible = true) => ['type' => 'electric-'.$type, 'settings' => $settings, 'is_visible' => $visible];
        $cards = fn ($labels) => array_map(fn ($label) => ['heading' => $label, 'text' => '', 'image' => $image, 'image_alt' => $name, 'cta_label' => $cta], $labels);
        $faqs = $copy['faqs'] ?? [
            ['question' => $tr('كيف أختار المنتج؟', 'How do I choose the product?'), 'answer' => $tr('راجع الوصف والصور والخيارات المتاحة في نموذج الطلب.', 'Review the description, photographs and available options in the order form.')],
            ['question' => $tr('كيف أعرف تكلفة التوصيل؟', 'How do I check delivery charges?'), 'answer' => $tr('تظهر تكلفة التوصيل المتاحة لعنوانك قبل تأكيد الطلب.', 'Available delivery charges are shown for your address before confirmation.')],
            ['question' => $tr('ما وسائل الدفع المتاحة؟', 'Which payment methods are available?'), 'answer' => $tr('تظهر وسائل الدفع التي فعّلها المتجر في نموذج الطلب.', 'Methods enabled by the store appear in the order form.')],
        ];

        return [
            $row('hero', ['eyebrow' => $store->name, 'heading' => strip_tags($copy['heading'] ?? $name), 'text' => strip_tags($copy['text'] ?? ''), 'image' => $image, 'image_alt' => $name, 'cta_label' => strip_tags($copy['cta_label'] ?? $cta), 'announcement' => '', 'expires_at' => '']),
            $row('purchase', ['heading' => $name, 'text' => $description, 'cta_label' => $cta]),
            $row('technical', ['heading' => $tr('تفاصيل المنتج', 'Product details'), 'items' => []], false),
            $row('stories', ['heading' => strip_tags($copy['details_heading'] ?? ''), 'items' => $cards([$tr('التصميم', 'Design'), $tr('تفاصيل أقرب', 'A closer look'), $tr('اكتشف المنتج', 'Explore the product')])]),
            $row('benefits', ['items' => $cards([$tr('نظرة أخرى', 'Another view'), $tr('كل التفاصيل', 'Every detail'), $tr('اختر ما يناسبك', 'Choose your option'), $tr('تعرّف أكثر', 'Discover more')])]),
            $row('editorial', ['items' => $cards([$tr('قصة المنتج', 'Product story'), $tr('في يومك', 'In your everyday')])]),
            $row('closing', ['heading' => $name, 'text' => '', 'image' => $image, 'image_alt' => $name, 'cta_label' => $cta]),
            $row('marquee', ['items' => []], false),
            $row('mosaic', ['heading' => $tr('صور المنتج', 'Product photographs'), 'items' => []], false),
            $row('reviews', ['heading' => $tr('آراء العملاء', 'What customers say')]),
            $row('faq', ['heading' => strip_tags($copy['faq_heading'] ?? $tr('أسئلة متكررة', 'Frequently asked questions')), 'image' => $image, 'image_alt' => $name, 'items' => array_map(fn ($faq) => ['question' => strip_tags($faq['question']), 'answer' => strip_tags($faq['answer'])], $faqs)]),
        ];
    }
}
