<?php

namespace App\Services\PageBuilder;

use App\Models\Product;
use App\Models\Store;

/** Independent campaign schema; template copy never invents efficacy, endorsements or sales. */
class HairReviveFunnelTemplate
{
    public const TEMPLATES = [
        'hair-revive-ar' => ['name' => ['ar' => 'العناية بالشعر — عربي', 'en' => 'Hair Revive — Arabic'], 'description' => ['ar' => 'غلاف وأقسام تفاصيل وفيديو ومقارنات وإحصاءات قابلة للتحرير ونموذج طلب', 'en' => 'Campaign, numbered details, video, comparisons, editable statistics and real checkout'], 'color' => '#0f3956', 'locale' => 'ar', 'native_layout' => 'hair-revive', 'preview_image' => '/media/funnel-previews/hair-revive-ar.png'],
        'hair-revive-en' => ['name' => ['ar' => 'العناية بالشعر — إنجليزي', 'en' => 'Hair Revive — English'], 'description' => ['ar' => 'تخطيط العناية بالشعر باللغة الإنجليزية واتجاه من اليسار لليمين', 'en' => 'The Hair Revive composition with English content and left-to-right direction'], 'color' => '#0f3956', 'locale' => 'en', 'native_layout' => 'hair-revive', 'preview_image' => '/media/funnel-previews/hair-revive-en.png'],
    ];

