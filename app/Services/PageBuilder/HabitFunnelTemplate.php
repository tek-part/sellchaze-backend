<?php

namespace App\Services\PageBuilder;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Str;

/** Violet HABIT composition with separate localized section sequences. */
class HabitFunnelTemplate
{
    public const TEMPLATES = [
        'habit-ar' => ['name' => ['ar' => 'HABIT — عربي', 'en' => 'HABIT — Arabic'], 'description' => ['ar' => 'غلاف بنفسجي ومميزات وأرقام وقصة وشبكة صور ونتائج وتقييمات وطلب', 'en' => 'Violet cover, features, figures, story, photo grid, results, reviews and checkout'], 'color' => '#483a97', 'locale' => 'ar', 'native_layout' => 'habit', 'preview_image' => '/media/funnel-previews/habit-ar.png'],
        'habit-en' => ['name' => ['ar' => 'HABIT — إنجليزي', 'en' => 'HABIT — English'], 'description' => ['ar' => 'تكوين إنجليزي مستقل للمميزات والأرقام والنتائج ونموذج الشراء', 'en' => 'Independent English feature, figures, results and purchase composition'], 'color' => '#483a97', 'locale' => 'en', 'native_layout' => 'habit', 'preview_image' => '/media/funnel-previews/habit-en.png'],
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
        $section = fn ($label, $settings) => ['label' => $label, 'category' => 'HABIT', 'settings' => $settings];

        return [
            'habit-hero' => $section('الغلاف والتنقل / Cover & navigation', [$heading, $body, $image, $alt, $cta, $field('announcement', 'الإعلان / Announcement'), $field('expires_at', 'انتهاء العرض مع المنطقة الزمنية / Offer expiry with timezone')]),
            'habit-purchase' => ['max_instances' => 1] + $section('المنتج ونموذج الطلب / Product & checkout', [$heading, $body, $cta]),
            'habit-marquee' => $section('الشريط المتحرك / Moving strip', [$list('items', 'العبارات / Phrases', [$field('label', 'العبارة / Phrase')], 8)]),
            'habit-features' => $section('المميزات حول الصورة / Features around a photograph', [$image, $alt, $list('items', 'المميزات / Features', [$heading, $body], 4)]),
            'habit-numbers' => $section('الأرقام والتفاصيل / Figures & details', [$heading, $image, $alt, $list('items', 'الأرقام الفعلية / Actual figures', [$field('value', 'القيمة / Value'), $field('label', 'التوضيح ومصدره / Explanation and source', 'textarea')], 4)]),
            'habit-story' => $section('القصة وشبكة الصور / Story & photograph grid', [$heading, $body, $list('items', 'الصور / Photographs', [$image, $alt], 4)]),
            'habit-statement' => $section('الصورة والبيان / Photograph & statement', [$heading, $body, $image, $alt]),
            'habit-results' => $section('لوحة النتائج / Results panel', [$heading, $field('subheading', 'العنوان الثاني / Subheading'), $image, $alt, $list('items', 'مجموعات النتائج الفعلية / Actual result groups', [$heading, $field('value_a', 'القيمة الأولى / First value'), $field('text_a', 'توضيح القيمة الأولى / First explanation', 'textarea'), $field('value_b', 'القيمة الثانية / Second value'), $field('text_b', 'توضيح القيمة الثانية / Second explanation', 'textarea')], 2)]),
            'habit-reviews' => $section('تقييمات المنتج الفعلية / Actual product reviews', [$heading, $field('subheading', 'العنوان الثاني / Subheading')]),
            'habit-faq' => $section('الأسئلة الشائعة / Frequently asked questions', [$heading, $cta, $list('items', 'الأسئلة / Questions', [$field('question', 'السؤال / Question'), $field('answer', 'الإجابة / Answer', 'textarea')], 12)]),
        ];
    }

