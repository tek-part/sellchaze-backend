<?php

namespace Database\Seeders;

use App\Models\Sector;
use Illuminate\Database\Seeder;

/**
 * Seeds the fixed 8-sector directory taxonomy (depth 0) plus their specialties (depth 1).
 * Idempotent: keyed by slug via updateOrCreate, so re-running only refreshes labels/SEO copy and
 * never duplicates. Top-level sectors carry a bilingual 100–150 word SEO intro; specialties inherit
 * their sector's framing and can have their own intro filled later from the admin.
 */
class SectorsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->tree() as $pos => $sector) {
            $parent = Sector::updateOrCreate(
                ['slug' => $sector['slug']],
                [
                    'parent_id' => null,
                    'name_en' => $sector['name_en'],
                    'name_ar' => $sector['name_ar'],
                    'icon' => $sector['icon'],
                    'depth' => 0,
                    'position' => $pos,
                    'is_active' => true,
                    'seo_title_en' => $sector['name_en'].' Manufacturers & Suppliers in Egypt',
                    'seo_title_ar' => 'مصانع وموردو '.$sector['name_ar'].' في مصر',
                    'seo_description_en' => $sector['seo_en'] ?? null,
                    'seo_description_ar' => $sector['seo_ar'] ?? null,
                    'intro_en' => $sector['intro_en'] ?? null,
                    'intro_ar' => $sector['intro_ar'] ?? null,
                ],
            );