    public static function schema(): array
    {
        $field = fn ($id, $label, $type = 'text') => ['id' => $id, 'label' => $label, 'type' => $type];
        $heading = $field('heading', 'العنوان / Heading');
        $body = $field('text', 'النص / Text', 'textarea');
        $image = $field('image', 'الصورة / Image', 'image');
        $alt = $field('image_alt', 'وصف الصورة / Image description');
        $cta = $field('cta_label', 'زر الطلب / Purchase button');
        $list = fn ($id, $label, $item, $max = 12) => ['id' => $id, 'label' => $label, 'type' => 'list', 'item' => $item, 'max' => $max];
        $section = fn ($label, $settings) => ['label' => $label, 'category' => 'Hair Revive', 'settings' => $settings];

        return [
            'hair-hero' => $section('الغلاف والتنقل / Campaign & navigation', [$heading, $body, $image, $alt, $cta, $field('announcement', 'شريط العرض / Announcement'), $field('expires_at', 'انتهاء العرض ISO 8601 / Offer expiry with timezone'), $field('badge_value', 'نص الشارة الكبير / Badge value'), $field('badge_heading', 'عنوان الشارة / Badge heading'), $field('badge_text', 'وصف الشارة / Badge description')]),
            'hair-purchase' => ['max_instances' => 1] + $section('المنتج والطلب / Product & checkout', [$heading, $body, $cta, $list('highlights', 'تفاصيل مختصرة / Highlights', [$field('label', 'العنوان / Label')], 3)]),
            'hair-statement' => $section('الشريط الكحلي / Statement band', [$heading, $body]),
            'hair-factors' => $section('التفاصيل المرقمة / Numbered details', [$list('items', 'بطاقات التفاصيل / Detail cards', [$heading, $body, $image, $alt], 8)]),
            'hair-video' => $section('الفيديو / Video', [$heading, $image, $alt, $field('video_url', 'رابط فيديو HTTPS أو YouTube / HTTPS or YouTube video URL')]),
            'hair-comparisons' => $section('مقارنة الصور / Image comparisons', [$heading, $list('items', 'صفوف المقارنة / Comparison rows', [$heading, $body, $field('before_image', 'الصورة الأولى / First image', 'image'), $field('after_image', 'الصورة الثانية / Second image', 'image'), $field('before_label', 'عنوان الصورة الأولى / First image label'), $field('after_label', 'عنوان الصورة الثانية / Second image label')], 6)]),
            'hair-statistics' => $section('لوحة المعلومات / Information panel', [$image, $alt, $list('items', 'البطاقات / Cards', [$field('value', 'القيمة / Value'), $field('label', 'الوصف / Description')], 4)]),
            'hair-quote' => $section('الرسالة التحريرية / Editorial message', [$body, $field('attribution', 'اسم العلامة أو صاحب النص / Brand or attribution')]),
            'hair-marquee' => $section('الشريط المتحرك / Moving strip', [$list('items', 'عبارات الشريط / Strip phrases', [$field('label', 'العبارة / Phrase')], 8)]),
            'hair-reviews' => $section('تقييمات المنتج الفعلية / Actual product reviews', [$heading]),
            'hair-faq' => $section('الأسئلة الشائعة / Frequently asked questions', [$heading, $list('items', 'الأسئلة والإجابات / Questions & answers', [$field('question', 'السؤال / Question'), $field('answer', 'الإجابة / Answer', 'textarea')])]),
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
        $row = fn ($type, $settings, $visible = true) => ['type' => 'hair-'.$type, 'settings' => $settings, 'is_visible' => $visible];
        $statement = fn ($heading, $text) => $row('statement', ['heading' => $heading, 'text' => $text]);
        $faqs = $copy['faqs'] ?? [
            ['question' => $tr('كيف أختار الخيار المناسب؟', 'How do I choose an option?'), 'answer' => $tr('اختر من الخيارات المتاحة داخل نموذج الطلب.', 'Choose from the available options in the order form.')],
            ['question' => $tr('أين أجد وصف المنتج؟', 'Where can I find product details?'), 'answer' => $tr('تجد الصور والوصف والتفاصيل في هذه الصفحة.', 'Photographs, descriptions and details are available on this page.')],
            ['question' => $tr('كيف أعرف تكلفة التوصيل؟', 'How can I check delivery charges?'), 'answer' => $tr('يعرض نموذج الطلب تكلفة التوصيل المتاحة لعنوانك قبل التأكيد.', 'The order form shows available delivery charges for your address before confirmation.')],
            ['question' => $tr('ما وسائل الدفع المتاحة؟', 'Which payment methods are available?'), 'answer' => $tr('تظهر وسائل الدفع المفعّلة في نموذج الطلب.', 'Enabled payment methods are shown in the order form.')],
            ['question' => $tr('هل يمكنني تغيير الكمية؟', 'Can I change the quantity?'), 'answer' => $tr('استخدم خيارات الكمية المتاحة في نموذج الطلب.', 'Use the quantity options available in the order form.')],
            ['question' => $tr('كيف أتواصل مع المتجر؟', 'How do I contact the store?'), 'answer' => $tr('راجع روابط وبيانات التواصل الموجودة في المتجر.', 'Use the contact information and links provided by the store.')],
        ];

        return [
            $row('hero', ['heading' => strip_tags($copy['heading'] ?? $name), 'text' => strip_tags($copy['text'] ?? ''), 'image' => $image, 'image_alt' => $name, 'cta_label' => strip_tags($copy['cta_label'] ?? $cta), 'announcement' => '', 'expires_at' => '', 'badge_value' => '', 'badge_heading' => $store->name, 'badge_text' => '']),
            $row('purchase', ['heading' => $name, 'text' => '', 'cta_label' => $cta, 'highlights' => []]),
            $statement(strip_tags($copy['details_heading'] ?? $tr('تعرّف على التفاصيل.', 'Get to know the details.')), $copy ? implode("\n\n", array_map('strip_tags', $copy['paragraphs'])) : $description),
            $row('factors', ['items' => array_map(fn ($label) => ['heading' => $label, 'text' => '', 'image' => $image, 'image_alt' => $name], [$tr('المنتج', 'The product'), $tr('التفاصيل', 'The details'), $tr('اختياراتك', 'Your options'), $tr('روتينك', 'Your routine'), $tr('معلومات إضافية', 'More information')])]),
            $statement($tr('كل التفاصيل في مكان واحد.', 'All the details, in one place.'), $tr('استكشف الصور ومعلومات المنتج، واختر ما يناسبك.', 'Explore the photographs and product information, then choose your option.')),
            $row('video', ['heading' => $tr('نظرة أقرب', 'A closer look'), 'image' => $image, 'image_alt' => $name, 'video_url' => '']),
            $statement($tr('اختر ما يناسب يومك.', 'Choose what fits your day.'), $tr('راجع تفاصيل المنتج وخياراته قبل إتمام الطلب.', 'Review the product details and available options before placing your order.')),
            $row('comparisons', ['heading' => $tr('الصور والتفاصيل', 'Photographs & details'), 'items' => []], false),
            $statement($tr('تفاصيل صغيرة. اختياراتك أنت.', 'Small details. Your own choices.'), $tr('تعرّف على المنتج من خلال الصور والوصف.', 'Get to know the product through its photographs and description.')),
            $row('statistics', ['image' => $image, 'image_alt' => $name, 'items' => []], false),
            $statement($tr('رسالة من المتجر.', 'A note from the store.'), $store->name),
            $row('quote', ['text' => '', 'attribution' => ''], false),
            $row('marquee', ['items' => []], false),
            $row('reviews', ['heading' => $tr('آراء العملاء', 'What customers say')]),
            $row('faq', ['heading' => strip_tags($copy['faq_heading'] ?? $tr('أسئلة متكررة', 'Frequently asked questions')), 'items' => array_map(fn ($faq) => ['question' => strip_tags($faq['question']), 'answer' => strip_tags($faq['answer'])], $faqs)]),
        ];
    }
}
