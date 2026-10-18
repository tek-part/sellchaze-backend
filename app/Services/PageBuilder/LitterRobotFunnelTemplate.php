<?php

namespace App\Services\PageBuilder;

use App\Models\Product;
use App\Models\Store;

/** The pet-appliance campaign has independent editable sections and one real checkout. */
class LitterRobotFunnelTemplate
{
    public const TEMPLATES = [
        'litter-robot-ar' => ['name' => ['ar' => 'Litter Robot — عربي', 'en' => 'Litter Robot — Arabic'], 'description' => ['ar' => 'غلاف ومواصفات وبطاقات مزايا وتفاصيل مصورة ونموذج طلب', 'en' => 'Campaign, specifications, feature cards, illustrated details and checkout'], 'color' => '#fbb040', 'locale' => 'ar', 'native_layout' => 'litter-robot', 'preview_image' => '/media/funnel-previews/litter-robot-ar.png'],
        'litter-robot-en' => ['name' => ['ar' => 'Litter Robot — إنجليزي', 'en' => 'Litter Robot — English'], 'description' => ['ar' => 'تخطيط الأجهزة الأليفة باللغة الإنجليزية من اليسار لليمين', 'en' => 'The pet-appliance composition with English content and left-to-right direction'], 'color' => '#fbb040', 'locale' => 'en', 'native_layout' => 'litter-robot', 'preview_image' => '/media/funnel-previews/litter-robot-en.png'],
    ];

    public static function schema(): array
    {
        $field = fn ($id, $label, $type = 'text') => ['id' => $id, 'label' => $label, 'type' => $type];
        $heading = $field('heading', 'العنوان / Heading');
        $body = $field('text', 'النص / Text', 'textarea');
        $image = $field('image', 'الصورة / Image', 'image');
        $alt = $field('image_alt', 'وصف الصورة / Image description');
        $cta = $field('cta_label', 'زر الطلب / Purchase button');
        $list = fn ($id, $label, $items, $max = 12) => ['id' => $id, 'label' => $label, 'type' => 'list', 'item' => $items, 'max' => $max];
        $cards = $list('items', 'البطاقات / Cards', [$heading, $body, $image, $alt], 8);
        $questions = $list('items', 'الأسئلة / Questions', [$field('question', 'السؤال / Question'), $field('answer', 'الإجابة / Answer', 'textarea')]);
        $section = fn ($label, $settings) => ['label' => $label, 'category' => 'Litter Robot', 'settings' => $settings];

        return [
            'litter-hero' => $section('الغلاف والتنقل / Campaign & navigation', [$field('eyebrow', 'العنوان التمهيدي / Eyebrow'), $heading, $body, $image, $alt, $cta, $field('announcement', 'شريط الإعلان / Announcement'), $field('expires_at', 'موعد انتهاء العرض مع المنطقة الزمنية / Offer expiry with timezone'), $field('offer_heading', 'عنوان بطاقة العرض / Offer heading'), $field('offer_text', 'نص بطاقة العرض / Offer text'), $field('offer_value', 'قيمة بطاقة العرض / Offer value')]),
            'litter-purchase' => ['max_instances' => 1] + $section('المنتج ونموذج الطلب / Product & checkout', [$heading, $body, $cta]),
            'litter-assurances' => $section('التفاصيل والأسئلة المختصرة / Highlights & short questions', [$heading, $cards, $list('questions', 'أسئلة مختصرة / Short questions', [$field('question', 'السؤال / Question'), $field('answer', 'الإجابة / Answer', 'textarea')], 6)]),
            'litter-specifications' => $section('المواصفات المصورة / Illustrated specifications', [$list('items', 'لوحات الصور / Image panels', [$image, $alt, $list('labels', 'المواصفات / Specifications', [$field('label', 'البند / Label'), $field('value', 'القيمة / Value')], 6)], 2)]),
            'litter-feature-cards' => $section('بطاقات المزايا / Feature cards', [$cards]),
            'litter-feature-orbit' => $section('مزايا حول المنتج / Features around the product', [$image, $alt, array_replace($cards, ['max' => 4])]),
            'litter-technical' => $section('لوحة المواصفات الفنية / Technical panel', [$heading, $image, $alt, $cards]),
            'litter-editorial' => $section('لوحة تفاصيل عريضة / Wide editorial panel', [$heading, $body, $image, $alt, $cta]),
            'litter-feature-rows' => $section('صفوف المزايا / Feature rows', [$cards]),
            'litter-quote' => $section('رسالة المتجر / Store message', [$body, $field('attribution', 'صاحب النص / Attribution'), $list('images', 'صور الرسالة / Message photographs', [$image, $alt], 4)]),
            'litter-marquee' => $section('الشريط المتحرك / Moving strip', [$list('items', 'العبارات / Phrases', [$field('label', 'العبارة / Phrase')], 8)]),
            'litter-reviews' => $section('تقييمات المنتج الفعلية / Actual product reviews', [$heading]),
            'litter-faq' => $section('الأسئلة الشائعة / Frequently asked questions', [$heading, $questions]),
        ];
    }

