<?php

namespace Database\Seeders\DemoSupplier;

/**
 * Static catalog taxonomy + content pools for the Demo Supplier flagship store. Kept separate from the
 * seeder so the (large) reference data is easy to scan and extend. All brand names are invented (no
 * real trademarks); taxonomy + spec templates are realistic per category.
 *
 * @phpstan-type Bilingual array{en:string,ar:string}
 */
final class CatalogData
{
    /**
     * Main categories → subcategories. Each entry: en/ar name + a `kind` that drives spec/variant/
     * content generation, plus subcategories (en/ar). ~15 mains, ~90 subs.
     *
     * @return array<int,array{en:string,ar:string,kind:string,icon:string,subs:array<int,array{en:string,ar:string}>}>
     */
    public static function categories(): array
    {
        return [
            ['en' => 'Electronics', 'ar' => 'الإلكترونيات', 'kind' => 'electronics', 'icon' => 'cpu', 'subs' => [
                ['en' => 'Smartphones', 'ar' => 'الهواتف الذكية'],
                ['en' => 'Laptops', 'ar' => 'أجهزة الكمبيوتر المحمولة'],
                ['en' => 'Tablets', 'ar' => 'الأجهزة اللوحية'],
                ['en' => 'Headphones', 'ar' => 'سماعات الرأس'],
                ['en' => 'Smartwatches', 'ar' => 'الساعات الذكية'],
                ['en' => 'Cameras', 'ar' => 'الكاميرات'],
                ['en' => 'Power Banks', 'ar' => 'بنوك الطاقة'],
            ]],
            ['en' => 'Home & Kitchen', 'ar' => 'المنزل والمطبخ', 'kind' => 'home', 'icon' => 'home', 'subs' => [
                ['en' => 'Cookware', 'ar' => 'أدوات الطهي'],
                ['en' => 'Small Appliances', 'ar' => 'الأجهزة الصغيرة'],
                ['en' => 'Dinnerware', 'ar' => 'أدوات المائدة'],
                ['en' => 'Storage & Organisation', 'ar' => 'التخزين والتنظيم'],
                ['en' => 'Bedding', 'ar' => 'المفروشات'],
                ['en' => 'Home Décor', 'ar' => 'ديكور المنزل'],
            ]],
            ['en' => 'Furniture', 'ar' => 'الأثاث', 'kind' => 'furniture', 'icon' => 'sofa', 'subs' => [
                ['en' => 'Sofas', 'ar' => 'الأرائك'],
                ['en' => 'Office Chairs', 'ar' => 'كراسي المكتب'],
                ['en' => 'Desks', 'ar' => 'المكاتب'],
                ['en' => 'Bookcases', 'ar' => 'خزائن الكتب'],
                ['en' => 'Beds', 'ar' => 'الأسِرّة'],
            ]],
            ['en' => 'Beauty & Personal Care', 'ar' => 'الجمال والعناية الشخصية', 'kind' => 'beauty', 'icon' => 'sparkles', 'subs' => [
                ['en' => 'Skincare', 'ar' => 'العناية بالبشرة'],
                ['en' => 'Haircare', 'ar' => 'العناية بالشعر'],
                ['en' => 'Fragrances', 'ar' => 'العطور'],
                ['en' => 'Makeup', 'ar' => 'المكياج'],
                ['en' => 'Grooming', 'ar' => 'العناية بالمظهر'],
            ]],
            ['en' => 'Sports & Outdoors', 'ar' => 'الرياضة والهواء الطلق', 'kind' => 'sports', 'icon' => 'dumbbell', 'subs' => [
                ['en' => 'Fitness Equipment', 'ar' => 'معدات اللياقة'],
                ['en' => 'Yoga & Pilates', 'ar' => 'اليوغا والبيلاتس'],
                ['en' => 'Camping Gear', 'ar' => 'معدات التخييم'],
                ['en' => 'Cycling', 'ar' => 'ركوب الدراجات'],
                ['en' => 'Activewear', 'ar' => 'الملابس الرياضية'],
            ]],
            ['en' => 'Fashion & Apparel', 'ar' => 'الأزياء والملابس', 'kind' => 'apparel', 'icon' => 'shirt', 'subs' => [
                ['en' => "Men's Clothing", 'ar' => 'ملابس رجالية'],
                ['en' => "Women's Clothing", 'ar' => 'ملابس نسائية'],
                ['en' => 'Footwear', 'ar' => 'الأحذية'],
                ['en' => 'Bags & Luggage', 'ar' => 'الحقائب والأمتعة'],
                ['en' => 'Watches', 'ar' => 'الساعات'],
            ]],
            ['en' => 'Office & Stationery', 'ar' => 'المكتب والقرطاسية', 'kind' => 'office', 'icon' => 'pen', 'subs' => [
                ['en' => 'Notebooks & Journals', 'ar' => 'الدفاتر والمذكرات'],
                ['en' => 'Writing Instruments', 'ar' => 'أدوات الكتابة'],
                ['en' => 'Desk Accessories', 'ar' => 'إكسسوارات المكتب'],
                ['en' => 'Printers & Ink', 'ar' => 'الطابعات والحبر'],
            ]],
            ['en' => 'Tools & Hardware', 'ar' => 'الأدوات والعتاد', 'kind' => 'tools', 'icon' => 'wrench', 'subs' => [
                ['en' => 'Power Tools', 'ar' => 'الأدوات الكهربائية'],
                ['en' => 'Hand Tools', 'ar' => 'الأدوات اليدوية'],
                ['en' => 'Tool Storage', 'ar' => 'تخزين الأدوات'],
                ['en' => 'Fasteners', 'ar' => 'أدوات التثبيت'],
            ]],
            ['en' => 'Toys & Games', 'ar' => 'الألعاب', 'kind' => 'toys', 'icon' => 'blocks', 'subs' => [
                ['en' => 'Building Blocks', 'ar' => 'مكعبات البناء'],
                ['en' => 'Board Games', 'ar' => 'ألعاب الطاولة'],
                ['en' => 'Educational Toys', 'ar' => 'الألعاب التعليمية'],
                ['en' => 'Remote Control', 'ar' => 'التحكم عن بعد'],
            ]],
            ['en' => 'Pet Supplies', 'ar' => 'مستلزمات الحيوانات الأليفة', 'kind' => 'pet', 'icon' => 'paw', 'subs' => [
                ['en' => 'Dog Supplies', 'ar' => 'مستلزمات الكلاب'],
                ['en' => 'Cat Supplies', 'ar' => 'مستلزمات القطط'],
                ['en' => 'Pet Feeders', 'ar' => 'أوعية التغذية'],
                ['en' => 'Pet Beds', 'ar' => 'أسِرّة الحيوانات'],
            ]],
            ['en' => 'Garden & Outdoor', 'ar' => 'الحديقة والخارج', 'kind' => 'garden', 'icon' => 'flower', 'subs' => [
                ['en' => 'Planters & Pots', 'ar' => 'الأصص والمزارع'],
                ['en' => 'Garden Tools', 'ar' => 'أدوات الحديقة'],
                ['en' => 'Outdoor Lighting', 'ar' => 'الإضاءة الخارجية'],
                ['en' => 'Patio Furniture', 'ar' => 'أثاث الفناء'],
            ]],
            ['en' => 'Automotive', 'ar' => 'السيارات', 'kind' => 'auto', 'icon' => 'car', 'subs' => [
                ['en' => 'Car Accessories', 'ar' => 'إكسسوارات السيارات'],
                ['en' => 'Car Care', 'ar' => 'العناية بالسيارة'],
                ['en' => 'Car Electronics', 'ar' => 'إلكترونيات السيارة'],
            ]],
            ['en' => 'Health & Wellness', 'ar' => 'الصحة والعافية', 'kind' => 'health', 'icon' => 'heart', 'subs' => [
                ['en' => 'Supplements', 'ar' => 'المكملات الغذائية'],
                ['en' => 'Medical Devices', 'ar' => 'الأجهزة الطبية'],
                ['en' => 'Massage & Relaxation', 'ar' => 'التدليك والاسترخاء'],
            ]],
            ['en' => 'Baby & Kids', 'ar' => 'الأطفال والرضّع', 'kind' => 'baby', 'icon' => 'baby', 'subs' => [
                ['en' => 'Feeding', 'ar' => 'التغذية'],
                ['en' => 'Strollers', 'ar' => 'عربات الأطفال'],
                ['en' => 'Nursery', 'ar' => 'غرفة الطفل'],
                ['en' => "Kids' Clothing", 'ar' => 'ملابس الأطفال'],
            ]],
            ['en' => 'Grocery & Gourmet', 'ar' => 'البقالة والأطعمة', 'kind' => 'grocery', 'icon' => 'basket', 'subs' => [
                ['en' => 'Coffee & Tea', 'ar' => 'القهوة والشاي'],
                ['en' => 'Snacks', 'ar' => 'الوجبات الخفيفة'],
                ['en' => 'Pantry Staples', 'ar' => 'أساسيات المؤن'],
                ['en' => 'Honey & Spreads', 'ar' => 'العسل والمعجنات'],
            ]],
        ];
    }

