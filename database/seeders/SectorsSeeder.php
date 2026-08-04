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
                'intro_en' => 'Egypt is one of the world\'s oldest textile hubs, and its factories still supply everything from raw cotton yarn to finished ready-to-wear. This directory brings together verified clothing manufacturers, fabric and thread mills, home-furnishing and curtain makers, traditional-wear ateliers, sportswear producers and uniform suppliers in one place. Whether you are a retailer sourcing a seasonal collection, a wholesaler after bulk fabric, or a business ordering branded uniforms, you can browse suppliers by specialty, compare their catalogues and reach them directly — no intermediaries. Egypt\'s textile sector spans the full chain — spinning, weaving, dyeing, printing, cutting and finishing — which means many factories here can take a design from sample to shipped order without outsourcing. When you evaluate a clothing manufacturer or fabric supplier, look at minimum order quantities, fabric composition and GSM, colour-fastness, stitching quality and lead times in peak season. Each supplier profile on Sellchase shows the company\'s products, specialties, city and contact details, so you can shortlist quickly and request quotations from several factories at once. New textile and apparel manufacturers join the directory regularly, so it is worth checking back before you place a large seasonal order.',
                'intro_ar' => 'مصر واحدة من أعرق مراكز النسيج في العالم، وما زالت مصانعها تورّد كل شيء من خيوط القطن الخام إلى الملابس الجاهزة. يجمع هذا الدليل مصنّعي الملابس الموثوقين، ومصانع الأقمشة والخيوط، وصنّاع المفروشات والستائر، وورش الأزياء التقليدية، ومنتجي الملابس الرياضية، وموردي الزي الموحّد في مكان واحد. سواء كنت تاجر تجزئة تبحث عن تشكيلة موسمية، أو تاجر جملة يريد أقمشة بالكميات، أو شركة تطلب زيًّا موحّدًا بعلامتها، يمكنك تصفّح الموردين حسب التخصص ومقارنة كتالوجاتهم والتواصل معهم مباشرة دون وسطاء. يمتد قطاع النسيج المصري على السلسلة الكاملة — من الغزل والنسيج والصباغة والطباعة إلى القص والتشطيب — ما يعني أن كثيرًا من المصانع هنا يمكنها تنفيذ التصميم من العيّنة حتى الشحن دون الاستعانة بجهات خارجية. عند تقييم مصنّع ملابس أو مورد أقمشة، انظر إلى الحد الأدنى للكمية، وتركيب القماش ووزنه، وثبات الألوان، وجودة الحياكة، ومدة التنفيذ في مواسم الذروة. يعرض كل ملف مورد على سيل شيز منتجات الشركة وتخصصاتها ومدينتها وبيانات التواصل، لتختار بسرعة وترسل طلبات عروض أسعار لعدة مصانع في وقت واحد. ينضم موردون ومصنّعو ملابس جدد إلى الدليل باستمرار، لذا راجعه قبل أي طلب موسمي كبير.',
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
                'intro_en' => 'From foundations to finishing touches, Egypt\'s construction supply chain is dense and competitive. This section lists manufacturers and suppliers of ceramics and tiles, iron and aluminium, paints and insulation, cement and bricks, and doors and windows. Contractors, developers and hardware retailers use it to source materials at factory prices, compare specifications, and build direct relationships with producers. Browse by specialty to shortlist suppliers, then reach out for quotes and bulk pricing on the exact products your project needs. Projects live or die on materials arriving on spec and on time, so it pays to know your producer rather than a chain of middlemen. Ask about compressive strength and grades for cement and bricks, thickness, water absorption and slip rating for ceramics and tiles, alloy and coating for aluminium and iron, and coverage and drying times for paints and insulation. Many of the factories listed here also handle custom sizes, project quantities and staged delivery schedules for large sites. Use the specialty pages to narrow down to exactly the material you need, review each company\'s catalogue, then contact them directly for project pricing.',
                'intro_ar' => 'من الأساسات إلى لمسات التشطيب النهائية، سلسلة توريد البناء في مصر كثيفة وتنافسية. يضم هذا القسم مصنّعي وموردي السيراميك والبلاط، والحديد والألومنيوم، والدهانات والعوازل، والأسمنت والطوب، والأبواب والنوافذ. يستخدمه المقاولون والمطوّرون وتجار الأدوات لتوريد المواد بأسعار المصنع، ومقارنة المواصفات، وبناء علاقات مباشرة مع المنتجين. تصفّح حسب التخصص لاختيار الموردين، ثم تواصل معهم للحصول على عروض أسعار وأسعار الجملة للمنتجات التي يحتاجها مشروعك بالضبط. تنجح المشاريع أو تتعثّر بحسب وصول المواد بالمواصفة وفي الموعد، لذا من الأفضل أن تعرف المنتج مباشرة بدلًا من سلسلة وسطاء. اسأل عن مقاومة الضغط والدرجات للأسمنت والطوب، وعن السماكة ونسبة امتصاص الماء ومقاومة الانزلاق للسيراميك والبلاط، وعن السبيكة والطلاء للألومنيوم والحديد، وعن معدل التغطية وزمن الجفاف للدهانات والعوازل. كثير من المصانع المدرجة هنا ينفّذ أيضًا مقاسات خاصة وكميات المشاريع وجداول تسليم على مراحل للمواقع الكبيرة. استخدم صفحات التخصصات للوصول إلى المادة التي تحتاجها بالضبط، وراجع كتالوج كل شركة، ثم تواصل معها مباشرة للحصول على أسعار المشروع.',
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
                'intro_en' => 'Egypt\'s food industry spans dry goods, beverages, dairy, sweets and bakery, and oils and spices — with a manufacturing base that serves both the local market and export. This directory helps retailers, distributors, restaurants and exporters find reliable food and beverage producers, verify them, and compare product ranges. Source packaged staples, private-label lines, or specialty ingredients directly from the factory, and open a direct channel for recurring supply. Food buyers work to tighter rules than most, so start with the paperwork: valid food-safety certification, clear ingredient and allergen declarations, batch coding, shelf life and storage conditions, and export documentation if you ship abroad. The manufacturers listed here cover dry goods, beverages, dairy, confectionery and bakery, and oils and spices, and many offer private-label production for retailers building their own brand. Whether you supply supermarkets, restaurants, hotels or distribution networks, you can compare producers by specialty, review their product ranges and packaging formats, and contact them directly to request samples and wholesale pricing before committing to a first order.',
                'intro_ar' => 'تمتد صناعة الغذاء في مصر عبر المواد الجافة والمشروبات ومنتجات الألبان والحلويات والمخبوزات والزيوت والتوابل، بقاعدة تصنيعية تخدم السوق المحلي والتصدير معًا. يساعد هذا الدليل تجار التجزئة والموزّعين والمطاعم والمصدّرين على إيجاد منتجي أغذية ومشروبات موثوقين، والتحقق منهم، ومقارنة تشكيلات المنتجات. وفّر السلع المعبّأة أو خطوط العلامات الخاصة أو المكوّنات المتخصصة مباشرة من المصنع، وافتح قناة توريد مباشرة ومتكررة. يعمل مشترو الأغذية وفق اشتراطات أدق من غيرهم، لذا ابدأ بالمستندات: شهادة سلامة غذاء سارية، وبيان واضح للمكوّنات ومسبّبات الحساسية، وترميز التشغيلات، ومدة الصلاحية وظروف التخزين، ومستندات التصدير إن كنت تشحن للخارج. تغطي المصانع المدرجة هنا المواد الغذائية الجافة والمشروبات ومنتجات الألبان والحلويات والمخبوزات والزيوت والتوابل، ويقدّم كثير منها إنتاجًا بعلامة خاصة لتجار التجزئة الذين يبنون علامتهم. سواء كنت تورّد لسلاسل السوبر ماركت أو المطاعم أو الفنادق أو شبكات التوزيع، يمكنك مقارنة المنتجين حسب التخصص، ومراجعة تشكيلاتهم وأشكال التعبئة، والتواصل معهم مباشرة لطلب عيّنات وأسعار الجملة قبل أول طلبية.',
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
                'intro_en' => 'The chemicals sector covers cleaning and sanitising products, cosmetics, industrial chemicals, and paints and coatings. Egyptian manufacturers supply finished consumer goods as well as raw and intermediate chemicals for other industries. Buyers use this directory to find producers by specialty, confirm they can meet volume and specification requirements, and negotiate supply directly — from private-label detergents to bulk industrial inputs. Chemical sourcing rewards precision. Before you order, confirm concentration and purity, request a technical data sheet and a safety data sheet, check packaging and transport classification, and agree on batch testing for repeat supply. The companies here produce detergents and disinfectants, cosmetics and personal-care lines, industrial chemicals, and paints and coatings, and many run contract and private-label manufacturing for brands that do not own a plant. Buyers from facilities management, hospitality, healthcare, retail and industry use this section to find a producer who can hold a consistent formula at volume. Browse by specialty, compare catalogues, and contact manufacturers directly for pricing.',
                'intro_ar' => 'يغطي قطاع الكيماويات منتجات التنظيف والتطهير، ومستحضرات التجميل، والمواد الكيماوية الصناعية، والدهانات والطلاءات. يورّد المصنّعون المصريون سلعًا استهلاكية جاهزة إلى جانب المواد الكيماوية الخام والوسيطة للصناعات الأخرى. يستخدم المشترون هذا الدليل لإيجاد المنتجين حسب التخصص، والتأكد من قدرتهم على تلبية متطلبات الكميات والمواصفات، والتفاوض على التوريد مباشرة — من المنظفات بعلامة خاصة إلى المدخلات الصناعية بالجملة. يكافئ توريد الكيماويات الدقة. قبل الطلب، تأكّد من التركيز والنقاء، واطلب نشرة البيانات الفنية ونشرة بيانات السلامة، وراجع التعبئة وتصنيف النقل، واتفق على اختبار التشغيلات في التوريد المتكرر. تنتج الشركات هنا المنظفات والمطهرات، ومستحضرات التجميل والعناية الشخصية، والمواد الكيماوية الصناعية، والدهانات والطلاءات، ويقدّم كثير منها التصنيع لدى الغير وبعلامة خاصة للعلامات التي لا تملك مصنعًا. يستخدم هذا القسم مشترون من إدارة المنشآت والضيافة والرعاية الصحية والتجزئة والصناعة للعثور على منتج قادر على الحفاظ على ثبات التركيبة بالكميات الكبيرة. تصفّح حسب التخصص، وقارن الكتالوجات، وتواصل مع المصانع مباشرة للأسعار.',
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
                'intro_en' => 'Packaging is where most supply relationships begin. Egypt\'s converters produce plastic containers, cartons and paper, glass containers, and flexible bags and wraps for food, cosmetics, pharma and industry. This directory lets brands and manufacturers find packaging suppliers by type, request samples and quotes, and lock in a dependable source for recurring orders — the packaging partner behind every product on the shelf. Packaging is where cost, shelf appeal and compliance meet, so specify it carefully. Agree on material and thickness, dimensions and tolerances, print method and colour matching, food-contact suitability where relevant, and the minimum order quantity a factory will accept for a custom die or plate. The suppliers listed here produce plastic containers, cartons and paper, glass and glass containers, and bags and flexible packaging, serving food producers, cosmetics brands, pharmaceutical companies and e-commerce sellers. Compare producers by specialty, look at the formats and print capabilities in their catalogues, and contact them directly to request samples, tooling costs and per-unit pricing at your volume.',
                'intro_ar' => 'التغليف هو حيث تبدأ معظم علاقات التوريد. ينتج محوّلو مصر عبوات بلاستيكية، وكراتين وورقًا، وعبوات زجاجية، وأكياسًا ولفائف مرنة للأغذية ومستحضرات التجميل والأدوية والصناعة. يتيح هذا الدليل للعلامات والمصنّعين إيجاد موردي التغليف حسب النوع، وطلب العينات وعروض الأسعار، وتأمين مصدر موثوق للطلبات المتكررة — شريك التغليف وراء كل منتج على الرفّ. التغليف هو نقطة التقاء التكلفة والجاذبية على الرف والامتثال، لذا حدّد مواصفاته بعناية. اتفق على الخامة والسماكة، والمقاسات والسماحات، وطريقة الطباعة ومطابقة الألوان، وصلاحية التلامس مع الغذاء عند الحاجة، والحد الأدنى للكمية الذي يقبله المصنع لأسطوانة أو كليشيه مخصص. ينتج الموردون المدرجون هنا العبوات البلاستيكية والكراتين والورق والزجاج والعبوات الزجاجية والأكياس والتغليف المرن، ويخدمون منتجي الأغذية وعلامات التجميل وشركات الأدوية وبائعي التجارة الإلكترونية. قارن المنتجين حسب التخصص، واطّلع على الأشكال وإمكانات الطباعة في كتالوجاتهم، وتواصل معهم مباشرة لطلب العيّنات وتكاليف القوالب وسعر الوحدة عند كميتك.',
                'children' => [
                    ['slug' => 'plastic-containers', 'name_en' => 'Plastic Containers', 'name_ar' => 'عبوات بلاستيك'],
                    ['slug' => 'cartons-and-paper', 'name_en' => 'Cartons & Paper', 'name_ar' => 'كراتين وورق'],
                    ['slug' => 'glass-containers', 'name_en' => 'Glass Containers', 'name_ar' => 'زجاج وعبوات زجاجية'],
                    ['slug' => 'flexible-packaging', 'name_en' => 'Bags & Flexible Packaging', 'name_ar' => 'أكياس وتغليف مرن'],
                ],
            ],
            [
                'slug' => 'metals-and-engineering', 'name_en' => 'Metals & Engineering', 'name_ar' => 'المعادن والهندسية', 'icon' => '🔧',
                'intro_en' => 'The metals and engineering sector supplies auto parts, machinery and equipment, wrought iron, and hand tools. Egyptian workshops and factories serve manufacturers, workshops, and importers with both stock products and made-to-order fabrication. Use this directory to find engineering suppliers by specialty, assess their capabilities, and open direct lines for parts, tooling and custom metalwork at competitive prices. Engineering buyers care about tolerances, materials and repeatability. Confirm the alloy or steel grade, machining tolerances, surface treatment and any certification your sector requires, and ask whether the workshop can work from your drawings for custom parts. The companies in this section supply auto spare parts, machinery and equipment, fabricated metal and hand tools, and many combine standard catalogue items with made-to-order production and after-sales support. Workshops, factories, maintenance teams and distributors use this directory to find a producer close to them and shorten lead times. Browse by specialty, review each supplier\'s range, and contact them directly for quotations on your drawings or part numbers.',
                'intro_ar' => 'يورّد قطاع المعادن والهندسة قطع غيار السيارات، والمعدات والمكينات، والحديد المشغول، والأدوات اليدوية. تخدم الورش والمصانع المصرية المصنّعين والورش والمستوردين بمنتجات جاهزة وتصنيع حسب الطلب. استخدم هذا الدليل لإيجاد موردي القطاع الهندسي حسب التخصص، وتقييم إمكاناتهم، وفتح خطوط مباشرة للقطع والعدد والأشغال المعدنية المخصصة بأسعار تنافسية. يهتم مشترو القطاع الهندسي بالسماحات والخامات وثبات الجودة. تأكّد من درجة السبيكة أو الصلب، وسماحات التشغيل، والمعالجة السطحية، وأي شهادات يتطلبها مجالك، واسأل ما إذا كانت الورشة تنفّذ القطع الخاصة من رسوماتك. تورّد الشركات في هذا القسم قطع غيار السيارات، والمعدات والمكينات، والحديد المشغول، والأدوات اليدوية، ويجمع كثير منها بين المنتجات القياسية في الكتالوج والتصنيع حسب الطلب وخدمات ما بعد البيع. تستخدم الورش والمصانع وفرق الصيانة والموزّعون هذا الدليل للعثور على منتج قريب منهم وتقليل مدة التنفيذ. تصفّح حسب التخصص، وراجع تشكيلة كل مورد، وتواصل معه مباشرة للحصول على عرض سعر على رسوماتك أو أرقام القطع.',
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
                'intro_en' => 'Egypt has a deep tradition of woodworking, from Damietta\'s furniture workshops to modern factories. This section covers home furniture, office furniture, wooden doors and frames, and wooden décor. Retailers, interior designers, offices and developers use the directory to source furniture makers, review their ranges, and commission stock or bespoke pieces directly from the manufacturer at workshop prices. Furniture buying is as much about materials and finishing as it is about design. Check the wood type or board grade, edge banding, hardware quality, finish durability and whether the workshop can match a sample or work to your drawings. The manufacturers here produce home furniture, office furniture, wooden doors and frames, and wooden décor, and many handle project quantities for hotels, offices, restaurants and residential developments alongside their retail ranges. Lead times matter here more than in most sectors, so confirm production capacity before committing. Browse by specialty to shortlist workshops and factories, review their catalogues and past work, then contact them directly for quotations and delivery timelines.',
                'intro_ar' => 'لمصر تراث عريق في صناعة الخشب، من ورش أثاث دمياط إلى المصانع الحديثة. يغطي هذا القسم الأثاث المنزلي، والأثاث المكتبي، والأبواب والإطارات الخشبية، والديكور الخشبي. يستخدم تجار التجزئة ومصممو الديكور والمكاتب والمطوّرون الدليل لتوريد صنّاع الأثاث، ومراجعة تشكيلاتهم، وطلب قطع جاهزة أو مخصصة مباشرة من المصنّع بأسعار الورشة. شراء الأثاث يتعلّق بالخامات والتشطيب بقدر ما يتعلّق بالتصميم. تحقّق من نوع الخشب أو درجة الألواح، وشرائط الحواف، وجودة الإكسسوارات، ومتانة التشطيب، وما إذا كانت الورشة قادرة على مطابقة عيّنة أو التنفيذ من رسوماتك. تنتج المصانع هنا الأثاث المنزلي والمكتبي، والأبواب والإطارات الخشبية، والديكور الخشبي، وينفّذ كثير منها كميات المشاريع للفنادق والمكاتب والمطاعم والمشروعات السكنية إلى جانب تشكيلاتها للتجزئة. مدة التنفيذ هنا أهم من معظم القطاعات، لذا تأكّد من الطاقة الإنتاجية قبل الالتزام. تصفّح حسب التخصص لاختيار الورش والمصانع، وراجع كتالوجاتهم وأعمالهم السابقة، ثم تواصل معهم مباشرة لعروض الأسعار ومواعيد التسليم.',
                'children' => [
                    ['slug' => 'home-furniture', 'name_en' => 'Home Furniture', 'name_ar' => 'أثاث منزلي'],
                    ['slug' => 'office-furniture', 'name_en' => 'Office Furniture', 'name_ar' => 'أثاث مكتبي'],
                    ['slug' => 'wooden-doors-and-frames', 'name_en' => 'Wooden Doors & Frames', 'name_ar' => 'أبواب وإطارات خشب'],
                    ['slug' => 'wooden-decor', 'name_en' => 'Wooden Décor', 'name_ar' => 'ديكور خشبي'],
                ],
            ],
            [
                'slug' => 'other', 'name_en' => 'Other', 'name_ar' => 'أخرى', 'icon' => '✨',
                'intro_en' => 'Not every business fits neatly into one sector. This catch-all lists manufacturers and suppliers whose products span or fall outside the main categories — from niche industries to new and emerging ones. Browse it to discover suppliers you might otherwise miss, and reach out directly to explore what they make. Not every business fits neatly into one industry, and some of the most useful suppliers sit between categories or serve several at once. This section is where those manufacturers, workshops and specialised suppliers list themselves so buyers can still find them. If you are sourcing something unusual, a mixed order, or a service that supports production rather than a finished product, start here and use the search to narrow down. Every company profile shows what it produces, where it is based and how to reach it directly. If you run a factory or supply business that does not match the other seven sectors, add your company here so buyers searching for what you make can find you.',
                'intro_ar' => 'ليست كل الأنشطة تندرج بدقة تحت قطاع واحد. يضم هذا القسم الجامع المصنّعين والموردين الذين تمتد منتجاتهم عبر القطاعات الرئيسية أو تقع خارجها — من الصناعات المتخصصة إلى الناشئة والجديدة. تصفّحه لاكتشاف موردين قد تفوتك رؤيتهم، وتواصل معهم مباشرة لاستكشاف ما ينتجون. ليست كل الأنشطة تندرج بدقة تحت صناعة واحدة، وبعض أنفع الموردين يقع بين التصنيفات أو يخدم أكثر من قطاع في آن واحد. هذا القسم هو المكان الذي يُدرج فيه هؤلاء المصنّعون والورش والموردون المتخصّصون أنفسهم ليتمكّن المشترون من الوصول إليهم. إذا كنت تبحث عن شيء غير معتاد، أو طلبية مختلطة، أو خدمة تدعم الإنتاج بدلًا من منتج نهائي، فابدأ من هنا واستخدم البحث لتضييق النتائج. يوضّح كل ملف شركة ما تنتجه، وأين تقع، وكيف تتواصل معها مباشرة. وإذا كنت تدير مصنعًا أو نشاط توريد لا يطابق القطاعات السبعة الأخرى، فأضف شركتك هنا ليجدك المشترون الباحثون عمّا تنتجه.',
                'children' => [],
            ],
        ];
    }
}