            foreach (($sector['children'] ?? []) as $cpos => $child) {
                Sector::updateOrCreate(
                    ['slug' => $child['slug']],
                    [
                        'parent_id' => $parent->id,
                        'name_en' => $child['name_en'],
                        'name_ar' => $child['name_ar'],
                        'icon' => null,
                        'depth' => 1,
                        'position' => $cpos,
                        'is_active' => true,
                        'seo_title_en' => $child['name_en'].' Suppliers in Egypt',
                        'seo_title_ar' => 'موردو '.$child['name_ar'].' في مصر',
                    ],
                );
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tree(): array
    {
        return [
            [
                'slug' => 'textiles-and-fashion', 'name_en' => 'Textiles & Fashion', 'name_ar' => 'الملابس والنسيج', 'icon' => '🧵',
                'intro_en' => 'Egypt is one of the world\'s oldest textile hubs, and its factories still supply everything from raw cotton yarn to finished ready-to-wear. This directory brings together verified clothing manufacturers, fabric and thread mills, home-furnishing and curtain makers, traditional-wear ateliers, sportswear producers and uniform suppliers in one place. Whether you are a retailer sourcing a seasonal collection, a wholesaler after bulk fabric, or a business ordering branded uniforms, you can browse suppliers by specialty, compare their catalogues and reach them directly — no intermediaries.',
                'intro_ar' => 'مصر واحدة من أعرق مراكز النسيج في العالم، وما زالت مصانعها تورّد كل شيء من خيوط القطن الخام إلى الملابس الجاهزة. يجمع هذا الدليل مصنّعي الملابس الموثوقين، ومصانع الأقمشة والخيوط، وصنّاع المفروشات والستائر، وورش الأزياء التقليدية، ومنتجي الملابس الرياضية، وموردي الزي الموحّد في مكان واحد. سواء كنت تاجر تجزئة تبحث عن تشكيلة موسمية، أو تاجر جملة يريد أقمشة بالكميات، أو شركة تطلب زيًّا موحّدًا بعلامتها، يمكنك تصفّح الموردين حسب التخصص ومقارنة كتالوجاتهم والتواصل معهم مباشرة دون وسطاء.',
                'children' => [
                    ['slug' => 'ready-made-clothing', 'name_en' => 'Ready-made Clothing', 'name_ar' => 'ملابس جاهزة'],
                    ['slug' => 'fabrics-and-yarn', 'name_en' => 'Fabrics & Yarn', 'name_ar' => 'أقمشة وخيوط'],
                    ['slug' => 'furnishings-and-curtains', 'name_en' => 'Furnishings & Curtains', 'name_ar' => 'مفروشات وستائر'],
                    ['slug' => 'traditional-wear', 'name_en' => 'Traditional Wear', 'name_ar' => 'أزياء تقليدية'],
                    ['slug' => 'wigs-and-hair', 'name_en' => 'Wigs & Hair', 'name_ar' => 'باروكات وشعر'],
                    ['slug' => 'sportswear', 'name_en' => 'Sportswear', 'name_ar' => 'ملابس رياضية'],
                    ['slug' => 'uniforms', 'name_en' => 'Uniforms', 'name_ar' => 'زي موحد'],
                    ['slug' => 'textiles-other', 'name_en' => 'Other', 'name_ar' => 'أخرى'],
                ],
            ],
            [
                'slug' => 'building-materials', 'name_en' => 'Building Materials & Finishes', 'name_ar' => 'مواد البناء والتشطيبات', 'icon' => '🏗️',
                'intro_en' => 'From foundations to finishing touches, Egypt\'s construction supply chain is dense and competitive. This section lists manufacturers and suppliers of ceramics and tiles, iron and aluminium, paints and insulation, cement and bricks, and doors and windows. Contractors, developers and hardware retailers use it to source materials at factory prices, compare specifications, and build direct relationships with producers. Browse by specialty to shortlist suppliers, then reach out for quotes and bulk pricing on the exact products your project needs.',
                'intro_ar' => 'من الأساسات إلى لمسات التشطيب النهائية، سلسلة توريد البناء في مصر كثيفة وتنافسية. يضم هذا القسم مصنّعي وموردي السيراميك والبلاط، والحديد والألومنيوم، والدهانات والعوازل، والأسمنت والطوب، والأبواب والنوافذ. يستخدمه المقاولون والمطوّرون وتجار الأدوات لتوريد المواد بأسعار المصنع، ومقارنة المواصفات، وبناء علاقات مباشرة مع المنتجين. تصفّح حسب التخصص لاختيار الموردين، ثم تواصل معهم للحصول على عروض أسعار وأسعار الجملة للمنتجات التي يحتاجها مشروعك بالضبط.',
                'children' => [
                    ['slug' => 'ceramics-and-tiles', 'name_en' => 'Ceramics & Tiles', 'name_ar' => 'سيراميك وبلاط'],
                    ['slug' => 'iron-and-aluminum', 'name_en' => 'Iron & Aluminium', 'name_ar' => 'حديد وألومنيوم'],
                    ['slug' => 'paints-and-insulation', 'name_en' => 'Paints & Insulation', 'name_ar' => 'دهانات وعوازل'],
                    ['slug' => 'cement-and-bricks', 'name_en' => 'Cement & Bricks', 'name_ar' => 'أسمنت وطوب'],
                    ['slug' => 'doors-and-windows', 'name_en' => 'Doors & Windows', 'name_ar' => 'أبواب ونوافذ'],
                    ['slug' => 'building-other', 'name_en' => 'Other', 'name_ar' => 'أخرى'],
                ],
            ],
            [
                'slug' => 'food-and-beverages', 'name_en' => 'Food & Beverages', 'name_ar' => 'الغذاء والمشروبات', 'icon' => '🥫',
                'intro_en' => 'Egypt\'s food industry spans dry goods, beverages, dairy, sweets and bakery, and oils and spices — with a manufacturing base that serves both the local market and export. This directory helps retailers, distributors, restaurants and exporters find reliable food and beverage producers, verify them, and compare product ranges. Source packaged staples, private-label lines, or specialty ingredients directly from the factory, and open a direct channel for recurring supply.',
                'intro_ar' => 'تمتد صناعة الغذاء في مصر عبر المواد الجافة والمشروبات ومنتجات الألبان والحلويات والمخبوزات والزيوت والتوابل، بقاعدة تصنيعية تخدم السوق المحلي والتصدير معًا. يساعد هذا الدليل تجار التجزئة والموزّعين والمطاعم والمصدّرين على إيجاد منتجي أغذية ومشروبات موثوقين، والتحقق منهم، ومقارنة تشكيلات المنتجات. وفّر السلع المعبّأة أو خطوط العلامات الخاصة أو المكوّنات المتخصصة مباشرة من المصنع، وافتح قناة توريد مباشرة ومتكررة.',
                'children' => [
                    ['slug' => 'dry-foods', 'name_en' => 'Dry Foods', 'name_ar' => 'مواد غذائية جافة'],
                    ['slug' => 'beverages', 'name_en' => 'Beverages', 'name_ar' => 'مشروبات'],
                    ['slug' => 'dairy', 'name_en' => 'Dairy Products', 'name_ar' => 'منتجات ألبان'],
                    ['slug' => 'sweets-and-bakery', 'name_en' => 'Sweets & Bakery', 'name_ar' => 'حلويات ومخبوزات'],
                    ['slug' => 'oils-and-spices', 'name_en' => 'Oils & Spices', 'name_ar' => 'زيوت وتوابل'],
                    ['slug' => 'food-other', 'name_en' => 'Other', 'name_ar' => 'أخرى'],
                ],
            ],
            [
                'slug' => 'chemicals-and-detergents', 'name_en' => 'Chemicals & Detergents', 'name_ar' => 'الكيماويات والمنظفات', 'icon' => '🧴',
                'intro_en' => 'The chemicals sector covers cleaning and sanitising products, cosmetics, industrial chemicals, and paints and coatings. Egyptian manufacturers supply finished consumer goods as well as raw and intermediate chemicals for other industries. Buyers use this directory to find producers by specialty, confirm they can meet volume and specification requirements, and negotiate supply directly — from private-label detergents to bulk industrial inputs.',
                'intro_ar' => 'يغطي قطاع الكيماويات منتجات التنظيف والتطهير، ومستحضرات التجميل، والمواد الكيماوية الصناعية، والدهانات والطلاءات. يورّد المصنّعون المصريون سلعًا استهلاكية جاهزة إلى جانب المواد الكيماوية الخام والوسيطة للصناعات الأخرى. يستخدم المشترون هذا الدليل لإيجاد المنتجين حسب التخصص، والتأكد من قدرتهم على تلبية متطلبات الكميات والمواصفات، والتفاوض على التوريد مباشرة — من المنظفات بعلامة خاصة إلى المدخلات الصناعية بالجملة.',
                'children' => [
                    ['slug' => 'detergents-and-sanitizers', 'name_en' => 'Detergents & Sanitizers', 'name_ar' => 'منظفات ومطهرات'],
                    ['slug' => 'cosmetics', 'name_en' => 'Cosmetics', 'name_ar' => 'مستحضرات تجميل'],
                    ['slug' => 'industrial-chemicals', 'name_en' => 'Industrial Chemicals', 'name_ar' => 'مواد كيماوية صناعية'],
                    ['slug' => 'paints-and-coatings', 'name_en' => 'Paints & Coatings', 'name_ar' => 'دهانات وطلاء'],
                    ['slug' => 'chemicals-other', 'name_en' => 'Other', 'name_ar' => 'أخرى'],
                ],
            ],
            [
                'slug' => 'packaging-and-plastics', 'name_en' => 'Packaging & Plastics', 'name_ar' => 'التغليف والبلاستيك', 'icon' => '📦',
                'intro_en' => 'Packaging is where most supply relationships begin. Egypt\'s converters produce plastic containers, cartons and paper, glass containers, and flexible bags and wraps for food, cosmetics, pharma and industry. This directory lets brands and manufacturers find packaging suppliers by type, request samples and quotes, and lock in a dependable source for recurring orders — the packaging partner behind every product on the shelf.',
                'intro_ar' => 'التغليف هو حيث تبدأ معظم علاقات التوريد. ينتج محوّلو مصر عبوات بلاستيكية، وكراتين وورقًا، وعبوات زجاجية، وأكياسًا ولفائف مرنة للأغذية ومستحضرات التجميل والأدوية والصناعة. يتيح هذا الدليل للعلامات والمصنّعين إيجاد موردي التغليف حسب النوع، وطلب العينات وعروض الأسعار، وتأمين مصدر موثوق للطلبات المتكررة — شريك التغليف وراء كل منتج على الرفّ.',
                'children' => [
                    ['slug' => 'plastic-containers', 'name_en' => 'Plastic Containers', 'name_ar' => 'عبوات بلاستيك'],
                    ['slug' => 'cartons-and-paper', 'name_en' => 'Cartons & Paper', 'name_ar' => 'كراتين وورق'],
                    ['slug' => 'glass-containers', 'name_en' => 'Glass Containers', 'name_ar' => 'زجاج وعبوات زجاجية'],
                    ['slug' => 'flexible-packaging', 'name_en' => 'Bags & Flexible Packaging', 'name_ar' => 'أكياس وتغليف مرن'],
                ],
            ],
            [
                'slug' => 'metals-and-engineering', 'name_en' => 'Metals & Engineering', 'name_ar' => 'المعادن والهندسية', 'icon' => '🔧',
                'intro_en' => 'The metals and engineering sector supplies auto parts, machinery and equipment, wrought iron, and hand tools. Egyptian workshops and factories serve manufacturers, workshops, and importers with both stock products and made-to-order fabrication. Use this directory to find engineering suppliers by specialty, assess their capabilities, and open direct lines for parts, tooling and custom metalwork at competitive prices.',
                'intro_ar' => 'يورّد قطاع المعادن والهندسة قطع غيار السيارات، والمعدات والمكينات، والحديد المشغول، والأدوات اليدوية. تخدم الورش والمصانع المصرية المصنّعين والورش والمستوردين بمنتجات جاهزة وتصنيع حسب الطلب. استخدم هذا الدليل لإيجاد موردي القطاع الهندسي حسب التخصص، وتقييم إمكاناتهم، وفتح خطوط مباشرة للقطع والعدد والأشغال المعدنية المخصصة بأسعار تنافسية.',
                'children' => [
                    ['slug' => 'auto-parts', 'name_en' => 'Auto Parts', 'name_ar' => 'قطع غيار سيارات'],
                    ['slug' => 'machinery-and-equipment', 'name_en' => 'Machinery & Equipment', 'name_ar' => 'معدات ومكينات'],
                    ['slug' => 'wrought-iron', 'name_en' => 'Wrought Iron', 'name_ar' => 'حديد مشغول'],
                    ['slug' => 'hand-tools', 'name_en' => 'Hand Tools', 'name_ar' => 'أدوات يدوية'],
                    ['slug' => 'metals-other', 'name_en' => 'Other', 'name_ar' => 'أخرى'],
                ],
            ],
            [
                'slug' => 'furniture-and-wood', 'name_en' => 'Furniture & Wood', 'name_ar' => 'الأثاث والخشب', 'icon' => '🪑',
                'intro_en' => 'Egypt has a deep tradition of woodworking, from Damietta\'s furniture workshops to modern factories. This section covers home furniture, office furniture, wooden doors and frames, and wooden décor. Retailers, interior designers, offices and developers use the directory to source furniture makers, review their ranges, and commission stock or bespoke pieces directly from the manufacturer at workshop prices.',
                'intro_ar' => 'لمصر تراث عريق في صناعة الخشب، من ورش أثاث دمياط إلى المصانع الحديثة. يغطي هذا القسم الأثاث المنزلي، والأثاث المكتبي، والأبواب والإطارات الخشبية، والديكور الخشبي. يستخدم تجار التجزئة ومصممو الديكور والمكاتب والمطوّرون الدليل لتوريد صنّاع الأثاث، ومراجعة تشكيلاتهم، وطلب قطع جاهزة أو مخصصة مباشرة من المصنّع بأسعار الورشة.',
                'children' => [
                    ['slug' => 'home-furniture', 'name_en' => 'Home Furniture', 'name_ar' => 'أثاث منزلي'],
                    ['slug' => 'office-furniture', 'name_en' => 'Office Furniture', 'name_ar' => 'أثاث مكتبي'],
                    ['slug' => 'wooden-doors-and-frames', 'name_en' => 'Wooden Doors & Frames', 'name_ar' => 'أبواب وإطارات خشب'],
                    ['slug' => 'wooden-decor', 'name_en' => 'Wooden Décor', 'name_ar' => 'ديكور خشبي'],
                ],
            ],
            [
                'slug' => 'other', 'name_en' => 'Other', 'name_ar' => 'أخرى', 'icon' => '✨',
                'intro_en' => 'Not every business fits neatly into one sector. This catch-all lists manufacturers and suppliers whose products span or fall outside the main categories — from niche industries to new and emerging ones. Browse it to discover suppliers you might otherwise miss, and reach out directly to explore what they make.',
                'intro_ar' => 'ليست كل الأنشطة تندرج بدقة تحت قطاع واحد. يضم هذا القسم الجامع المصنّعين والموردين الذين تمتد منتجاتهم عبر القطاعات الرئيسية أو تقع خارجها — من الصناعات المتخصصة إلى الناشئة والجديدة. تصفّحه لاكتشاف موردين قد تفوتك رؤيتهم، وتواصل معهم مباشرة لاستكشاف ما ينتجون.',
                'children' => [],
            ],
        ];
    }
}
