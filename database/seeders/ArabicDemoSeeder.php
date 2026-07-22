<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fills the whole domain with Arabic demo data wired to the demo accounts, with
 * LARGE per-owner catalogs so every merchant & supplier has a full showcase.
 *   Admin    = #1 (wigpleasure@gmail.com)
 *   Merchant = #2 (customer@demo.com)   -> username asala-store
 *   Supplier = #3 (supplier@demo.com)   -> username noor-supplies (public product showcase)
 *   + extra supplier #4 (modern-wholesale) and extra merchant #5 (elite-store)
 * Re-runnable: clears demo domain tables first (keeps users 1-3, roles, permissions,
 * warehouses, currencies, attributes, settings, email templates).
 */
class ArabicDemoSeeder extends Seeder
{
    private int $admin = 1;
    private int $merchant = 2;
    private int $supplier = 3;

    /** Bilingual categories: [ar, en] */
    private array $categories = [
        ['إلكترونيات', 'Electronics'],
        ['ملابس رجالية', "Men's Clothing"],
        ['ملابس نسائية', "Women's Clothing"],
        ['منتجات العناية', 'Personal Care'],
        ['منزل ومطبخ', 'Home & Kitchen'],
        ['إكسسوارات', 'Accessories'],
        ['أجهزة منزلية', 'Home Appliances'],
        ['رياضة ولياقة', 'Sports & Fitness'],
        ['ألعاب أطفال', "Kids' Toys"],
        ['كتب وقرطاسية', 'Books & Stationery'],
        ['هواتف وملحقاتها', 'Phones & Accessories'],
        ['مستلزمات حيوانات', 'Pet Supplies'],
    ];

    /** Arabic base product names per category (index aligned with $categories) */
    private array $pool = [
        ['سماعات بلوتوث لاسلكية', 'ساعة ذكية رياضية', 'شاحن سريع 65 واط', 'باور بانك 20000', 'ماوس لاسلكي', 'مكبر صوت محمول', 'كاميرا ويب HD', 'قرص تخزين خارجي'],
        ['قميص قطني رجالي', 'بنطال جينز', 'تيشيرت رياضي', 'جاكيت شتوي', 'بدلة رسمية', 'حذاء رياضي رجالي', 'حزام جلد طبيعي', 'معطف صوف'],
        ['فستان سهرة', 'عباية مطرزة', 'بلوزة حرير', 'تنورة كاجوال', 'حجاب قطن', 'حذاء كعب عالٍ', 'حقيبة يد نسائية', 'شال صوف'],
        ['عطر عود فاخر', 'كريم مرطب للبشرة', 'شامبو بالكيراتين', 'غسول وجه', 'واقي شمس SPF50', 'زيت شعر مغذّي', 'معطر جسم'],
        ['طقم أواني طهي', 'خلاط كهربائي', 'غلاية كهربائية', 'محضرة طعام', 'طقم سكاكين', 'مقلاة هوائية', 'ماكينة قهوة'],
        ['نظارة شمسية', 'حقيبة ظهر جلدية', 'ساعة يد كلاسيكية', 'محفظة جلد', 'سلسلة مفاتيح', 'قبعة صيفية'],
        ['مكنسة كهربائية', 'مروحة عمودية', 'مكواة بخار', 'سخان مياه', 'منقي هواء'],
        ['دمبل قابل للتعديل', 'حصيرة يوجا', 'دراجة ثابتة', 'حبل قفز', 'زجاجة مياه رياضية'],
        ['مكعبات تعليمية', 'سيارة تحكم عن بعد', 'دمية قماشية', 'لغز خشبي', 'مجسم ديناصور'],
        ['دفتر ملاحظات فاخر', 'طقم أقلام حبر', 'حقيبة مدرسية', 'كتاب تنمية ذاتية'],
        ['جراب هاتف واقٍ', 'واقي شاشة زجاجي', 'حامل هاتف للسيارة', 'كابل شحن مضفّر', 'سماعة أذن سلكية'],
        ['طعام قطط جاف', 'لعبة مضغ للكلاب', 'قفص طيور', 'وعاء طعام للحيوانات'],
    ];

    private array $variants = ['الإصدار الفاخر', 'موديل 2025', 'لون أسود', 'لون أبيض', 'مقاس كبير', 'نسخة برو', 'إصدار محدود', 'لون ذهبي', 'فئة اقتصادية', 'جودة عالية'];