    public static function sections(Store $store, Product $product, string $locale, ?array $copy = null): array
    {
        $ar = $locale === 'ar';
        $tr = fn ($a, $e) => $ar ? $a : $e;
        $name = strip_tags((string) $product->translated('name', $locale));
        $description = strip_tags((string) $product->translated('description', $locale));
        $image = $product->imageUrl() ?? '';
        $cta = $tr('اطلب الآن', 'Order now');
        $row = fn ($type, $settings, $visible = true) => ['type' => 'litter-'.$type, 'settings' => $settings, 'is_visible' => $visible];
        $cards = fn ($labels) => array_map(fn ($label) => ['heading' => $label, 'text' => '', 'image' => $image, 'image_alt' => $name], $labels);
        $faqs = $copy['faqs'] ?? [
            ['question' => $tr('كيف أختار المنتج؟', 'How do I choose the product?'), 'answer' => $tr('راجع الصور والوصف وخيارات المنتج في نموذج الطلب.', 'Review the photographs, description and product options in the order form.')],
            ['question' => $tr('كيف أعرف تكلفة الشحن؟', 'How do I check delivery charges?'), 'answer' => $tr('تظهر تكلفة الشحن المتاحة لعنوانك قبل تأكيد الطلب.', 'Available delivery charges are shown for your address before confirmation.')],
            ['question' => $tr('ما وسائل الدفع المتاحة؟', 'Which payment methods are available?'), 'answer' => $tr('تظهر الوسائل التي فعّلها المتجر في نموذج الطلب.', 'Payment methods enabled by the store appear in the order form.')],
        ];

        return [
            $row('hero', ['eyebrow' => '', 'heading' => strip_tags($copy['heading'] ?? $name), 'text' => strip_tags($copy['text'] ?? ''), 'image' => $image, 'image_alt' => $name, 'cta_label' => strip_tags($copy['cta_label'] ?? $cta), 'announcement' => '', 'expires_at' => '', 'offer_heading' => '', 'offer_text' => '', 'offer_value' => '']),
            $row('purchase', ['heading' => $name, 'text' => $description, 'cta_label' => $cta]),
            $row('assurances', ['heading' => $tr('التفاصيل', 'Details'), 'items' => $cards([$tr('صور المنتج', 'Product photographs'), $tr('معلومات المنتج', 'Product information'), $tr('خيارات الطلب', 'Order options')]), 'questions' => []]),
            $row('specifications', ['items' => []], false),
            $row('feature-cards', ['items' => $cards([$tr('تعرّف على المنتج', 'Explore the product'), $tr('تفاصيل أقرب', 'A closer look'), $tr('اختر ما يناسبك', 'Choose your option')])]),
            $row('feature-orbit', ['image' => $image, 'image_alt' => $name, 'items' => []], false),
            $row('technical', ['heading' => strip_tags($copy['details_heading'] ?? $tr('معلومات المنتج', 'Product information')), 'image' => $image, 'image_alt' => $name, 'items' => []], false),
            ...array_map(fn ($heading, $index) => $row('editorial', ['heading' => $heading, 'text' => strip_tags($copy['paragraphs'][$index] ?? ''), 'image' => $image, 'image_alt' => $name, 'cta_label' => '']), [$tr('اكتشف التفاصيل', 'Discover the details'), $tr('في مكانه المناسب', 'Find its place'), $tr('نظرة أقرب', 'Take a closer look'), $tr('اختياراتك أنت', 'Your own choice')], range(0, 3)),
            $row('feature-rows', ['items' => []], false),
            $row('quote', ['text' => '', 'attribution' => $store->name, 'images' => []], false),
            $row('marquee', ['items' => []], false),
            $row('reviews', ['heading' => $tr('آراء العملاء', 'What customers say')]),
            $row('faq', ['heading' => strip_tags($copy['faq_heading'] ?? $tr('أسئلة متكررة', 'Frequently asked questions')), 'items' => array_map(fn ($faq) => ['question' => strip_tags($faq['question']), 'answer' => strip_tags($faq['answer'])], $faqs)]),
        ];
    }
}