    public static function sections(Store $store, Product $product, string $locale, ?array $copy = null): array
    {
        $tr = fn ($ar, $en) => $locale === 'ar' ? $ar : $en;
        $name = strip_tags((string) $product->translated('name', $locale));
        $description = strip_tags((string) $product->translated('description', $locale));
        $short = strip_tags((string) ($product->translated('short_description', $locale) ?: $description));
        $image = $product->imageUrl() ?? '';
        $cta = strip_tags($copy['cta_label'] ?? $tr('اطلب الآن', 'Order now'));
        $row = fn ($type, $settings, $visible = true) => ['type' => 'habit-'.$type, 'settings' => $settings, 'is_visible' => $visible];
        $marquee = fn () => $row('marquee', ['items' => []], false);
        $faqs = $copy['faqs'] ?? [
            ['question' => $tr('كيف أختار المنتج؟', 'How do I choose the product?'), 'answer' => $tr('راجع وصف المنتج وصوره والخيارات المتاحة في نموذج الطلب.', 'Review the product description, photographs and available order-form options.')],
            ['question' => $tr('هل تتوفر خيارات مختلفة؟', 'Are different options available?'), 'answer' => $tr('يعرض نموذج الطلب خيارات المنتج التي أتاحها المتجر.', 'The order form displays product options provided by the store.')],
            ['question' => $tr('كيف أعرف تكلفة التوصيل؟', 'How do I check delivery charges?'), 'answer' => $tr('تظهر تكلفة التوصيل المتاحة لعنوانك قبل تأكيد الطلب.', 'Available delivery charges for your address appear before confirmation.')],
            ['question' => $tr('ما وسائل الدفع المتاحة؟', 'Which payment methods are available?'), 'answer' => $tr('تظهر وسائل الدفع التي فعّلها المتجر داخل نموذج الطلب.', 'Methods enabled by the store appear in the order form.')],
            ['question' => $tr('أين أجد سياسة الاسترجاع؟', 'Where is the return policy?'), 'answer' => $tr('راجع روابط سياسات المتجر أو تواصل معه قبل إتمام الطلب.', 'Review the store policy links or contact the store before ordering.')],
            ['question' => $tr('كيف أتواصل مع المتجر؟', 'How do I contact the store?'), 'answer' => $tr('تجد وسائل التواصل التي أتاحها المتجر في أسفل الصفحة.', 'Contact methods provided by the store appear at the bottom of the page.')],
        ];
        $sections = [
            $row('hero', ['heading' => strip_tags($copy['heading'] ?? $name), 'text' => Str::limit(strip_tags($copy['text'] ?? $short), 350), 'image' => $image, 'image_alt' => $name, 'cta_label' => $cta, 'announcement' => '', 'expires_at' => '']),
            $row('purchase', ['heading' => $name, 'text' => '', 'cta_label' => $cta]),
            $marquee(),
            $row('features', ['image' => $image, 'image_alt' => $name, 'items' => [['heading' => $name, 'text' => $short]]]),
            $marquee(),
            $row('numbers', ['heading' => $tr('تفاصيل بالأرقام', 'Details in figures'), 'image' => $image, 'image_alt' => $name, 'items' => []], false),
            $marquee(),
        ];
        if ($locale === 'ar') {
            $sections[] = $row('story', ['heading' => strip_tags($copy['details_heading'] ?? $tr('قصة المنتج وتفاصيله', 'Product story & details')), 'text' => $copy ? implode("\n\n", array_map('strip_tags', $copy['paragraphs'] ?? [$description])) : $description, 'items' => $image !== '' ? array_fill(0, 4, ['image' => $image, 'image_alt' => $name]) : []]);
            $sections[] = $row('statement', ['heading' => '', 'text' => '', 'image' => $image, 'image_alt' => $name], false);
        }
        $sections[] = $row('results', ['heading' => $tr('تفاصيل المنتج', 'Product details'), 'subheading' => '', 'image' => $image, 'image_alt' => $name, 'items' => []], false);
        $sections[] = $row('reviews', ['heading' => $tr('آراء العملاء', 'What customers say'), 'subheading' => '']);
        $sections[] = $row('faq', ['heading' => strip_tags($copy['faq_heading'] ?? ''), 'cta_label' => $cta, 'items' => array_map(fn ($faq) => ['question' => strip_tags($faq['question']), 'answer' => strip_tags($faq['answer'])], $faqs)]);

        return $sections;
    }
}
