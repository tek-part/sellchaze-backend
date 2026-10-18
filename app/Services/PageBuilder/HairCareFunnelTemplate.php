<?php

namespace App\Services\PageBuilder;

use App\Models\Product;
use App\Models\Store;

/** Lime/violet editorial composition, independent of the active storefront theme. */
class HairCareFunnelTemplate
{
    public const TEMPLATES = [
        'hair-care-ar' => ['name' => ['ar' => 'Hair Care — عربي', 'en' => 'Hair Care — Arabic'], 'description' => ['ar' => 'غلاف مستدير ومقدمة مصورة وتفاصيل ومزايا وتقييمات وطلب', 'en' => 'Rounded campaign, illustrated introduction, details, features, reviews and checkout'], 'color' => '#ecffb5', 'locale' => 'ar', 'native_layout' => 'hair-care', 'preview_image' => '/media/funnel-previews/hair-care-ar.png'],
        'hair-care-en' => ['name' => ['ar' => 'Hair Care — إنجليزي', 'en' => 'Hair Care — English'], 'description' => ['ar' => 'تخطيط العناية بالشعر بالإنجليزية', 'en' => 'Lime and violet hair-care editorial with English content'], 'color' => '#ecffb5', 'locale' => 'en', 'native_layout' => 'hair-care', 'preview_image' => '/media/funnel-previews/hair-care-en.png'],
    ];

    public static function schema(): array
    {
        $field = fn ($id, $label, $type = 'text') => ['id' => $id, 'label' => $label, 'type' => $type];
        $heading = $field('heading', 'العنوان / Heading');
        $body = $field('text', 'النص / Text', 'textarea');
        $image = $field('image', 'الصورة / Image', 'image');
        $alt = $field('image_alt', 'وصف الصورة / Image description');
        $cta = $field('cta_label', 'زر الطلب / Purchase button');
        $list = fn ($id, $label, $items, $max) => ['id' => $id, 'label' => $label, 'type' => 'list', 'item' => $items, 'max' => $max];
        $section = fn ($label, $settings) => ['label' => $label, 'category' => 'Hair Care', 'settings' => $settings];

        return [
            'haircare-hero' => $section('الغلاف والتنقل / Campaign & navigation', [$heading, $body, $image, $alt, $cta, $field('announcement', 'شريط الإعلان / Announcement'), $field('expires_at', 'انتهاء العرض مع المنطقة الزمنية / Offer expiry with timezone')]),
            'haircare-intro' => $section('المقدمة والصور المتداخلة / Introduction & overlapping photographs', [$heading, $body, $cta, $list('photos', 'الصور الزخرفية / Decorative photographs', [$image, $alt], 3), $list('badges', 'الشارات / Badges', [$field('label', 'النص / Label')], 4)]),
            'haircare-purchase' => ['max_instances' => 1] + $section('المنتج ونموذج الطلب / Product & checkout', [$heading, $body, $cta]),
            'haircare-details' => $section('الصورة ولوحة التفاصيل / Photograph & detail rows', [$image, $alt, $list('items', 'التفاصيل / Details', [$heading, $body], 3)]),
            'haircare-marquee' => $section('الشريط المتحرك / Moving strip', [$list('items', 'العبارات / Phrases', [$field('label', 'العبارة / Phrase')], 8)]),
            'haircare-features' => $section('لوحات المزايا المصورة / Illustrated feature panels', [$list('items', 'اللوحات / Panels', [$heading, $body, $image, $alt, $cta], 3)]),
            'haircare-assurances' => $section('معلومات خدمات المتجر / Store service information', [$list('items', 'الخدمات الفعلية / Actual services', [$heading, $body], 4)]),
            'haircare-reviews' => $section('تقييمات المنتج الفعلية / Actual product reviews', [$heading]),
            'haircare-faq' => $section('الأسئلة الشائعة / Frequently asked questions', [$heading, $list('items', 'الأسئلة / Questions', [$field('question', 'السؤال / Question'), $field('answer', 'الإجابة / Answer', 'textarea')], 12)]),
        ];
    }

    public static function sections(Store $store, Product $product, string $locale, ?array $copy = null): array
    {
        $tr = fn ($ar, $en) => $locale === 'ar' ? $ar : $en;
        $name = strip_tags((string) $product->translated('name', $locale));
        $description = strip_tags((string) $product->translated('description', $locale));
        $image = $product->imageUrl() ?? '';
        $cta = $tr('اطلب الآن', 'Order now');
        $row = fn ($type, $settings, $visible = true) => ['type' => 'haircare-'.$type, 'settings' => $settings, 'is_visible' => $visible];
        $faqs = $copy['faqs'] ?? [
            ['question' => $tr('كيف أختار المنتج؟', 'How do I choose the product?'), 'answer' => $tr('راجع الوصف والصور والخيارات المتاحة في نموذج الطلب.', 'Review the description, photographs and available options in the order form.')],
            ['question' => $tr('كيف أعرف تكلفة التوصيل؟', 'How do I check delivery charges?'), 'answer' => $tr('تظهر تكلفة التوصيل المتاحة لعنوانك قبل تأكيد الطلب.', 'Available delivery charges are shown for your address before confirmation.')],
            ['question' => $tr('ما وسائل الدفع المتاحة؟', 'Which payment methods are available?'), 'answer' => $tr('تظهر وسائل الدفع التي فعّلها المتجر في نموذج الطلب.', 'Methods enabled by the store appear in the order form.')],
        ];

        return [
            $row('hero', ['heading' => strip_tags($copy['heading'] ?? $name), 'text' => strip_tags($copy['text'] ?? ''), 'image' => $image, 'image_alt' => $name, 'cta_label' => strip_tags($copy['cta_label'] ?? $cta), 'announcement' => '', 'expires_at' => '']),
            $row('intro', ['heading' => $name, 'text' => $description, 'cta_label' => $cta, 'photos' => [], 'badges' => []]),
            $row('purchase', ['heading' => $name, 'text' => '', 'cta_label' => $cta]),
            $row('details', ['image' => $image, 'image_alt' => $name, 'items' => array_map(fn ($label) => ['heading' => $label, 'text' => ''], [$tr('تفاصيل المنتج', 'Product details'), $tr('المواصفات', 'Specifications'), $tr('طريقة الاستخدام', 'How to use')])]),
            $row('marquee', ['items' => []], false),
            $row('features', ['items' => array_map(fn ($label) => ['heading' => $label, 'text' => '', 'image' => $image, 'image_alt' => $name, 'cta_label' => $cta], [$tr('اكتشف المنتج', 'Discover the product'), $tr('نظرة أقرب', 'A closer look'), $tr('كل التفاصيل', 'Every detail')])]),
            $row('marquee', ['items' => []], false),
            $row('assurances', ['items' => []], false),
            $row('marquee', ['items' => []], false),
            $row('reviews', ['heading' => $tr('آراء العملاء', 'What customers say')]),
            $row('faq', ['heading' => strip_tags($copy['faq_heading'] ?? $tr('الأسئلة الشائعة وإجاباتها', 'Frequently asked questions')), 'items' => array_map(fn ($faq) => ['question' => strip_tags($faq['question']), 'answer' => strip_tags($faq['answer'])], $faqs)]),
        ];
    }
}
