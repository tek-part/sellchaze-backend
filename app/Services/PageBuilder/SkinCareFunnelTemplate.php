<?php

namespace App\Services\PageBuilder;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Str;

/** Full-bleed terracotta campaign with separate editorial, policy and quote sections. */
class SkinCareFunnelTemplate
{
    public const TEMPLATES = [
        'skin-care-ar' => ['name' => ['ar' => 'Skin Care — عربي', 'en' => 'Skin Care — Arabic'], 'description' => ['ar' => 'غلاف كامل ومواصفات وسياسات وقصة مصورة واقتباس وتقييمات وطلب', 'en' => 'Full-bleed campaign, specifications, policies, editorial story, quote, reviews and checkout'], 'color' => '#c55635', 'locale' => 'ar', 'native_layout' => 'skin-care', 'preview_image' => '/media/funnel-previews/skin-care-ar.png'],
        'skin-care-en' => ['name' => ['ar' => 'Skin Care — إنجليزي', 'en' => 'Skin Care — English'], 'description' => ['ar' => 'تخطيط العناية بالبشرة بالإنجليزية مع كتلتي مزايا واقتباس', 'en' => 'English skin-care composition with two editable feature and quote pairs'], 'color' => '#c55635', 'locale' => 'en', 'native_layout' => 'skin-care', 'preview_image' => '/media/funnel-previews/skin-care-en.png'],
    ];

    public static function schema(): array
    {
        $field = fn ($id, $label, $type = 'text') => ['id' => $id, 'label' => $label, 'type' => $type];
        $heading = $field('heading', 'العنوان / Heading');
        $body = $field('text', 'النص / Text', 'textarea');
        $image = $field('image', 'الصورة / Image', 'image');
        $alt = $field('image_alt', 'وصف الصورة / Image description');
        $backdrop = $field('backdrop', 'صورة الخلفية / Backdrop photograph', 'image');
        $cta = $field('cta_label', 'زر الطلب / Purchase button');
        $list = fn ($id, $label, $items, $max) => ['id' => $id, 'label' => $label, 'type' => 'list', 'item' => $items, 'max' => $max];
        $questions = [$field('question', 'السؤال / Question'), $field('answer', 'الإجابة / Answer', 'textarea')];
        $section = fn ($label, $settings) => ['label' => $label, 'category' => 'Skin Care', 'settings' => $settings];

        return [
            'skincare-hero' => $section('الغلاف والتنقل / Campaign & navigation', [$heading, $body, $image, $alt, $cta, $field('announcement', 'شريط الإعلان / Announcement'), $field('expires_at', 'انتهاء العرض مع المنطقة الزمنية / Offer expiry with timezone')]),
            'skincare-intro' => $section('المقدمة الكبيرة / Large editorial introduction', [$body]),
            'skincare-purchase' => ['max_instances' => 1] + $section('المنتج ونموذج الطلب / Product & checkout', [$heading, $body, $cta]),
            'skincare-specs' => $section('المواصفات والسياسات / Specifications & policies', [$heading, $image, $alt, $backdrop, $list('items', 'المواصفات الفعلية / Actual specifications', [$field('label', 'النص / Text')], 12), $list('policies', 'سياسات المتجر الفعلية / Actual store policies', $questions, 3)]),
            'skincare-story' => $section('قصة المنتج المصورة / Illustrated product story', [$heading, $body, $image, $alt, $backdrop, $cta]),
            'skincare-marquee' => $section('الشريط المتحرك / Moving strip', [$list('items', 'العبارات / Phrases', [$field('label', 'العبارة / Phrase')], 8)]),
            'skincare-features' => $section('غلاف المزايا / Feature cover', [$image, $alt, $list('items', 'عناوين المزايا / Feature headlines', [$field('label', 'العنوان / Headline')], 5)]),
            'skincare-quote' => $section('اقتباس أو بيان موثّق / Attributed quote or statement', [$body, $field('attribution', 'صاحب البيان / Attribution'), $list('logos', 'شعارات مصرح باستخدامها / Authorized logos', [$image, $alt], 4)]),
            'skincare-experiences' => $section('صورة الحملة الكاملة / Full-bleed editorial photograph', [$heading, $image, $alt]),
            'skincare-reviews' => $section('تقييمات المنتج الفعلية / Actual product reviews', [$heading, $field('subheading', 'العنوان الثاني / Subheading')]),
            'skincare-faq' => $section('الأسئلة والصورة / Questions & photograph', [$heading, $image, $alt, $backdrop, $list('items', 'الأسئلة / Questions', $questions, 12)]),
        ];
    }

