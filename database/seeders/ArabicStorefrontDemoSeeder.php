<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\CustomerAddress;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreMenu;
use App\Models\StoreMenuItem;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreOrderStatusChange;
use App\Models\StorePage;
use App\Models\StorePageSection;
use App\Models\StoreProductVariant;
use App\Models\User;
use App\Models\WishlistItem;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Arabic STOREFRONT demo data for the two demo owners:
 *   Merchant  customer@demo.com  (#2)  -> "متجر أصالة"        (EGP)
 *   Supplier  supplier@demo.com  (#3)  -> "مؤسسة نور للتوريدات" (SAR)
 *
 * Fills every storefront table each store owns: categories, products, variants,
 * customers, addresses, coupons, orders (+items +status timeline), reviews,
 * wishlist, CMS pages (+sections) and header/footer menus. Re-runnable: it wipes
 * each owner's storefront rows first, then reseeds. Complements ArabicDemoSeeder
 * (which covers the legacy B2B domain).
 */
class ArabicStorefrontDemoSeeder extends Seeder
{
    public function run(): void
    {
        $merchant = User::where('email', 'customer@demo.com')->first();
        $supplier = User::where('email', 'supplier@demo.com')->first();
        if (! $merchant || ! $supplier) {
            $this->command->warn('Demo merchant/supplier missing — run UsersSeeder first.');

            return;
        }

        $this->seedStore($merchant, [
            'name' => 'متجر أصالة',
            'slug' => 'asala-store',
            'description' => 'وجهتك الأولى للإلكترونيات والأزياء والمنتجات المنزلية بأسعار منافسة وجودة مضمونة.',
            'currency' => 'EGP',
            'email' => 'hello@asala.example',
            'phone' => '+20 100 123 4567',
            'owner_type' => 'merchant',
            'cities' => ['القاهرة', 'الجيزة', 'الإسكندرية', 'المنصورة'],
        ]);

        $this->seedStore($supplier, [
            'name' => 'مؤسسة نور للتوريدات',
            'slug' => 'noor-supplies',
            'description' => 'مورد جملة موثوق لمستلزمات المتاجر والإلكترونيات والإكسسوارات في المنطقة.',
            'currency' => 'SAR',
            'email' => 'sales@noor-supplies.example',
            'phone' => '+966 55 987 6543',
            'owner_type' => 'supplier',
            'cities' => ['الرياض', 'جدة', 'الدمام', 'مكة المكرمة'],
        ]);

        $this->command->info('Arabic storefront demo seeded for متجر أصالة (merchant) و مؤسسة نور للتوريدات (supplier).');
    }

    private function seedStore(User $owner, array $cfg): void
    {
        $store = Store::query()->where('owner_user_id', $owner->id)->first();
        if (! $store) {
            $store = Store::create([
                'owner_user_id' => $owner->id,
                'owner_type' => $cfg['owner_type'],
                'name' => $cfg['name'],
                'slug' => $cfg['slug'],
                'currency' => $cfg['currency'],
                'status' => 'active',
            ]);
        }

        // Refresh branding + activate.
        $store->forceFill([
            'name' => $cfg['name'],
            'description' => $cfg['description'],
            'currency' => $cfg['currency'],
            'email' => $cfg['email'],
            'phone' => $cfg['phone'],
            'owner_type' => $cfg['owner_type'],
            'status' => 'active',
        ])->save();

        $this->clearStorefront($store->id);
        app(CurrentStore::class)->set($store);

        $cats = $this->seedCategories($store);
        $products = $this->seedProducts($store, $cats, $cfg['currency']);
        $customers = $this->seedCustomers($store, $cfg['cities']);
        $this->seedCoupons($store);
        $this->seedOrders($store, $customers, $products, $cfg['currency']);
        $this->seedReviews($store, $customers, $products);
        $this->seedWishlist($store, $customers, $products);
        $this->seedPages($store, $cfg);
        $this->seedMenus($store);
    }

    private function clearStorefront(int $storeId): void
    {
        foreach ([
            'product_reviews', 'wishlist_items', 'coupon_usages',
            'store_order_status_changes', 'store_order_items', 'store_orders',
            'cart_items', 'carts', 'coupons',
            'customer_addresses', 'store_customer_tokens', 'store_customers',
            'store_product_variants', 'products', 'categories',
            'store_page_sections', 'store_page_revisions', 'store_pages',
            'store_menu_items', 'store_menus',
        ] as $table) {
            DB::table($table)->where('store_id', $storeId)->delete();
        }
    }

    /** @return array<string,Category> keyed by Arabic name */
    private function seedCategories(Store $store): array
    {
        $names = ['إلكترونيات', 'أزياء رجالية', 'أزياء نسائية', 'منزل ومطبخ', 'العناية والجمال', 'إكسسوارات'];
        $out = [];
        foreach ($names as $i => $name) {
            $out[$name] = Category::create([
                'store_id' => $store->id,
                'name' => $name,
                'slug' => 'cat-'.($i + 1),
                'description' => 'تشكيلة مختارة من '.$name.'.',
                'is_active' => true,
                'position' => $i,
            ]);
        }

        return $out;
    }

    /** @return Product[] */
    private function seedProducts(Store $store, array $cats, string $cur): array
    {
        // [name, category, price, compare, featured, description]
        $pool = [
            ['سماعة بلوتوث لاسلكية', 'إلكترونيات', 349, 499, true, 'سماعة أذن لاسلكية بجودة صوت نقية وعمر بطارية يدوم طوال اليوم.'],
            ['ساعة ذكية رياضية', 'إلكترونيات', 899, 1200, true, 'ساعة ذكية لتتبع اللياقة والنوم مع شاشة أموليد ومقاومة للماء.'],
            ['شاحن سريع 65 واط', 'إلكترونيات', 220, 300, false, 'شاحن جداري سريع يدعم شحن الأجهزة المتعددة بأمان.'],
            ['قميص قطني كلاسيكي', 'أزياء رجالية', 260, 340, false, 'قميص رجالي من القطن الخالص بقصّة أنيقة ومريحة.'],
            ['حذاء رياضي خفيف', 'أزياء رجالية', 540, 700, true, 'حذاء رياضي بنعل مريح مناسب للجري والمشي اليومي.'],
            ['فستان صيفي مطرز', 'أزياء نسائية', 480, 620, true, 'فستان نسائي بتطريز يدوي وخامة قطنية منعشة للصيف.'],
            ['حقيبة يد أنيقة', 'أزياء نسائية', 610, 800, false, 'حقيبة يد عصرية بجلد صناعي فاخر ومساحة عملية.'],
            ['طقم أواني طهي', 'منزل ومطبخ', 720, 950, false, 'طقم أواني غير لاصقة مقاوم للخدش وموزّع للحرارة بالتساوي.'],
            ['خلاط كهربائي متعدد', 'منزل ومطبخ', 430, 560, false, 'خلاط قوي بسرعات متعددة لعصائرك ووصفاتك اليومية.'],
            ['كريم ترطيب طبيعي', 'العناية والجمال', 150, 210, false, 'كريم مرطب بمكونات طبيعية يمنح البشرة نعومة تدوم.'],
            ['عطر شرقي فاخر', 'العناية والجمال', 390, 520, true, 'عطر شرقي بمزيج من العود والمسك يدوم طويلاً.'],
            ['نظارة شمسية عصرية', 'إكسسوارات', 280, 360, false, 'نظارة شمسية بحماية من الأشعة فوق البنفسجية وتصميم أنيق.'],
        ];

        $out = [];
        foreach ($pool as $i => $p) {
            [$name, $cat, $price, $compare, $featured, $desc] = $p;
            $product = Product::create([
                'store_id' => $store->id,
                'category_id' => $cats[$cat]->id ?? null,
                'name' => $name,
                'slug' => 'prod-'.($i + 1),
                'sku' => 'SKU-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'description' => $desc,
                'short_description' => Str::limit($desc, 90),
                'price' => $price,
                'compare_price' => $compare,
                'is_active' => true,
                'is_featured' => $featured,
                'position' => $i,
            ]);

            // Variants for a few products (size / colour in Arabic).
            if (in_array($cat, ['أزياء رجالية', 'أزياء نسائية'], true)) {
                foreach ([['مقاس S', 0], ['مقاس M', 15], ['مقاس L', 30]] as $vi => $v) {
                    StoreProductVariant::create([
                        'store_id' => $store->id,
                        'store_product_id' => $product->id,
                        'name' => $v[0],
                        'sku' => $product->sku.'-'.($vi + 1),
                        'price_override' => $price + $v[1],
                        'options' => ['المقاس' => $v[0]],
                        'is_active' => true,
                        'position' => $vi,
                    ]);
                }
            }
            $out[] = $product;
        }

        return $out;
    }

    /** @return StoreCustomer[] */
    private function seedCustomers(Store $store, array $cities): array
    {
        $people = [
            ['أحمد المصري', 'ahmed', '+20 111 222 3344'],
            ['فاطمة الزهراء', 'fatima', '+20 122 333 4455'],
            ['محمد عبدالله', 'mohamed', '+966 50 111 2222'],
            ['سارة خالد', 'sara', '+966 55 333 4444'],
            ['ليلى حسن', 'laila', '+20 100 555 6677'],
        ];
        $out = [];
        foreach ($people as $i => $c) {
            $customer = StoreCustomer::create([
                'store_id' => $store->id,
                'name' => $c[0],
                'email' => $c[1].'.'.$store->id.'@demo.example',
                'password' => Hash::make('12345678'),
                'phone' => $c[2],
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            CustomerAddress::create([
                'store_id' => $store->id,
                'store_customer_id' => $customer->id,
                'label' => 'المنزل',
                'name' => $c[0],
                'phone' => $c[2],
                'line1' => 'شارع '.($i + 10).' - حي النخيل',
                'line2' => 'بجوار المسجد الكبير',
                'city' => $cities[$i % count($cities)],
                'state' => $cities[$i % count($cities)],
                'country' => 'EG',
                'postal_code' => (string) (10000 + $i * 111),
                'is_default' => true,
            ]);
            $out[] = $customer;
        }

        return $out;
    }

    private function seedCoupons(Store $store): void
    {
        $coupons = [
            ['AHLAN10', 'percentage', 10, 100, 'خصم ترحيبي 10٪ لأول طلب'],
            ['SHHN50', 'fixed', 50, 300, 'خصم 50 على الطلبات فوق 300'],
            ['EID25', 'percentage', 25, 200, 'عرض العيد — خصم 25٪'],
        ];
        foreach ($coupons as $c) {
            Coupon::create([
                'store_id' => $store->id,
                'code' => $c[0],
                'type' => $c[1],
                'value' => $c[2],
                'minimum_order_amount' => $c[3],
                'starts_at' => now()->subDays(10),
                'expires_at' => now()->addDays(30),
                'max_uses' => 100,
                'max_uses_per_customer' => 1,
                'is_active' => true,
            ]);
        }
    }

    private function seedOrders(Store $store, array $customers, array $products, string $cur): void
    {
        $statuses = ['delivered', 'delivered', 'shipped', 'processing', 'confirmed', 'pending', 'cancelled'];
        $flow = [
            'delivered' => ['pending', 'confirmed', 'processing', 'shipped', 'delivered'],
            'shipped' => ['pending', 'confirmed', 'processing', 'shipped'],
            'processing' => ['pending', 'confirmed', 'processing'],
            'confirmed' => ['pending', 'confirmed'],
            'pending' => ['pending'],
            'cancelled' => ['pending', 'cancelled'],
        ];

        foreach ($statuses as $n => $status) {
            $customer = $customers[$n % count($customers)];
            $items = [];
            $subtotal = '0.00';
            $count = 1 + ($n % 3);
            for ($k = 0; $k < $count; $k++) {
                $p = $products[($n + $k) % count($products)];
                $qty = 1 + (($n + $k) % 2);
                $line = bcmul((string) $p->price, (string) $qty, 2);
                $subtotal = bcadd($subtotal, $line, 2);
                $items[] = [$p, $qty, $line];
            }
            $discount = $n === 0 ? bcdiv(bcmul($subtotal, '10', 4), '100', 2) : '0.00';
            $shipping = $status === 'cancelled' ? '0.00' : '30.00';
            $grand = bcsub(bcadd($subtotal, $shipping, 2), $discount, 2);
            $placedAt = now()->subDays(20 - $n * 2);

            $order = StoreOrder::create([
                'store_id' => $store->id,
                'store_customer_id' => $customer->id,
                'order_number' => 'ORD-'.$placedAt->format('Ymd').'-'.str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT),
                'status' => $status,
                'currency' => $cur,
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'customer_phone' => $customer->phone,
                'shipping_address' => [
                    'name' => $customer->name,
                    'line1' => 'شارع '.($n + 10).' - حي النخيل',
                    'city' => 'القاهرة',
                    'country' => 'EG',
                ],
                'subtotal' => $subtotal,
                'shipping_total' => $shipping,
                'discount_total' => $discount,
                'grand_total' => $grand,
                'notes' => $n % 2 === 0 ? 'يرجى التوصيل مساءً بعد الساعة السادسة.' : null,
                'placed_at' => $placedAt,
                'cancelled_at' => $status === 'cancelled' ? $placedAt->copy()->addDay() : null,
            ]);

            foreach ($items as [$p, $qty, $line]) {
                StoreOrderItem::create([
                    'store_id' => $store->id,
                    'store_order_id' => $order->id,
                    'store_product_id' => $p->id,
                    'name' => $p->name,
                    'unit_price' => $p->price,
                    'quantity' => $qty,
                    'line_total' => $line,
                ]);
            }

            // Status timeline.
            $prev = null;
            foreach ($flow[$status] as $s) {
                StoreOrderStatusChange::create([
                    'store_id' => $store->id,
                    'store_order_id' => $order->id,
                    'from_status' => $prev,
                    'to_status' => $s,
                    'actor_id' => $s === 'cancelled' ? null : $store->owner_user_id,
                    'notes' => $s === 'shipped' ? 'تم شحن الطلب عبر شركة الشحن.' : null,
                ]);
                $prev = $s;
            }
        }
    }

    private function seedReviews(Store $store, array $customers, array $products): void
    {
        $texts = [
            [5, 'منتج ممتاز', 'جودة رائعة وتغليف احترافي، أنصح بالشراء بشدة.'],
            [4, 'جيد جداً', 'المنتج مطابق للوصف والتوصيل كان سريعاً.'],
            [5, 'تجربة موفقة', 'سعر مناسب وخدمة عملاء متعاونة، شكراً لكم.'],
            [4, 'راضٍ عن الشراء', 'الخامة جيدة لكن اللون مختلف قليلاً عن الصورة.'],
        ];
        foreach ($texts as $i => $r) {
            ProductReview::create([
                'store_id' => $store->id,
                'store_customer_id' => $customers[$i % count($customers)]->id,
                'store_product_id' => $products[$i]->id,
                'rating' => $r[0],
                'title' => $r[1],
                'body' => $r[2],
                'status' => 'approved',
            ]);
        }
    }

    private function seedWishlist(Store $store, array $customers, array $products): void
    {
        foreach ($customers as $i => $customer) {
            foreach ([0, 1] as $j) {
                $p = $products[($i + $j) % count($products)];
                WishlistItem::firstOrCreate([
                    'store_customer_id' => $customer->id,
                    'store_product_id' => $p->id,
                ], ['store_id' => $store->id]);
            }
        }
    }

    private function seedPages(Store $store, array $cfg): void
    {
        $pages = [
            ['من نحن', 'about', [
                ['hero', ['title' => 'من نحن', 'subtitle' => $cfg['name'].' — شغفنا خدمتك']],
                ['rich_text', ['html' => '<p>نحن في '.$cfg['name'].' نلتزم بتقديم منتجات أصلية بأفضل الأسعار مع خدمة توصيل سريعة وضمان على جميع المنتجات.</p>']],
            ]],
            ['سياسة الشحن والاستبدال', 'shipping-policy', [
                ['rich_text', ['html' => '<h2>الشحن</h2><p>نوفّر الشحن لجميع المدن خلال ٢ إلى ٥ أيام عمل.</p><h2>الاستبدال</h2><p>يمكنك استبدال المنتج خلال ١٤ يوماً من الاستلام.</p>']],
            ]],
            ['اتصل بنا', 'contact', [
                ['contact', ['email' => $cfg['email'], 'phone' => $cfg['phone'], 'title' => 'تواصل معنا']],
            ]],
        ];

        foreach ($pages as $pi => $pg) {
            $page = StorePage::create([
                'store_id' => $store->id,
                'title' => $pg[0],
                'slug' => $pg[1],
                'status' => 'published',
                'template' => 'page',
                'locale' => 'ar',
                'seo' => ['title' => $pg[0].' | '.$cfg['name'], 'description' => 'صفحة '.$pg[0]],
                'published_at' => now()->subDays(5),
            ]);
            foreach ($pg[2] as $si => $sec) {
                StorePageSection::create([
                    'store_page_id' => $page->id,
                    'store_id' => $store->id,
                    'type' => $sec[0],
                    'settings' => $sec[1],
                    'position' => $si,
                ]);
            }
        }
    }

    private function seedMenus(Store $store): void
    {
        $header = StoreMenu::create(['store_id' => $store->id, 'handle' => 'header', 'name' => 'القائمة الرئيسية']);
        foreach ([
            ['الرئيسية', 'url', '/'],
            ['المنتجات', 'url', '/products'],
            ['من نحن', 'internal', 'about'],
            ['اتصل بنا', 'internal', 'contact'],
        ] as $i => $it) {
            StoreMenuItem::create([
                'store_menu_id' => $header->id,
                'store_id' => $store->id,
                'label' => $it[0],
                'type' => $it[1],
                'target' => $it[2],
                'position' => $i,
            ]);
        }

        $footer = StoreMenu::create(['store_id' => $store->id, 'handle' => 'footer', 'name' => 'قائمة التذييل']);
        foreach ([
            ['سياسة الشحن والاستبدال', 'internal', 'shipping-policy'],
            ['الأسئلة الشائعة', 'url', '/faq'],
        ] as $i => $it) {
            StoreMenuItem::create([
                'store_menu_id' => $footer->id,
                'store_id' => $store->id,
                'label' => $it[0],
                'type' => $it[1],
                'target' => $it[2],
                'position' => $i,
            ]);
        }
    }
}
