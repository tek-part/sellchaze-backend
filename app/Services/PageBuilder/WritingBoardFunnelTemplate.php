<?php

namespace App\Services\PageBuilder;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Str;

/** Independent red/charcoal writing campaign; demo assets never initialize merchant pages. */
class WritingBoardFunnelTemplate
{
    public const TEMPLATES = [
        'writing-board-ar' => ['name' => ['ar' => 'Writing Board — عربي', 'en' => 'Writing Board — Arabic'], 'description' => ['ar' => 'غلاف أحمر وتفاصيل كبيرة وشبكة صور وبيان وتقييمات وطلب', 'en' => 'Red cover, large editorial details, image grid, statement, reviews and checkout'], 'color' => '#d92d36', 'locale' => 'ar', 'native_layout' => 'writing-board', 'preview_image' => '/media/funnel-previews/writing-board-ar.png'],
        'writing-board-en' => ['name' => ['ar' => 'Writing Board — إنجليزي', 'en' => 'Writing Board — English'], 'description' => ['ar' => 'التكوين الأحمر والأسود باللغة الإنجليزية مع أقسام مستقلة قابلة للتحرير', 'en' => 'English red/charcoal composition with independently editable sections'], 'color' => '#d92d36', 'locale' => 'en', 'native_layout' => 'writing-board', 'preview_image' => '/media/funnel-previews/writing-board-en.png'],
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
        $section = fn ($label, $settings) => ['label' => $label, 'category' => 'Writing Board', 'settings' => $settings];

        return [
            'writingboard-hero' => $section('الغلاف والتنقل / Cover & navigation', [$heading, $image, $alt, $cta, $field('announcement', 'شريط الإعلان / Announcement'), $field('expires_at', 'انتهاء العرض مع المنطقة الزمنية / Offer expiry with timezone')]),
            'writingboard-intro' => $section('المقدمة الكبيرة / Large introduction', [$body]),
            'writingboard-purchase' => ['max_instances' => 1] + $section('المنتج ونموذج الطلب / Product & checkout', [$heading, $body, $cta]),
            'writingboard-story' => $section('تفاصيل وصورة واسعة / Details & wide photograph', [$heading, $body, $image, $alt]),
            'writingboard-marquee' => $section('الشريط المتحرك / Moving strip', [$list('items', 'العبارات / Phrases', [$field('label', 'العبارة / Phrase')], 8)]),
            'writingboard-features' => $section('شبكة صور التفاصيل / Detail image grid', [$heading, $body, $list('items', 'صور التفاصيل / Detail photographs', [$image, $alt], 4)]),
            'writingboard-heritage' => $section('قسم الصورة على الأحمر / Photograph on red', [$heading, $body, $image, $alt]),
            'writingboard-statement' => $section('البيان والصورة / Statement & photograph', [$heading, $body, $image, $alt]),
            'writingboard-reviews' => $section('تقييمات المنتج الفعلية / Actual product reviews', [$heading, $field('subheading', 'العنوان الثاني / Subheading')]),
            'writingboard-faq' => $section('الأسئلة الشائعة / Frequently asked questions', [$heading, $list('items', 'الأسئلة / Questions', [$field('question', 'السؤال / Question'), $field('answer', 'الإجابة / Answer', 'textarea')], 12)]),
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
        $row = fn ($type, $settings, $visible = true) => ['type' => 'writingboard-'.$type, 'settings' => $settings, 'is_visible' => $visible];
        $faqs = $copy['faqs'] ?? [
            ['question' => $tr('كيف أختار المنتج؟', 'How do I choose the product?'), 'answer' => $tr('راجع الوصف والصور والخيارات المتاحة في نموذج الطلب.', 'Review the description, photographs and options in the order form.')],
            ['question' => $tr('هل توجد خيارات مختلفة؟', 'Are different options available?'), 'answer' => $tr('يعرض نموذج الطلب الخيارات التي أتاحها المتجر لهذا المنتج.', 'The order form displays the options the store offers for this product.')],
            ['question' => $tr('كيف أعرف تكلفة التوصيل؟', 'How do I check delivery charges?'), 'answer' => $tr('تظهر التكلفة المتاحة لعنوانك قبل تأكيد الطلب.', 'Available delivery charges are shown for your address before confirmation.')],
            ['question' => $tr('ما وسائل الدفع المتاحة؟', 'Which payment methods are available?'), 'answer' => $tr('تظهر الوسائل التي فعّلها المتجر في نموذج الطلب.', 'Methods enabled by the store appear in the order form.')],
            ['question' => $tr('أين أجد سياسة الاسترجاع؟', 'Where can I find the return policy?'), 'answer' => $tr('راجع روابط سياسات المتجر أو تواصل معه قبل إتمام الطلب.', 'Review the store policy links or contact the store before placing an order.')],
            ['question' => $tr('كيف أتواصل مع المتجر؟', 'How do I contact the store?'), 'answer' => $tr('تجد وسائل التواصل التي أتاحها المتجر في أسفل الصفحة.', 'Contact methods provided by the store appear at the bottom of the page.')],
        ];

        return [
            $row('hero', ['heading' => strip_tags($copy['heading'] ?? $name), 'image' => $image, 'image_alt' => $name, 'cta_label' => $cta, 'announcement' => '', 'expires_at' => '']),
            $row('intro', ['text' => Str::limit(strip_tags($copy['text'] ?? $short), 350)]),
            $row('purchase', ['heading' => $name, 'text' => '', 'cta_label' => $cta]),
            $row('story', ['heading' => strip_tags($copy['details_heading'] ?? $tr('تفاصيل تستحق الاهتمام', 'Details worth discovering')), 'text' => $copy ? implode("\n\n", array_map('strip_tags', $copy['paragraphs'] ?? [$description])) : $description, 'image' => $image, 'image_alt' => $name]),
            $row('marquee', ['items' => []], false),
            $row('features', ['heading' => $tr('نظرة أقرب', 'A closer look'), 'text' => $short, 'items' => $image !== '' ? array_fill(0, 4, ['image' => $image, 'image_alt' => $name]) : []]),
            $row('heritage', ['heading' => $tr('التصميم والتفاصيل', 'Design & details'), 'text' => $short, 'image' => $image, 'image_alt' => $name]),
            $row('statement', ['heading' => $name, 'text' => $description, 'image' => $image, 'image_alt' => $name]),
            $row('marquee', ['items' => []], false),
            $row('reviews', ['heading' => $tr('آراء العملاء', 'What customers say'), 'subheading' => '']),
            $row('faq', ['heading' => strip_tags($copy['faq_heading'] ?? $tr('الأسئلة الشائعة وإجاباتها', 'Frequently asked questions')), 'items' => array_map(fn ($faq) => ['question' => strip_tags($faq['question']), 'answer' => strip_tags($faq['answer'])], $faqs)]),
        ];
    }
}