    public static function sections(Store $store, Product $product, string $locale, ?array $copy = null): array
    {
        $tr = fn ($ar, $en) => $locale === 'ar' ? $ar : $en;
        $name = strip_tags((string) $product->translated('name', $locale));
        $description = strip_tags((string) $product->translated('description', $locale));
        $short = strip_tags((string) ($product->translated('short_description', $locale) ?: $description));
        $image = $product->imageUrl() ?? '';
        $cta = $tr('اطلب الآن', 'Order now');
        $row = fn ($type, $settings, $visible = true) => ['type' => 'skincare-'.$type, 'settings' => $settings, 'is_visible' => $visible];
        $faqs = $copy['faqs'] ?? [
            ['question' => $tr('كيف أختار المنتج؟', 'How do I choose the product?'), 'answer' => $tr('راجع الوصف والصور والخيارات المتاحة في نموذج الطلب.', 'Review the description, photographs and available options in the order form.')],
            ['question' => $tr('كيف أعرف تكلفة التوصيل؟', 'How do I check delivery charges?'), 'answer' => $tr('تظهر تكلفة التوصيل المتاحة لعنوانك قبل تأكيد الطلب.', 'Available delivery charges are shown for your address before confirmation.')],
            ['question' => $tr('ما وسائل الدفع المتاحة؟', 'Which payment methods are available?'), 'answer' => $tr('تظهر وسائل الدفع التي فعّلها المتجر في نموذج الطلب.', 'Methods enabled by the store appear in the order form.')],
        ];
        $features = fn () => $row('features', ['image' => $image, 'image_alt' => $name, 'items' => array_map(fn ($label) => ['label' => $label], [$name, $tr('تفاصيل المنتج', 'Product details'), $tr('نظرة أقرب', 'A closer look'), $tr('خيارات الطلب', 'Order options'), $tr('معلومات المتجر', 'Store information')])]);
        $quote = fn () => $row('quote', ['text' => '', 'attribution' => '', 'logos' => []], false);
        $sections = [
            $row('hero', ['heading' => strip_tags($copy['heading'] ?? $name), 'text' => strip_tags($copy['text'] ?? ''), 'image' => $image, 'image_alt' => $name, 'cta_label' => strip_tags($copy['cta_label'] ?? $cta), 'announcement' => '', 'expires_at' => '']),
            $row('intro', ['text' => Str::limit($short, 240)]),
            $row('purchase', ['heading' => $name, 'text' => '', 'cta_label' => $cta]),
            $row('specs', ['heading' => $tr('المواصفات', 'Specifications'), 'image' => $image, 'image_alt' => $name, 'backdrop' => '', 'items' => [], 'policies' => []]),
            $row('story', ['heading' => strip_tags($copy['details_heading'] ?? $tr('قصة المنتج', 'The product story')), 'text' => $copy ? implode("\n\n", array_map('strip_tags', $copy['paragraphs'] ?? [$description])) : $description, 'image' => $image, 'image_alt' => $name, 'backdrop' => '', 'cta_label' => $cta]),
            $row('marquee', ['items' => []], false),
            $features(), $quote(),
        ];
        // The English reference contains two pairs. Each instance remains independently editable.
        if ($locale === 'en') {
            array_push($sections, $features(), $quote());
        }
        array_push($sections,
            $row('experiences', ['heading' => $tr('صور المنتج', 'Product photographs'), 'image' => $image, 'image_alt' => $name], $image !== ''),
            $row('marquee', ['items' => []], false),
            $row('reviews', ['heading' => $tr('آراء العملاء', 'What customers say'), 'subheading' => '']),
            $row('faq', ['heading' => strip_tags($copy['faq_heading'] ?? ''), 'image' => $image, 'image_alt' => $name, 'backdrop' => '', 'items' => array_map(fn ($faq) => ['question' => strip_tags($faq['question']), 'answer' => strip_tags($faq['answer'])], $faqs)]),
        );

        return $sections;
    }
}
