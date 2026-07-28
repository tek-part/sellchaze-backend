<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\StoreContentPage;
use App\Support\StoreContent\ContentPageSchema;
use Illuminate\Database\Seeder;

/**
 * Seeds sensible default content for the fixed storefront system pages
 * (about/contact/faq/shipping/returns/blog) for every store, so each store's
 * dashboard shows pre-filled fields and the storefront renders real content.
 * Idempotent: only creates a row when the (store, key) pair doesn't exist yet —
 * it never overwrites content a merchant has customised.
 */
class StoreContentPagesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Store::query()->get() as $store) {
            foreach (ContentPageSchema::keys() as $key) {
                StoreContentPage::query()->firstOrCreate(
                    ['store_id' => $store->id, 'key' => $key],
                    ['data' => $this->defaults($key, $store->name), 'is_published' => true],
                );
            }
        }
    }

    /** @return array{en: array<string,mixed>, ar: array<string,mixed>} */
    private function defaults(string $key, string $storeName): array
    {
        return match ($key) {
            'about' => [
                'ar' => [
                    'heading' => 'من نحن',
                    'subheading' => "قصة {$storeName} ورؤيتنا",
                    'hero_image' => '',
                    'mission' => 'نصنع منتجات أقل عددًا وأعلى جودة، ونكون صادقين مع عملائنا حول ما يشترونه وممّ صُنع وكم يدوم.',
                    'vision' => 'سوق تُنشر فيه المتانة كمواصفة، ويُعامَل فيه الإرجاع كمعلومة لا كإزعاج.',
                    'story' => [
                        "بدأنا {$storeName} بفكرة بسيطة: أن نقدّم منتجات نفخر بها ونقف خلفها بعد البيع.",
                        'نعمل مع مورّدين نعرفهم بالاسم، ونصمّم لأجل الاستدامة لا الموسم.',
                    ],
                    'values' => [
                        ['title' => 'قُل الحقيقة', 'body' => 'مكوّنات كاملة وبلد الصنع وطريقة العناية على كل منتج.'],
                        ['title' => 'اصنع ليدوم', 'body' => 'المتانة وقابلية الإصلاح قيود تصميم لا رفاهية.'],
                        ['title' => 'أجب على الهاتف', 'body' => 'خدمة العملاء أكبر فريق لدينا ومتاحة برقم حقيقي.'],
                    ],
                    'stats' => [
                        ['value' => '2014', 'label' => 'سنة التأسيس'],
                        ['value' => '+40', 'label' => 'سوقًا نخدمه'],
                        ['value' => '4.8/5', 'label' => 'متوسط التقييم'],
                    ],
                    'founded' => '2014',
                    'story_image' => '',
                    'craft_image' => '',
                    'craft_title' => 'ما نلتزم به ولا ندّعيه',
                    'craft_body' => [
                        'كل صفحة منتج تحمل تركيب المواد الكامل وبلد الصنع وطريقة العناية.',
                        'خدمة الإصلاح لدينا تعاملت مع آلاف القطع، ونحتفظ بقطع غيار للمنتجات المتوقّفة.',
                    ],
                    'milestones' => [
                        ['year' => '2014', 'title' => 'التأسيس', 'body' => 'بدأنا بعدد محدود من المنتجات والتزام بالجودة والشفافية.'],
                        ['year' => '2020', 'title' => 'خدمة الإصلاح', 'body' => 'أطلقنا خدمة إصلاح المنتجات بدل استبدالها.'],
                        ['year' => '2024', 'title' => 'عشر سنوات', 'body' => 'وصلنا إلى أسواق جديدة مع الحفاظ على مبادئنا.'],
                    ],
                    'leadership' => [
                        ['name' => 'إيلينا مارتشيتي', 'role' => 'المؤسِّسة والمديرة الإبداعية', 'bio' => 'خبرة عقد في الإنتاج قبل تأسيس الشركة.', 'photo' => ''],
                        ['name' => 'برية رامان', 'role' => 'مديرة خدمة العملاء', 'bio' => 'بنَت عملية تحليل المرتجعات التي تغذّي مراجعات التصميم.', 'photo' => ''],
                    ],
                    'awards' => [
                        ['year' => '2025', 'title' => 'مؤشر الشفافية — أفضل 10', 'body' => 'تقديرًا لنشر تركيب المواد بالكامل عبر الكتالوج.'],
                        ['year' => '2024', 'title' => 'أفضل خدمة عملاء', 'body' => 'عن سرعة الاستجابة وحل المشكلات من أول تواصل.'],
                    ],
                    'partners' => ['شهادة الجودة', 'اعتماد بيئي', 'قطن أفضل', 'محايد كربونيًا'],
                    'testimonials' => [
                        ['quote' => 'جودة ممتازة وخدمة عملاء تردّ بسرعة. تجربة شراء مريحة.', 'author' => 'سارة م.', 'detail' => 'عميلة منذ 2022'],
                        ['quote' => 'منتجات تدوم طويلًا وسياسة إرجاع واضحة. أنصح بها.', 'author' => 'أحمد ك.', 'detail' => 'عميل دائم'],
                    ],
                    'story_eyebrow' => 'قصتنا', 'story_heading' => 'كيف وصلنا إلى هنا',
                    'values_eyebrow' => 'ما نلتزم به', 'values_heading' => 'قيمنا',
                    'milestones_eyebrow' => 'المحطات', 'milestones_heading' => 'رحلتنا بالترتيب',
                    'leadership_eyebrow' => 'الفريق', 'leadership_heading' => 'من يدير المكان',
                    'craft_eyebrow' => 'الجودة والاستدامة',
                    'awards_eyebrow' => 'التقدير', 'awards_heading' => 'الجوائز والاعتمادات',
                    'testimonials_eyebrow' => 'بكلماتهم', 'testimonials_heading' => 'ماذا يقول عملاؤنا',
                    'partners_eyebrow' => 'المعايير التي نلتزم بها', 'partners_heading' => 'الشركاء والاعتمادات',
                    'cta_title' => 'عندك أي سؤال؟', 'cta_text' => 'خدمة العملاء أكبر فريق لدينا ومتاحة بالهاتف لا بالنموذج فقط.',
                ],
                'en' => [
                    'heading' => 'About us',
                    'subheading' => "The story and vision of {$storeName}",
                    'hero_image' => '',
                    'mission' => 'We make fewer, better things — and are honest with customers about what they buy, what it is made of and how long it lasts.',
                    'vision' => 'A market where durability is a published spec and returns are treated as information, not a nuisance.',
                    'story' => [
                        "{$storeName} started with a simple idea: sell things we are proud of and stand behind after the sale.",
                        'We work with suppliers we know by name and design for longevity, not the season.',
                    ],
                    'values' => [
                        ['title' => 'Say what it is', 'body' => 'Full composition, country of manufacture and care on every product.'],
                        ['title' => 'Build to last', 'body' => 'Durability and repairability are design constraints, not extras.'],
                        ['title' => 'Answer the phone', 'body' => 'Customer care is our largest team, reachable by a real number.'],
                    ],
                    'stats' => [
                        ['value' => '2014', 'label' => 'Founded'],
                        ['value' => '40+', 'label' => 'Markets served'],
                        ['value' => '4.8/5', 'label' => 'Average rating'],
                    ],
                    'founded' => '2014',
                    'story_image' => '',
                    'craft_image' => '',
                    'craft_title' => 'What we will and will not claim',
                    'craft_body' => [
                        'Every product page carries full composition, the country of manufacture and care instructions.',
                        'Our repair service has handled thousands of pieces, and we hold spare parts for discontinued lines.',
                    ],
                    'milestones' => [
                        ['year' => '2014', 'title' => 'Founded', 'body' => 'Started with a handful of products and a commitment to quality and transparency.'],
                        ['year' => '2020', 'title' => 'Repair service', 'body' => 'Launched a repair service instead of replacements.'],
                        ['year' => '2024', 'title' => 'Ten years', 'body' => 'Reached new markets while keeping our principles.'],
                    ],
                    'leadership' => [
                        ['name' => 'Elena Marchetti', 'role' => 'Founder & Creative Director', 'bio' => 'A decade in production before founding the company.', 'photo' => ''],
                        ['name' => 'Priya Raman', 'role' => 'Director of Customer Care', 'bio' => 'Built the returns-analysis process feeding design reviews.', 'photo' => ''],
                    ],
                    'awards' => [
                        ['year' => '2025', 'title' => 'Transparency Index — Top 10', 'body' => 'For publishing full material composition across the catalogue.'],
                        ['year' => '2024', 'title' => 'Best Customer Service', 'body' => 'For response time and first-contact resolution.'],
                    ],
                    'partners' => ['Quality Certified', 'Eco Accredited', 'Better Cotton', 'Climate Neutral'],
                    'testimonials' => [
                        ['quote' => 'Excellent quality and fast customer service. A smooth buying experience.', 'author' => 'Sarah M.', 'detail' => 'Customer since 2022'],
                        ['quote' => 'Products that last and a clear returns policy. Highly recommend.', 'author' => 'Ahmed K.', 'detail' => 'Repeat customer'],
                    ],
                    'story_eyebrow' => 'Our story', 'story_heading' => 'How we got here',
                    'values_eyebrow' => 'What we hold to', 'values_heading' => 'Our values',
                    'milestones_eyebrow' => 'Milestones', 'milestones_heading' => 'Our journey, in order',
                    'leadership_eyebrow' => 'The team', 'leadership_heading' => 'Who runs the place',
                    'craft_eyebrow' => 'Quality & sustainability',
                    'awards_eyebrow' => 'Recognition', 'awards_heading' => 'Awards & accreditation',
                    'testimonials_eyebrow' => 'In their words', 'testimonials_heading' => 'What customers say',
                    'partners_eyebrow' => 'Standards we work to', 'partners_heading' => 'Partners & certification',
                    'cta_title' => 'Questions about anything here?', 'cta_text' => 'Customer care is our largest team, reachable by phone, not just a form.',
                ],
            ],
            'contact' => [
                'ar' => [
                    'heading' => 'اتصل بنا', 'intro' => 'فريق خدمة العملاء يردّ خلال يوم عمل واحد.',
                    'email' => 'care@example.com', 'phone' => '+20 100 000 0000',
                    'address' => 'شارع التجارة 42، القاهرة، مصر', 'hours' => 'السبت–الخميس 9:00–18:00',
                    'map_embed' => '', 'show_form' => true,
                    'notice_title' => 'مواعيد التوصيل في الإجازات',
                    'notice_body' => 'قد يتأخر التوصيل خلال العطلات الرسمية؛ خدمة العملاء متاحة عبر البريد طوال الوقت.',
                    'departments' => [
                        ['name' => 'خدمة العملاء', 'description' => 'الطلبات والتوصيل والإرجاع وكل ما يخص عملية شراء تمّت.', 'email' => 'care@example.com', 'phone' => '+20 100 000 0000', 'hours' => 'السبت–الخميس 9:00–18:00'],
                        ['name' => 'المبيعات', 'description' => 'استشارات المقاسات والتوفّر والمواعيد الخاصة.', 'email' => 'sales@example.com', 'phone' => '+20 100 000 0001', 'hours' => 'السبت–الخميس 10:00–18:00'],
                    ],
                    'locations' => [
                        ['name' => 'الفرع الرئيسي', 'address' => 'شارع التجارة 42', 'city' => 'القاهرة', 'country' => 'مصر', 'phone' => '+20 100 000 0000', 'hours' => 'السبت–الخميس 9:00–18:00', 'lat' => '30.0444', 'lon' => '31.2357'],
                    ],
                    'hero_eyebrow' => 'نردّ خلال يوم عمل واحد',
                    'departments_eyebrow' => 'الأقسام', 'departments_heading' => 'بمن تتواصل',
                    'form_heading' => 'أرسل رسالة', 'locations_heading' => 'فروعنا',
                    'faq_eyebrow' => 'قبل أن تكتب', 'faq_heading' => 'الأسئلة الشائعة',
                ],
                'en' => [
                    'heading' => 'Contact us', 'intro' => 'Our customer care team replies within one business day.',
                    'email' => 'care@example.com', 'phone' => '+20 100 000 0000',
                    'address' => '42 Commerce St, Cairo, Egypt', 'hours' => 'Sat–Thu 9:00–18:00',
                    'map_embed' => '', 'show_form' => true,
                    'notice_title' => 'Delivery over the holidays',
                    'notice_body' => 'Delivery may be delayed during public holidays; customer care is reachable by email throughout.',
                    'departments' => [
                        ['name' => 'Customer care', 'description' => 'Orders, delivery, returns and anything about a purchase you have made.', 'email' => 'care@example.com', 'phone' => '+20 100 000 0000', 'hours' => 'Sat–Thu 9:00–18:00'],
                        ['name' => 'Sales', 'description' => 'Sizing advice, stock checks and appointments.', 'email' => 'sales@example.com', 'phone' => '+20 100 000 0001', 'hours' => 'Sat–Thu 10:00–18:00'],
                    ],
                    'locations' => [
                        ['name' => 'Main branch', 'address' => '42 Commerce St', 'city' => 'Cairo', 'country' => 'Egypt', 'phone' => '+20 100 000 0000', 'hours' => 'Sat–Thu 9:00–18:00', 'lat' => '30.0444', 'lon' => '31.2357'],
                    ],
                    'hero_eyebrow' => 'We reply within one business day',
                    'departments_eyebrow' => 'Departments', 'departments_heading' => 'Who to contact',
                    'form_heading' => 'Send a message', 'locations_heading' => 'Our branches',
                    'faq_eyebrow' => 'Before you write', 'faq_heading' => 'Frequently asked',
                ],
            ],
            'faq' => [
                'ar' => ['heading' => 'الأسئلة الشائعة', 'items' => [
                    ['question' => 'إلى أين تشحنون؟', 'answer' => 'نشحن إلى معظم المحافظات، وتظهر التكلفة والمدة عند إتمام الطلب.'],
                    ['question' => 'ما سياسة الإرجاع؟', 'answer' => 'يمكن إرجاع أي منتج غير مستعمل خلال 14 يومًا لاسترداد كامل.'],
                    ['question' => 'كيف أتابع طلبي؟', 'answer' => 'ستصلك رسالة بها رابط التتبّع بمجرد شحن الطلب.'],
                ]],
                'en' => ['heading' => 'Frequently asked', 'items' => [
                    ['question' => 'Where do you ship?', 'answer' => 'We ship to most regions; cost and time are shown at checkout.'],
                    ['question' => 'What is your returns policy?', 'answer' => 'Return any unused item within 14 days for a full refund.'],
                    ['question' => 'How do I track my order?', 'answer' => 'You will get a message with a tracking link once your order ships.'],
                ]],
            ],
            'shipping' => [
                'ar' => ['title' => 'سياسة الشحن', 'summary' => 'أين نوصّل، وكم يكلّف، وكم يستغرق.', 'sections' => [
                    ['heading' => 'مدة التجهيز', 'body' => ['يتم تجهيز الطلبات وشحنها خلال 1–2 يوم عمل.'], 'list' => []],
                    ['heading' => 'خيارات وتكلفة التوصيل', 'body' => ['تظهر خيارات التوصيل وتكلفتها عند إتمام الطلب.'], 'list' => ['قياسي: 3–7 أيام', 'سريع: 1–2 يوم']],
                    ['heading' => 'تتبّع الطلب', 'body' => ['ستصلك رسالة شحن بها بيانات التتبّع فور مغادرة الطرد.'], 'list' => []],
                ]],
                'en' => ['title' => 'Shipping policy', 'summary' => 'Where we deliver, what it costs and how long it takes.', 'sections' => [
                    ['heading' => 'Processing time', 'body' => ['Orders are dispatched within 1–2 business days.'], 'list' => []],
                    ['heading' => 'Options & cost', 'body' => ['Delivery options and cost are shown at checkout.'], 'list' => ['Standard: 3–7 days', 'Express: 1–2 days']],
                    ['heading' => 'Tracking', 'body' => ['You will get a dispatch email with tracking once the parcel leaves us.'], 'list' => []],
                ]],
            ],
            'returns' => [
                'ar' => ['title' => 'سياسة الإرجاع', 'summary' => 'كيف ترجع منتجًا وتسترد قيمته.', 'sections' => [
                    ['heading' => 'مدة الإرجاع', 'body' => ['يمكنك إرجاع أي منتج غير مستعمل خلال 14 يومًا من الاستلام.'], 'list' => []],
                    ['heading' => 'كيفية الإرجاع', 'body' => ['تواصل معنا برقم الطلب وسنرسل لك التعليمات.'], 'list' => ['احتفظ بالتغليف الأصلي']],
                    ['heading' => 'استرداد المبلغ', 'body' => ['يُعاد المبلغ إلى وسيلة الدفع نفسها خلال 5–10 أيام عمل.'], 'list' => []],
                ]],
                'en' => ['title' => 'Returns policy', 'summary' => 'How to return an item and get a refund.', 'sections' => [
                    ['heading' => 'Return window', 'body' => ['Return any unused item in original condition within 14 days.'], 'list' => []],
                    ['heading' => 'How to return', 'body' => ['Contact us with your order number and we will send instructions.'], 'list' => ['Keep the original packaging']],
                    ['heading' => 'Refunds', 'body' => ['Refunds go back to the original payment method within 5–10 business days.'], 'list' => []],
                ]],
            ],
            'blog' => [
                'ar' => ['heading' => 'المدوّنة', 'intro' => 'قصص ونصائح من فريقنا.', 'posts' => [
                    ['title' => 'كيف تختار المقاس المناسب', 'slug' => 'choosing-size', 'excerpt' => 'دليل سريع لاختيار مقاسك بثقة.', 'image' => '', 'date' => '2026-07-01', 'body' => 'محتوى المقال هنا…'],
                    ['title' => 'العناية بمنتجاتك', 'slug' => 'product-care', 'excerpt' => 'نصائح للحفاظ على منتجاتك أطول فترة.', 'image' => '', 'date' => '2026-06-15', 'body' => 'محتوى المقال هنا…'],
                ]],
                'en' => ['heading' => 'Journal', 'intro' => 'Stories and tips from our team.', 'posts' => [
                    ['title' => 'Choosing the right size', 'slug' => 'choosing-size', 'excerpt' => 'A quick guide to picking your size with confidence.', 'image' => '', 'date' => '2026-07-01', 'body' => 'Article body here…'],
                    ['title' => 'Caring for your products', 'slug' => 'product-care', 'excerpt' => 'Tips to keep your products looking their best.', 'image' => '', 'date' => '2026-06-15', 'body' => 'Article body here…'],
                ]],
            ],
            'home' => [
                'ar' => [
                    'hero_eyebrow' => 'مجموعة جديدة',
                    'hero_heading' => "تسوّق أفضل المنتجات من {$storeName}",
                    'hero_subheading' => 'منتجات مختارة بعناية، أسعار عادلة، وشحن سريع لكل مكان.',
                    'hero_cta_label' => 'تسوّق الآن',
                    'hero_cta_url' => '/shop',
                    'hero_cta2_label' => 'استكشف التصنيفات',
                    'hero_cta2_url' => '/categories',
                    'hero_image' => '',
                    'slides' => [
                        ['eyebrow' => 'الأكثر مبيعًا', 'heading' => 'نجوم هذا الموسم', 'subheading' => 'أكثر ما يحبّه عملاؤنا', 'cta_label' => 'اكتشف', 'cta_url' => '/collections/best-sellers', 'image' => ''],
                        ['eyebrow' => 'عروض', 'heading' => 'خصومات تصل إلى ٢٥٪', 'subheading' => 'لفترة محدودة', 'cta_label' => 'اطلب الآن', 'cta_url' => '/collections/trending', 'image' => ''],
                    ],
                    'why_choose_us' => [
                        ['title' => 'شحن سريع', 'text' => 'توصيل خلال ٢–٥ أيام عمل لكل المدن.'],
                        ['title' => 'إرجاع سهل', 'text' => 'استرجاع خلال ١٤ يومًا بلا تعقيد.'],
                        ['title' => 'دفع آمن', 'text' => 'وسائل دفع موثوقة ومحمية.'],
                        ['title' => 'دعم متواصل', 'text' => 'فريقنا جاهز لمساعدتك دائمًا.'],
                    ],
                    'editorial_eyebrow' => 'قصتنا',
                    'editorial_heading' => 'صُنع بعناية، من أجلك',
                    'editorial_body' => 'نختار كل منتج بعناية ونقف خلفه بعد البيع.',
                    'editorial_cta_label' => 'من نحن',
                    'editorial_cta_url' => '/about',
                    'editorial_image' => '',
                    'ugc_handle' => '@'.\Illuminate\Support\Str::slug($storeName),
                    'ugc' => [],
                    'newsletter_title' => 'اشترك في نشرتنا',
                    'newsletter_text' => 'كن أول من يعرف عن المنتجات والعروض الجديدة.',
                    'newsletter_cta' => 'اشترك',
                    'collections_heading' => 'تسوّق حسب المجموعة',
                    'brands_heading' => 'أشهر البراندات',
                    'why_heading' => 'لماذا تتسوّق معنا',
                    'ugc_heading' => 'من إبداع عملائنا',
                ],
                'en' => [
                    'hero_eyebrow' => 'New collection',
                    'hero_heading' => "Shop the best from {$storeName}",
                    'hero_subheading' => 'Carefully curated products, fair prices, and fast shipping everywhere.',
                    'hero_cta_label' => 'Shop now',
                    'hero_cta_url' => '/shop',
                    'hero_cta2_label' => 'Browse categories',
                    'hero_cta2_url' => '/categories',
                    'hero_image' => '',
                    'slides' => [
                        ['eyebrow' => 'Best sellers', 'heading' => 'This season\'s stars', 'subheading' => 'What our customers love most', 'cta_label' => 'Discover', 'cta_url' => '/collections/best-sellers', 'image' => ''],
                        ['eyebrow' => 'Offers', 'heading' => 'Up to 25% off', 'subheading' => 'For a limited time', 'cta_label' => 'Shop the sale', 'cta_url' => '/collections/trending', 'image' => ''],
                    ],
                    'why_choose_us' => [
                        ['title' => 'Fast shipping', 'text' => 'Delivered in 2–5 business days nationwide.'],
                        ['title' => 'Easy returns', 'text' => '14-day hassle-free returns.'],
                        ['title' => 'Secure payment', 'text' => 'Trusted, protected payment methods.'],
                        ['title' => 'Always here', 'text' => 'Our team is ready to help anytime.'],
                    ],
                    'editorial_eyebrow' => 'Our story',
                    'editorial_heading' => 'Made with care, for you',
                    'editorial_body' => 'We choose every product carefully and stand behind it after the sale.',
                    'editorial_cta_label' => 'About us',
                    'editorial_cta_url' => '/about',
                    'editorial_image' => '',
                    'ugc_handle' => '@'.\Illuminate\Support\Str::slug($storeName),
                    'ugc' => [],
                    'newsletter_title' => 'Join our newsletter',
                    'newsletter_text' => 'Be the first to know about new products and offers.',
                    'newsletter_cta' => 'Subscribe',
                    'collections_heading' => 'Shop by collection',
                    'brands_heading' => 'Featured brands',
                    'why_heading' => 'Why shop with us',
                    'ugc_heading' => 'From our community',
                ],
            ],
            default => ['en' => [], 'ar' => []],
        };
    }
}