    /** @return array<int,array{en:string,ar:string,country:string}> Invented brands (no real trademarks). */
    public static function brands(): array
    {
        return [
            ['en' => 'Nordhaven', 'ar' => 'نوردهافن', 'country' => 'Denmark'],
            ['en' => 'AeroPeak', 'ar' => 'إيروبيك', 'country' => 'Germany'],
            ['en' => 'Lumira', 'ar' => 'لوميرا', 'country' => 'France'],
            ['en' => 'Voltcore', 'ar' => 'فولتكور', 'country' => 'South Korea'],
            ['en' => 'Everloom', 'ar' => 'إيفرلوم', 'country' => 'United Kingdom'],
            ['en' => 'Cascade & Co', 'ar' => 'كاسكيد وشركاه', 'country' => 'United States'],
            ['en' => 'Tavora', 'ar' => 'تافورا', 'country' => 'Italy'],
            ['en' => 'Kestrel', 'ar' => 'كيستريل', 'country' => 'United States'],
            ['en' => 'Marisol', 'ar' => 'ماريسول', 'country' => 'Spain'],
            ['en' => 'Ironwood', 'ar' => 'آيرونوود', 'country' => 'Canada'],
            ['en' => 'Solace', 'ar' => 'سولاس', 'country' => 'Sweden'],
            ['en' => 'Pura Vida', 'ar' => 'بورا فيدا', 'country' => 'Portugal'],
            ['en' => 'Meridian', 'ar' => 'ميريديان', 'country' => 'Switzerland'],
            ['en' => 'Halcyon', 'ar' => 'هالسيون', 'country' => 'Japan'],
            ['en' => 'Brightwell', 'ar' => 'برايتويل', 'country' => 'United Kingdom'],
            ['en' => 'Copperline', 'ar' => 'كوبرلاين', 'country' => 'United States'],
            ['en' => 'Verdant', 'ar' => 'فيردانت', 'country' => 'Netherlands'],
            ['en' => 'Stratos', 'ar' => 'ستراتوس', 'country' => 'Greece'],
            ['en' => 'Amber & Oak', 'ar' => 'أمبر وأوك', 'country' => 'United States'],
            ['en' => 'Finela', 'ar' => 'فينيلا', 'country' => 'Finland'],
            ['en' => 'Zephyr', 'ar' => 'زفير', 'country' => 'Germany'],
            ['en' => 'Onyx Labs', 'ar' => 'أونكس لابز', 'country' => 'United States'],
            ['en' => 'Casa Bella', 'ar' => 'كاسا بيلا', 'country' => 'Italy'],
            ['en' => 'Trailhead', 'ar' => 'تريلهيد', 'country' => 'Canada'],
            ['en' => 'Sable', 'ar' => 'سابل', 'country' => 'France'],
            ['en' => 'Northpoint', 'ar' => 'نورثبوينت', 'country' => 'United States'],
            ['en' => 'Aurelia', 'ar' => 'أوريليا', 'country' => 'Italy'],
            ['en' => 'Kavana', 'ar' => 'كافانا', 'country' => 'United Arab Emirates'],
            ['en' => 'Basira', 'ar' => 'بصيرة', 'country' => 'Saudi Arabia'],
            ['en' => 'Rawaa', 'ar' => 'رواء', 'country' => 'Saudi Arabia'],
            ['en' => 'Mistral', 'ar' => 'ميسترال', 'country' => 'France'],
            ['en' => 'Bastion', 'ar' => 'باستيون', 'country' => 'United States'],
        ];
    }

    /** @return array<int,array{en:string,ar:string,type:string}> Merchandising collections. */
    public static function collections(): array
    {
        return [
            ['en' => 'Featured', 'ar' => 'المميزة', 'type' => 'featured'],
            ['en' => 'New Arrivals', 'ar' => 'وصل حديثاً', 'type' => 'new-arrivals'],
            ['en' => 'Best Sellers', 'ar' => 'الأكثر مبيعاً', 'type' => 'best-sellers'],
            ['en' => 'Trending Now', 'ar' => 'الرائجة الآن', 'type' => 'trending'],
            ['en' => 'Weekly Deals', 'ar' => 'عروض الأسبوع', 'type' => 'weekly-deals'],
            ['en' => 'Luxury Collection', 'ar' => 'مجموعة الفخامة', 'type' => 'luxury'],
            ['en' => 'Budget Finds', 'ar' => 'خيارات اقتصادية', 'type' => 'budget'],
            ['en' => 'Staff Picks', 'ar' => 'اختيارات الفريق', 'type' => 'staff-picks'],
            ['en' => 'Seasonal Edit', 'ar' => 'إطلالة الموسم', 'type' => 'seasonal'],
        ];
    }
}
