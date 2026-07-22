<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreMenu;
use App\Services\PageBuilder\StoreMenuService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Renders the transactional / account storefront pages (cart, checkout, order
 * success, login, register, wishlist, compare) through the active theme's shared
 * layout, with MOCK data for design/style testing. These are presentational
 * (static) previews — the real cart/checkout/auth flows are API-driven and wired
 * separately. Runs inside the `resolve.store` group so CurrentStore is set and the
 * catalog queries below are tenant-scoped.
 */
class StorefrontStaticController extends Controller
{
    public function __construct(private readonly StoreMenuService $menus) {}

    public function login(Request $r): View
    {
        return $this->page($r, 'login', 'تسجيل الدخول', ['chrome' => false, 'container' => false]);
    }

    public function register(Request $r): View
    {
        return $this->page($r, 'register', 'إنشاء حساب', ['chrome' => false, 'container' => false]);
    }

    public function cart(Request $r): View
    {
        $s = $this->store($r);

        return $this->page($r, 'cart', 'سلة التسوق', ['items' => $this->mock(3), 'currency' => $s->currency]);
    }

    public function checkout(Request $r): View
    {
        $s = $this->store($r);

        return $this->page($r, 'checkout', 'إتمام الشراء', ['items' => $this->mock(3), 'currency' => $s->currency]);
    }

    public function orderSuccess(Request $r): View
    {
        $s = $this->store($r);

        return $this->page($r, 'order-success', 'تم استلام الطلب', ['items' => $this->mock(4), 'currency' => $s->currency]);
    }

    public function wishlist(Request $r): View
    {
        $s = $this->store($r);

        return $this->page($r, 'wishlist', 'قائمة الرغبات', ['items' => $this->mock(5), 'currency' => $s->currency]);
    }

    public function compare(Request $r): View
    {
        $s = $this->store($r);

        return $this->page($r, 'compare', 'مقارنة المنتجات', ['items' => $this->mock(3), 'currency' => $s->currency]);
    }

    // ---- Account module (Part 5) --------------------------------------------

    public function forgotPassword(Request $r): View
    {
        return $this->page($r, 'forgot-password', 'استعادة كلمة المرور', ['chrome' => false, 'container' => false]);
    }

    public function dashboard(Request $r): View
    {
        $s = $this->store($r);

        return $this->account($r, 'dashboard', 'لوحة حسابي', ['orders' => $this->mockOrders(), 'wishlist' => $this->mock(4), 'currency' => $s->currency]);
    }

    public function orders(Request $r): View
    {
        $s = $this->store($r);

        return $this->account($r, 'orders', 'طلباتي', ['orders' => $this->mockOrders(), 'currency' => $s->currency]);
    }

    public function orderDetails(Request $r): View
    {
        $s = $this->store($r);

        return $this->account($r, 'order-details', 'تفاصيل الطلب', ['items' => $this->mock(3), 'currency' => $s->currency]);
    }

    public function addresses(Request $r): View
    {
        return $this->account($r, 'addresses', 'العناوين', []);
    }

    public function reviews(Request $r): View
    {
        $s = $this->store($r);

        return $this->account($r, 'reviews', 'تقييماتي', ['items' => $this->mock(3), 'currency' => $s->currency]);
    }

    public function profile(Request $r): View
    {
        return $this->account($r, 'profile', 'الملف الشخصي', []);
    }

    public function settings(Request $r): View
    {
        return $this->account($r, 'settings', 'الإعدادات', []);
    }

    // ---- Info / legal / utility (Part 6) ------------------------------------

    public function contact(Request $r): View
    {
        return $this->page($r, 'contact', 'اتصل بنا', []);
    }

    public function about(Request $r): View
    {
        return $this->page($r, 'about', 'من نحن', []);
    }

    public function faq(Request $r): View
    {
        return $this->page($r, 'faq', 'الأسئلة الشائعة', []);
    }