    public function run(): void
    {
        $now = Carbon::now();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'transactions', 'supplier_payments', 'order_deliveries', 'order_quotations',
            'ticket_actions', 'ticket_messages', 'order_tickets', 'messages', 'conversations',
            'product_orders', 'order_suppliers', 'orders',
            'warehouse_inventories', 'bundle_product', 'bundles', 'product_attributes', 'products',
            'gateway_transactions', 'gateway_wallets', 'payment_gateways',
            'wavex_campaign_recipients', 'wavex_campaigns', 'wavex_contact_group_members',
            'wavex_contact_groups', 'wavex_templates', 'wavex_inbox_messages',
            'tasks', 'verification_requests', 'invitations', 'merchant_supplier',
            'articles', 'notifications', 'activity_logs', 'login_histories', 'email_logs', 'categories',
        ] as $t) {
            DB::table($t)->delete();
        }
        DB::table('profiles')->where('user_id', '>', 3)->delete();
        DB::table('model_has_roles')->where('model_id', '>', 3)->delete();
        DB::table('users')->where('id', '>', 3)->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $roles = DB::table('roles')->pluck('id', 'name');

        // ---------------------------------------------------------------
        // 1) Profiles (Arabic) + friendly public usernames
        // ---------------------------------------------------------------
        $this->upsertProfile($this->admin, 'wigpleasure-admin', 'سيل‌تشيس', 'مصر', 'القاهرة',
            'المدير العام لمنصة سيل‌تشيس لإدارة الطلبات والتوريد.', 'إدارة ذكية للطلبات', '+201000000001', $now);
        $this->upsertProfile($this->merchant, 'asala-store', 'متجر الأصالة', 'الإمارات', 'دبي',
            'متجر تجزئة إلكتروني متخصص في الإلكترونيات ومنتجات العناية والأزياء.', 'كل ما تحتاجه في مكان واحد', '+971500000002', $now);
        $this->upsertProfile($this->supplier, 'noor-supplies', 'مؤسسة النور للتوريدات', 'السعودية', 'الرياض',
            'مورد جملة موثوق للإلكترونيات والإكسسوارات والأجهزة المنزلية بأسعار تنافسية.', 'جودة عالية وتسليم سريع', '+966550000003', $now);

        // ---------------------------------------------------------------
        // 2) Extra users for richer directory + showcases
        // ---------------------------------------------------------------
        $pass = Hash::make('12345678');
        $extraSupplier = DB::table('users')->insertGetId([
            'name' => 'مورد الجملة الحديثة', 'email' => 'supplier2@demo.com', 'password' => $pass,
            'email_verified_at' => $now, 'is_active' => 1, 'is_verified' => 1, 'verified_at' => $now,
            'verified_by_user_id' => $this->admin, 'pending_approval' => 0, 'registration_role' => 'Supplier',
            'created_at' => $now->copy()->subDays(20), 'updated_at' => $now,
        ]);
        $extraMerchant = DB::table('users')->insertGetId([
            'name' => 'متجر النخبة', 'email' => 'merchant2@demo.com', 'password' => $pass,
            'email_verified_at' => $now, 'is_active' => 1, 'is_verified' => 0, 'pending_approval' => 0,
            'registration_role' => 'Merchant', 'created_at' => $now->copy()->subDays(12), 'updated_at' => $now,
        ]);
        $pendingUser = DB::table('users')->insertGetId([
            'name' => 'تاجر بانتظار الموافقة', 'email' => 'pending@demo.com', 'password' => $pass,
            'email_verified_at' => $now, 'is_active' => 1, 'is_verified' => 0, 'pending_approval' => 1,
            'registration_role' => 'Merchant', 'created_at' => $now->copy()->subDays(2), 'updated_at' => $now,
        ]);
        DB::table('model_has_roles')->insert([
            ['role_id' => $roles['Supplier'], 'model_type' => 'App\\Models\\User', 'model_id' => $extraSupplier],
            ['role_id' => $roles['Merchant'], 'model_type' => 'App\\Models\\User', 'model_id' => $extraMerchant],
            ['role_id' => $roles['Merchant'], 'model_type' => 'App\\Models\\User', 'model_id' => $pendingUser],
        ]);
        $this->insertProfile($extraSupplier, 'modern-wholesale', 'مورد الجملة الحديثة', 'السعودية', 'الرياض',
            'موزّع معتمد للأجهزة المنزلية والمنتجات الرياضية بكميات الجملة.', 'شريكك في التوريد', '+966550000004', $now);
        $this->insertProfile($extraMerchant, 'elite-store', 'متجر النخبة', 'السعودية', 'جدة',
            'متجر أزياء وإكسسوارات راقية يخدم عملاء الخليج.', 'أناقة بلا حدود', '+966550000005', $now);
        $this->insertProfile($pendingUser, 'pending-trader', 'تاجر جديد', 'السعودية', 'الدمام',
            'حساب قيد المراجعة.', '', '+966550000006', $now, isPublic: 0);

        // ---------------------------------------------------------------
        // 2b) Staff / Employees under each merchant & supplier
        //     (child users: parent_user_id = owner, role = Employee)
        // ---------------------------------------------------------------
        $firstNames = ['أحمد', 'محمد', 'سارة', 'فاطمة', 'عمر', 'ليلى', 'خالد', 'نورة', 'يوسف', 'هبة', 'طارق', 'رنا', 'سامي', 'منى', 'كريم', 'دعاء'];
        $lastNames = ['علي', 'حسن', 'إبراهيم', 'خالد', 'يوسف', 'سعيد', 'الوليد', 'فهد', 'كريم', 'سمير', 'عزمي', 'ناصر'];
        $jobTitles = ['مدير مبيعات', 'موظف طلبات', 'خدمة عملاء', 'أمين مخزن', 'محاسب', 'مسؤول شحن', 'مسؤول تسويق', 'مشرف عمليات'];
        $permSets = [
            ['orders-out', 'orders-in', 'quotations-out'],
            ['orders-out', 'tickets-list', 'tickets-create'],
            ['products-list', 'categories-list', 'bundles-list'],
            ['deliveries-list', 'shipping-companies-list'],
            ['balance-in', 'balance-out', 'notifications-orders'],
        ];
        $staffOwners = [
            $this->merchant => 'متجر الأصالة',
            $this->supplier => 'مؤسسة النور للتوريدات',
            $extraSupplier => 'مورد الجملة الحديثة',
            $extraMerchant => 'متجر النخبة',
        ];
        $empCounter = 0;
        $staffPerOwner = 8;
        foreach ($staffOwners as $ownerId => $ownerCompany) {
            for ($e = 1; $e <= $staffPerOwner; $e++) {
                $empCounter++;
                $name = $firstNames[$empCounter % count($firstNames)] . ' ' . $lastNames[($empCounter * 3) % count($lastNames)];
                $title = $jobTitles[$e % count($jobTitles)];
                $eid = DB::table('users')->insertGetId([
                    'name' => $name, 'email' => 'emp' . $ownerId . '-' . $e . '@demo.com', 'password' => $pass,
                    'email_verified_at' => $now, 'is_active' => ($e % 8 !== 0) ? 1 : 0, 'is_verified' => 0,
                    'pending_approval' => 0, 'registration_role' => 'Employee', 'parent_user_id' => $ownerId,
                    'created_at' => $now->copy()->subDays(rand(1, 30)), 'updated_at' => $now,
                ]);
                DB::table('model_has_roles')->insert(['role_id' => $roles['Employee'], 'model_type' => 'App\\Models\\User', 'model_id' => $eid]);
                DB::table('profiles')->insert([
                    'username' => 'emp-' . $eid, 'company' => $ownerCompany, 'tagline' => $title,
                    'biography' => $title . ' لدى ' . $ownerCompany, 'city' => 'الرياض', 'country' => 'السعودية',
                    'gender' => 'male', 'is_public' => 0, 'active' => 1, 'user_id' => $eid,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        // ---------------------------------------------------------------
        // 3) Categories
        // ---------------------------------------------------------------
        $catIds = [];
        foreach ($this->categories as $i => $c) {
            $catIds[] = DB::table('categories')->insertGetId([
                'wigpleasure_category_id' => 1000 + $i, 'name' => $c[0],
                'name_ar' => $c[0], 'name_en' => $c[1], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // ---------------------------------------------------------------
        // 4) LARGE catalogs — 3 products per category per owner (~36 each)
        // ---------------------------------------------------------------
        $attrIds = DB::table('attributes')->pluck('id')->all();
        $warehouseByOwner = [$this->merchant => 2, $this->supplier => 3, $extraSupplier => 3, $extraMerchant => 4];
        $catalogs = [];
        foreach ([$this->merchant, $this->supplier, $extraSupplier, $extraMerchant] as $ownerIdx => $ownerId) {
            $ids = [];
            foreach ($this->categories as $ci => $cat) {
                $bases = $this->pool[$ci];
                for ($n = 0; $n < 3; $n++) {
                    $base = $bases[($ownerIdx + $n) % count($bases)];
                    $variant = $this->variants[($ownerIdx * 3 + $n + $ci) % count($this->variants)];
                    $name = $base . ' — ' . $variant;
                    $pid = DB::table('products')->insertGetId([
                        'name' => $name,
                        'description' => 'منتج ' . $base . ' بجودة ممتازة ضمن قسم ' . $cat[0] . '. ' . $variant . '، متوفر للطلب بالجملة والتجزئة.',
                        'category_id' => $catIds[$ci], 'user_id' => $ownerId,
                        'created_at' => $now->copy()->subDays(rand(1, 40)), 'updated_at' => $now,
                    ]);
                    $ids[] = $pid;

                    // 2 attribute links per product
                    foreach ((array) array_rand(array_flip($attrIds), 2) as $aid) {
                        DB::table('product_attributes')->insert([
                            'product_id' => $pid, 'attribute_id' => $aid, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }

                    // inventory in owner's primary warehouse + one other
                    $primary = $warehouseByOwner[$ownerId];
                    $other = [1, 2, 3, 4][($pid + $ci) % 4];
                    $whs = array_unique([$primary, $other]);
                    foreach ($whs as $wh) {
                        DB::table('warehouse_inventories')->insert([
                            'warehouse_id' => $wh, 'product_id' => $pid, 'qty' => rand(0, 200),
                            'reserved_qty' => rand(0, 15), 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }
            }
            $catalogs[$ownerId] = $ids;
        }
        $merchantProducts = $catalogs[$this->merchant];

        // ---------------------------------------------------------------
        // 5) Bundles — 3 per catalog owner (merchant + supplier + extras)
        // ---------------------------------------------------------------
        $bundleNames = [
            ['باقة الإلكترونيات', 'سماعات + ساعة ذكية + شاحن سريع'],
            ['باقة العناية الشخصية', 'عطر فاخر + كريم مرطب + شامبو'],
            ['باقة المنزل الذكي', 'أجهزة وأدوات منزلية مختارة'],
        ];
        foreach ($catalogs as $ownerId => $ownerProducts) {
            foreach ($bundleNames as $bi => $bn) {
                $bid = DB::table('bundles')->insertGetId([
                    'name' => $bn[0], 'description' => $bn[1], 'user_id' => $ownerId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                // pick 3 distinct products from this owner's catalog
                $offset = $bi * 3;
                foreach ([0, 1, 2] as $k) {
                    DB::table('bundle_product')->insert([
                        'bundle_id' => $bid, 'product_id' => $ownerProducts[($offset + $k) % count($ownerProducts)],
                        'quantity' => $k + 1, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }

        // ---------------------------------------------------------------
        // 6) Partnerships + invitations
        // ---------------------------------------------------------------
        DB::table('merchant_supplier')->insert([
            ['merchant_id' => $this->merchant, 'supplier_id' => $this->supplier, 'status' => 'accepted', 'invited_by_user_id' => $this->merchant, 'created_at' => $now, 'updated_at' => $now],
            ['merchant_id' => $this->merchant, 'supplier_id' => $extraSupplier, 'status' => 'accepted', 'invited_by_user_id' => $this->merchant, 'created_at' => $now, 'updated_at' => $now],
            ['merchant_id' => $extraMerchant, 'supplier_id' => $this->supplier, 'status' => 'accepted', 'invited_by_user_id' => $extraMerchant, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('invitations')->insert([
            ['email' => 'newsupplier@demo.com', 'invitation_token' => Str::random(40), 'invite_code' => strtoupper(Str::random(8)), 'invited_role' => 'Supplier', 'status' => 'pending', 'is_reusable' => 0, 'expires_at' => $now->copy()->addDays(7), 'registered_at' => null, 'sender_user_id' => $this->merchant, 'created_at' => $now, 'updated_at' => $now],
            ['email' => 'partner@demo.com', 'invitation_token' => Str::random(40), 'invite_code' => strtoupper(Str::random(8)), 'invited_role' => 'Merchant', 'status' => 'accepted', 'is_reusable' => 0, 'expires_at' => null, 'registered_at' => $now, 'sender_user_id' => $this->admin, 'created_at' => $now->copy()->subDays(5), 'updated_at' => $now],
        ]);

        // ---------------------------------------------------------------
        // 7) Orders + suppliers + quotations + deliveries + transactions
        // ---------------------------------------------------------------
        $endCustomers = [
            ['أحمد المصري', 'ahmed@example.com', '+201111111111', 'مدينة نصر، القاهرة'],
            ['فاطمة الزهراء', 'fatima@example.com', '+201222222222', 'المعادي، القاهرة'],
            ['خالد العتيبي', 'khaled@example.com', '+966511111111', 'حي العليا، الرياض'],
            ['نورة القحطاني', 'noura@example.com', '+966522222222', 'حي النخيل، جدة'],
            ['محمد راشد', 'mohd@example.com', '+971501111111', 'الجميرا، دبي'],
        ];
        $currencies = ['EGP', 'SAR', 'AED'];
        $shipCompanies = DB::table('shipping_companies')->pluck('id')->all();
        $plan = [
            ['pending', null, null], ['pending', 'pending', null], ['pending', 'pending', null],
            ['awaiting_shipping', 'accepted', null], ['awaiting_shipping', 'accepted', null],
            ['shipped', 'accepted', 'in_transit'], ['shipped', 'accepted', 'in_transit'], ['shipped', 'rejected', null],
            ['completed', 'accepted', 'delivered'], ['completed', 'accepted', 'delivered'], ['completed', 'accepted', 'delivered'],
            ['cancelled', null, null],
        ];
        foreach ($plan as $i => $row) {
            [$oStatus, $qStatus, $dStatus] = $row;
            $pid = $merchantProducts[$i % count($merchantProducts)];
            $cust = $endCustomers[$i % count($endCustomers)];
            $cur = $currencies[$i % 3];
            $createdAt = $now->copy()->subDays($i % 7)->subHours(rand(0, 20));
            $price = rand(150, 4500);
            $orderId = DB::table('orders')->insertGetId([
                'code' => 'ORD-' . (1001 + $i), 'quantity' => rand(1, 6), 'product_id' => $pid,
                'user_id' => $this->merchant, 'assigned_supplier_id' => $this->supplier,
                'customer_name' => $cust[0], 'customer_email' => $cust[1], 'customer_phone' => $cust[2],
                'status' => $oStatus, 'ref_number' => 'REF-' . rand(10000, 99999),
                'notes' => 'طلب من متجر الأصالة — يرجى التغليف بعناية.', 'shipping_address' => $cust[3],
                'shipping_type' => 'customer_direct', 'payment_method' => 'cod', 'payment_type' => 'full',
                'paid_amount' => $oStatus === 'completed' ? $price : 0, 'currency' => $cur,
                'created_at' => $createdAt, 'updated_at' => $now,
            ]);
            DB::table('order_suppliers')->insert(['order_id' => $orderId, 'customer' => $this->merchant, 'supplier' => $this->supplier, 'seen' => 1, 'created_at' => $createdAt, 'updated_at' => $now]);
            DB::table('product_orders')->insert(['product_id' => $pid, 'order_id' => $orderId, 'created_at' => $createdAt, 'updated_at' => $now]);
            if ($qStatus !== null) {
                $qId = DB::table('order_quotations')->insertGetId([
                    'price' => $price, 'price_includes_shipping' => 1, 'currency' => $cur,
                    'delivery_date' => $now->copy()->addDays(rand(3, 14))->toDateString(),
                    'notes' => 'عرض سعر شامل الشحن خلال أسبوع.', 'status' => $qStatus, 'seen' => 1,
                    'order_id' => $orderId, 'supplier_user_id' => $this->supplier, 'customer_user_id' => $this->merchant,
                    'shipping_company' => 'أرامكس', 'tracking_number' => $dStatus ? 'TRK' . rand(100000, 999999) : null,
                    'shipped_at' => in_array($dStatus, ['in_transit', 'delivered']) ? $createdAt : null,
                    'created_at' => $createdAt, 'updated_at' => $now,
                ]);
                if ($qStatus === 'accepted') {
                    DB::table('transactions')->insert(['supplier_user_id' => $this->supplier, 'customer_user_id' => $this->merchant, 'type' => 'order_payment', 'quotation_id' => $qId, 'orders' => (string) $orderId, 'amount' => $price, 'transfer_method' => 'تحويل بنكي', 'notes' => 'دفعة مقابل الطلب ORD-' . (1001 + $i), 'created_at' => $createdAt, 'updated_at' => $now]);
                }
            }
            if ($dStatus !== null) {
                DB::table('order_deliveries')->insert(['order_id' => $orderId, 'segment' => 'to_customer', 'shipping_company_id' => $shipCompanies[array_rand($shipCompanies)], 'delivery_company' => 'aramex', 'tracking_number' => 'TRK' . rand(100000, 999999), 'status' => $dStatus, 'cod_amount' => $cur === 'EGP' ? $price : 0, 'delivered_at' => $dStatus === 'delivered' ? $now->copy()->subDays(1) : null, 'notes' => 'شحنة إلى العميل مباشرة.', 'created_at' => $createdAt, 'updated_at' => $now]);
            }
        }
        $orderIds = DB::table('orders')->pluck('id')->all();

        // ---------------------------------------------------------------
        // 8) Tickets + messages + actions
        // ---------------------------------------------------------------
        $tickets = [
            ['return', 'open', 'المنتج وصل بحجم مختلف عن المطلوب، أرغب في الإرجاع.'],
            ['replacement', 'awaiting_supplier', 'الشاشة بها خدش بسيط، أطلب استبدال القطعة.'],
            ['other', 'in_progress', 'استفسار عن موعد تسليم الشحنة القادمة.'],
            ['return', 'closed', 'تم حل المشكلة واسترجاع المبلغ. شكراً لكم.'],
        ];
        foreach ($tickets as $i => $t) {
            $tid = DB::table('order_tickets')->insertGetId(['order_id' => $orderIds[$i], 'type' => $t[0], 'status' => $t[1], 'requested_by' => $this->merchant, 'assigned_to' => $this->supplier, 'notes' => $t[2], 'created_at' => $now->copy()->subDays(rand(1, 5)), 'updated_at' => $now]);
            DB::table('ticket_messages')->insert([
                ['ticket_id' => $tid, 'user_id' => $this->merchant, 'body' => $t[2], 'attachments' => null, 'created_at' => $now->copy()->subDays(1)->subHours(3), 'updated_at' => $now],
                ['ticket_id' => $tid, 'user_id' => $this->supplier, 'body' => 'شكراً لتواصلك، جارٍ مراجعة الطلب والرد خلال 24 ساعة.', 'attachments' => null, 'created_at' => $now->copy()->subDays(1), 'updated_at' => $now],
            ]);
            DB::table('ticket_actions')->insert(['ticket_id' => $tid, 'action' => $t[1] === 'closed' ? 'refund' : 'note', 'performed_by' => $this->admin, 'notes' => $t[1] === 'closed' ? 'تم تنفيذ استرداد المبلغ.' : 'تمت مراجعة التذكرة.', 'created_at' => $now, 'updated_at' => $now]);
        }

        // ---------------------------------------------------------------
        // 9) Conversation + messages
        // ---------------------------------------------------------------
        $convId = DB::table('conversations')->insertGetId(['order_id' => $orderIds[0], 'created_at' => $now, 'updated_at' => $now]);
        DB::table('messages')->insert([
            ['conversation_id' => $convId, 'user_id' => $this->merchant, 'body' => 'مرحباً، هل يمكن تسريع شحن هذا الطلب؟', 'created_at' => $now->copy()->subHours(5), 'updated_at' => $now],
            ['conversation_id' => $convId, 'user_id' => $this->supplier, 'body' => 'بالتأكيد، سيتم الشحن غداً صباحاً بإذن الله.', 'created_at' => $now->copy()->subHours(4), 'updated_at' => $now],
            ['conversation_id' => $convId, 'user_id' => $this->merchant, 'body' => 'ممتاز، شكراً جزيلاً لك.', 'created_at' => $now->copy()->subHours(3), 'updated_at' => $now],
        ]);

        // ---------------------------------------------------------------
        // 10) Gateways + wallets + transactions
        // ---------------------------------------------------------------
        foreach ([['سترايب', 'stripe'], ['باي بال', 'paypal'], ['فوري', 'fawry'], ['تحويل بنكي', 'bank-transfer']] as $g) {
            $gid = DB::table('payment_gateways')->insertGetId(['name' => $g[0], 'slug' => $g[1], 'created_at' => $now, 'updated_at' => $now]);
            DB::table('gateway_wallets')->insert(['gateway_id' => $gid, 'balance' => rand(1000, 50000), 'created_at' => $now, 'updated_at' => $now]);
            for ($k = 0; $k < 3; $k++) {
                DB::table('gateway_transactions')->insert(['gateway_id' => $gid, 'type' => ['deposit', 'order_payment', 'withdrawal'][$k], 'amount' => rand(100, 5000), 'reference_type' => 'order', 'reference_id' => $orderIds[array_rand($orderIds)], 'notes' => 'حركة مالية تجريبية عبر ' . $g[0], 'created_at' => $now->copy()->subDays($k), 'updated_at' => $now]);
            }
        }
        DB::table('supplier_payments')->insert([
            ['supplier_id' => $this->supplier, 'amount' => 3500, 'notes' => 'تسوية مستحقات شهر يونيو', 'recorded_by' => $this->admin, 'created_at' => $now->copy()->subDays(6), 'updated_at' => $now],
            ['supplier_id' => $this->supplier, 'amount' => 1800, 'notes' => 'دفعة مقدمة', 'recorded_by' => $this->admin, 'created_at' => $now->copy()->subDays(2), 'updated_at' => $now],
        ]);

        // ---------------------------------------------------------------
        // 11) Tasks / verifications / articles / notifications / logs
        // ---------------------------------------------------------------
        DB::table('tasks')->insert([
            ['assigned_to_user_id' => $this->supplier, 'assigned_by_user_id' => $this->admin, 'title' => 'تحديث قائمة الأسعار', 'description' => 'مراجعة وتحديث أسعار المنتجات الإلكترونية.', 'status' => 'in_progress', 'due_date' => $now->copy()->addDays(3), 'created_at' => $now, 'updated_at' => $now],
            ['assigned_to_user_id' => $this->merchant, 'assigned_by_user_id' => $this->admin, 'title' => 'رفع منتجات جديدة', 'description' => 'إضافة 10 منتجات جديدة لقسم المنزل.', 'status' => 'pending', 'due_date' => $now->copy()->addDays(5), 'created_at' => $now, 'updated_at' => $now],
            ['assigned_to_user_id' => $this->supplier, 'assigned_by_user_id' => $this->merchant, 'title' => 'تجهيز شحنة الرياض', 'description' => 'تغليف وتجهيز طلبات مدينة الرياض.', 'status' => 'done', 'due_date' => $now->copy()->subDays(1), 'created_at' => $now->copy()->subDays(4), 'updated_at' => $now],
        ]);
        DB::table('verification_requests')->insert([
            ['user_id' => $this->merchant, 'status' => 'approved', 'notes' => 'طلب توثيق حساب المتجر.', 'review_notes' => 'تم التحقق من السجل التجاري.', 'reviewed_by_user_id' => $this->admin, 'reviewed_at' => $now, 'documents' => json_encode(['commercial_register.pdf']), 'created_at' => $now->copy()->subDays(8), 'updated_at' => $now],
            ['user_id' => $this->supplier, 'status' => 'pending', 'notes' => 'طلب توثيق حساب المورد.', 'review_notes' => null, 'reviewed_by_user_id' => null, 'reviewed_at' => null, 'documents' => json_encode(['supplier_license.pdf']), 'created_at' => $now->copy()->subDays(1), 'updated_at' => $now],
        ]);
        DB::table('articles')->insert([
            ['slug' => 'kaifa-tudir-talabatak', 'title' => 'How to manage your B2B orders efficiently', 'title_ar' => 'كيف تدير طلباتك بكفاءة', 'excerpt' => 'Tips for order management', 'excerpt_ar' => 'نصائح لإدارة الطلبات والتوريد بفعالية.', 'content' => '<p>Managing orders at scale...</p>', 'content_ar' => '<p>إدارة الطلبات على نطاق واسع تتطلب أدوات ذكية وتنظيماً دقيقاً...</p>', 'published' => 1, 'published_at' => $now->copy()->subDays(10), 'created_by' => $this->admin, 'created_at' => $now->copy()->subDays(10), 'updated_at' => $now],
            ['slug' => 'ikhtiyar-almuwarid', 'title' => 'Choosing the right supplier', 'title_ar' => 'اختيار المورد المناسب', 'excerpt' => 'Supplier selection guide', 'excerpt_ar' => 'دليلك لاختيار أفضل الموردين لعملك.', 'content' => '<p>The right supplier...</p>', 'content_ar' => '<p>اختيار المورد المناسب هو حجر الأساس لنجاح أي عمل تجاري...</p>', 'published' => 1, 'published_at' => $now->copy()->subDays(4), 'created_by' => $this->admin, 'created_at' => $now->copy()->subDays(4), 'updated_at' => $now],
        ]);
        $notif = function ($type, $userId, $data, $read = false) use ($now) {
            DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => $type, 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => $userId, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'read_at' => $read ? $now : null, 'created_at' => $now->copy()->subHours(rand(1, 40)), 'updated_at' => $now]);
        };
        $notif('App\\Notifications\\OrderCreated', $this->supplier, ['title' => 'طلب جديد', 'message' => 'تم تعيين طلب جديد ORD-1006 إليك.']);
        $notif('App\\Notifications\\QuotationApproved', $this->supplier, ['title' => 'تم قبول عرضك', 'message' => 'وافق التاجر على عرض السعر للطلب ORD-1009.'], true);
        $notif('App\\Notifications\\QuotationCreated', $this->merchant, ['title' => 'عرض سعر جديد', 'message' => 'قدّم المورد عرض سعر للطلب ORD-1002.']);
        $notif('App\\Notifications\\DeliveryUpdatedNotification', $this->merchant, ['title' => 'تحديث الشحن', 'message' => 'شحنتك ORD-1006 في الطريق إليك.']);
        $notif('App\\Notifications\\UserCreated', $this->admin, ['title' => 'تسجيل جديد', 'message' => 'تاجر جديد بانتظار الموافقة.']);
        DB::table('activity_logs')->insert([
            ['actor_user_id' => $this->merchant, 'action' => 'order.create', 'event_type' => 'user_action', 'channel' => 'http', 'level' => 'info', 'subject_type' => 'App\\Models\\Order', 'subject_id' => $orderIds[0], 'properties' => json_encode(['code' => 'ORD-1001']), 'ip_address' => '196.221.0.10', 'created_at' => $now->copy()->subHours(6)],
            ['actor_user_id' => $this->supplier, 'action' => 'quotation.create', 'event_type' => 'user_action', 'channel' => 'http', 'level' => 'info', 'subject_type' => 'App\\Models\\OrderQuotations', 'subject_id' => 1, 'properties' => json_encode(['price' => 1200]), 'ip_address' => '212.118.0.20', 'created_at' => $now->copy()->subHours(5)],
            ['actor_user_id' => null, 'action' => 'payment.webhook.failed', 'event_type' => 'system', 'channel' => 'webhook', 'level' => 'error', 'subject_type' => null, 'subject_id' => null, 'properties' => json_encode(['reason' => 'invalid signature']), 'ip_address' => '0.0.0.0', 'created_at' => $now->copy()->subHours(2)],
            ['actor_user_id' => $this->admin, 'action' => 'user.approve', 'event_type' => 'user_action', 'channel' => 'http', 'level' => 'info', 'subject_type' => 'App\\Models\\User', 'subject_id' => $extraMerchant, 'properties' => json_encode([]), 'ip_address' => '41.65.0.5', 'created_at' => $now->copy()->subHours(1)],
        ]);
        foreach ([$this->admin, $this->merchant, $this->supplier] as $uid) {
            DB::table('login_histories')->insert(['user_id' => $uid, 'login_method' => 'password', 'ip_address' => '196.221.0.' . $uid, 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'created_at' => $now->copy()->subHours(rand(1, 12))]);
        }
        DB::table('email_logs')->insert([
            ['to' => 'supplier@demo.com', 'subject' => 'تم تعيين طلب جديد', 'template_key' => 'supplier_order_assigned', 'status' => 'sent', 'error_message' => null, 'created_at' => $now->copy()->subHours(6)],
            ['to' => 'customer@demo.com', 'subject' => 'عرض سعر جديد على طلبك', 'template_key' => 'quotation_created', 'status' => 'sent', 'error_message' => null, 'created_at' => $now->copy()->subHours(5)],
            ['to' => 'pending@demo.com', 'subject' => 'مرحباً بك في سيل‌تشيس', 'template_key' => 'user_created', 'status' => 'failed', 'error_message' => 'Mailbox unavailable', 'created_at' => $now->copy()->subHours(3)],
        ]);

        // ---------------------------------------------------------------
        // 12) Wavex (WhatsApp)
        // ---------------------------------------------------------------
        $tpl = DB::table('wavex_templates')->insertGetId(['user_id' => $this->merchant, 'name' => 'عرض ترويجي', 'body' => 'مرحباً {name}، عرض خاص لك اليوم!', 'body_html' => '<p>مرحباً {name}، عرض خاص لك اليوم! خصم 20% على جميع المنتجات.</p>', 'created_at' => $now, 'updated_at' => $now]);
        $grp = DB::table('wavex_contact_groups')->insertGetId(['user_id' => $this->merchant, 'name' => 'عملاء الرياض', 'description' => 'قائمة عملاء منطقة الرياض', 'created_at' => $now, 'updated_at' => $now]);
        $members = [['+966550000010', 'أحمد'], ['+966550000011', 'سارة'], ['+966550000012', 'فيصل'], ['+966550000013', 'ليان']];
        foreach ($members as $mi => $m) {
            DB::table('wavex_contact_group_members')->insert(['contact_group_id' => $grp, 'phone' => $m[0], 'display_name' => $m[1], 'sort_order' => $mi, 'created_at' => $now, 'updated_at' => $now]);
        }
        $camp = DB::table('wavex_campaigns')->insertGetId(['user_id' => $this->merchant, 'name' => 'حملة تخفيضات الصيف', 'template_id' => $tpl, 'contact_group_id' => $grp, 'message_body' => 'مرحباً، عرض خاص لك اليوم! خصم 20%.', 'delay_seconds' => 5, 'status' => 'completed', 'total_recipients' => 4, 'sent_count' => 3, 'queued_count' => 0, 'failed_count' => 1, 'started_at' => $now->copy()->subHours(3), 'completed_at' => $now->copy()->subHours(2), 'created_at' => $now->copy()->subHours(4), 'updated_at' => $now]);
        foreach ($members as $mi => $m) {
            DB::table('wavex_campaign_recipients')->insert(['campaign_id' => $camp, 'sort_order' => $mi, 'phone' => $m[0], 'display_name' => $m[1], 'status' => $mi === 3 ? 'failed' : 'sent', 'error_message' => $mi === 3 ? 'الرقم غير مسجل على واتساب' : null, 'sent_at' => $mi === 3 ? null : $now->copy()->subHours(2), 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('wavex_inbox_messages')->insert([
            ['instance_id' => 'demo-instance', 'chat_id' => '966550000010@c.us', 'direction' => 'in', 'body' => 'هل العرض ما زال متاحاً؟', 'raw' => json_encode(['type' => 'text']), 'message_at' => $now->copy()->subHours(1), 'created_at' => $now, 'updated_at' => $now],
            ['instance_id' => 'demo-instance', 'chat_id' => '966550000010@c.us', 'direction' => 'out', 'body' => 'نعم، العرض متاح حتى نهاية الأسبوع.', 'raw' => json_encode(['type' => 'text']), 'message_at' => $now->copy()->subMinutes(50), 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->command->info('Seeded: '
            . DB::table('users')->count() . ' users ('
            . DB::table('users')->where('parent_user_id', '!=', null)->whereNotNull('parent_user_id')->count() . ' staff/employees), '
            . DB::table('products')->count() . ' products, '
            . DB::table('bundles')->count() . ' bundles, '
            . DB::table('orders')->count() . ' orders.');
    }

    private function upsertProfile(int $userId, string $username, string $company, string $country, string $city, string $bio, string $tagline, string $phone, Carbon $now): void
    {
        DB::table('profiles')->updateOrInsert(['user_id' => $userId], [
            'username' => $username, 'biography' => $bio, 'company' => $company, 'country' => $country,
            'city' => $city, 'address' => $city, 'phone' => $phone, 'whatsapp' => $phone, 'tagline' => $tagline,
            'gender' => 'male', 'website' => 'https://' . $username . '.example.com',
            'social_media' => json_encode(['instagram' => '@' . $username, 'whatsapp' => $phone], JSON_UNESCAPED_UNICODE),
            'is_public' => 1, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function insertProfile(int $userId, string $username, string $company, string $country, string $city, string $bio, string $tagline, string $phone, Carbon $now, int $isPublic = 1): void
    {
        DB::table('profiles')->insert([
            'username' => $username, 'biography' => $bio, 'company' => $company, 'country' => $country,
            'city' => $city, 'address' => $city, 'phone' => $phone, 'whatsapp' => $phone, 'tagline' => $tagline,
            'website' => 'https://' . $username . '.example.com', 'gender' => 'male',
            'social_media' => json_encode(['instagram' => '@' . $username, 'whatsapp' => $phone], JSON_UNESCAPED_UNICODE),
            'is_public' => $isPublic, 'active' => 1, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