    public function legal(Request $r, string $doc): View
    {
        $titles = ['privacy' => 'سياسة الخصوصية', 'terms' => 'الشروط والأحكام', 'shipping' => 'سياسة الشحن', 'returns' => 'سياسة الاسترجاع'];

        return $this->page($r, 'legal', $titles[$doc] ?? 'صفحة', ['doc' => $doc, 'docTitle' => $titles[$doc] ?? 'صفحة']);
    }

    public function search(Request $r): View
    {
        $s = $this->store($r);
        $q = trim((string) $r->query('q', ''));

        return $this->page($r, 'search', $q !== '' ? 'نتائج: '.$q : 'بحث', ['q' => $q, 'items' => $q !== '' ? $this->mock(6) : [], 'currency' => $s->currency]);
    }

    public function notFound(Request $r): View
    {
        return $this->page($r, 'not-found', 'الصفحة غير موجودة', []);
    }

    public function maintenance(Request $r): View
    {
        return $this->page($r, 'maintenance', 'صيانة', ['chrome' => false, 'container' => false]);
    }

    public function comingSoon(Request $r): View
    {
        return $this->page($r, 'coming-soon', 'قريباً', ['chrome' => false, 'container' => false]);
    }

    private function store(Request $r): Store
    {
        return $r->attributes->get('store');
    }

    /** @return array<string,mixed> */
    private function context(Store $store, string $title): array
    {
        $menus = StoreMenu::query()
            ->whereIn('handle', ['header', 'footer'])
            ->get()->keyBy('handle');

        return [
            'store' => [
                'name' => $store->name,
                'description' => $store->description,
                'email' => $store->email,
                'phone' => $store->phone,
                'currency' => $store->currency,
            ],
            'theme' => [
                'key' => 'modern',
                'version' => '1.0.0',
                'settings' => is_array($store->theme_settings) ? $store->theme_settings : [],
            ],
            'navigation' => [
                'header' => ($h = $menus->get('header')) ? $this->menus->tree($h) : [],
                'footer' => ($f = $menus->get('footer')) ? $this->menus->tree($f) : [],
            ],
            'seo' => ['title' => $title.' — '.$store->name],
        ];
    }

    /** @return array<int,array<string,mixed>> mock product lines for design testing */
    private function mock(int $n): array
    {
        return Product::query()
            ->where('is_active', true)
            ->orderByDesc('id')->limit($n)->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'price' => $p->price,
                'compare_price' => $p->compare_price,
                'image_url' => $p->imageUrl(),
            ])->all();
    }

    /** @return array<int,array<string,mixed>> mock orders for account previews */
    private function mockOrders(): array
    {
        return [
            ['number' => 'SC-992841', 'date' => '٢٥ أكتوبر ٢٠٢٥', 'status' => 'shipped', 'label' => 'تم الشحن', 'total' => '2,011', 'thumbs' => $this->thumbs(3)],
            ['number' => 'SC-992712', 'date' => '١٢ أكتوبر ٢٠٢٥', 'status' => 'delivered', 'label' => 'تم التسليم', 'total' => '845', 'thumbs' => $this->thumbs(2)],
            ['number' => 'SC-991980', 'date' => '٣ أكتوبر ٢٠٢٥', 'status' => 'processing', 'label' => 'قيد التجهيز', 'total' => '1,299', 'thumbs' => $this->thumbs(4)],
        ];
    }

    /** @return array<int,?string> */
    private function thumbs(int $n): array
    {
        return array_map(fn (array $p) => $p['image_url'], $this->mock($n));
    }

    /** @param array<string,mixed> $extra Renders an account-shell page (sidebar + content). */
    private function account(Request $r, string $view, string $title, array $extra): View
    {
        $store = $this->store($r);

        return view("storefront.themes.modern.pages.account.{$view}", array_merge(
            ['context' => $this->context($store, $title), 'active' => $view],
            $extra,
        ));
    }

    /** @param array<string,mixed> $extra */
    private function page(Request $r, string $view, string $title, array $extra): View
    {
        $store = $this->store($r);

        return view("storefront.themes.modern.pages.{$view}", array_merge(
            ['context' => $this->context($store, $title)],
            $extra,
        ));
    }
}
