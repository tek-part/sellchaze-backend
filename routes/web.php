<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\InvitationsController;
use App\Http\Controllers\Storefront\StorefrontPageController;
use App\Http\Controllers\Storefront\StorefrontStaticController;
use App\Http\Controllers\Storefront\ThemeBundleController;
use Illuminate\Support\Facades\Route;

/*
| Phase 3.5 (Task 2): host-agnostic public storefront (server-rendered).
| Store resolution is entirely host-based (ResolveStoreFromHost) — NO subdomain
| assumption — so the same layer serves nike.sellchase.com and future custom
| domains like nike.com. "/" serves the store homepage on a resolved host, or
| the app welcome on the main domain; other storefront paths 404 on unknown hosts.
*/
Route::middleware(['resolve.store', 'storefront.locale'])->group(function () {
    Route::get('/theme-bundles/{version}/{checksum}.js', [ThemeBundleController::class, 'show'])
        ->whereNumber('version')->where('checksum', '[a-f0-9]{64}');
    Route::get('/', [StorefrontPageController::class, 'root']);
    Route::get('/products', [StorefrontPageController::class, 'products']);
    Route::get('/products/{slug}', [StorefrontPageController::class, 'product'])->where('slug', '[a-z0-9\-]+');
    Route::get('/categories/{slug}', [StorefrontPageController::class, 'category'])->where('slug', '[a-z0-9\-]+');
    Route::get('/pages/{slug}', [StorefrontPageController::class, 'page'])->where('slug', '[a-z0-9\-]+');

    // Transactional / account storefront pages (static previews with mock data for
    // design/style testing — real flows are API-driven). Auth previews live under
    // /account/* to avoid colliding with the platform "/login" SPA redirect below.
    Route::get('/cart', [StorefrontStaticController::class, 'cart']);
    Route::get('/checkout', [StorefrontStaticController::class, 'checkout']);
    Route::get('/checkout/success', [StorefrontStaticController::class, 'orderSuccess']);
    Route::get('/wishlist', [StorefrontStaticController::class, 'wishlist']);
    Route::get('/compare', [StorefrontStaticController::class, 'compare']);
    Route::get('/account/login', [StorefrontStaticController::class, 'login']);
    Route::get('/account/register', [StorefrontStaticController::class, 'register']);
    Route::get('/forgot-password', [StorefrontStaticController::class, 'forgotPassword']);
    // Account module
    Route::get('/account', [StorefrontStaticController::class, 'dashboard']);
    Route::get('/account/orders', [StorefrontStaticController::class, 'orders']);
    Route::get('/account/orders/{number}', [StorefrontStaticController::class, 'orderDetails'])->where('number', '[A-Za-z0-9\-]+');
    Route::get('/account/addresses', [StorefrontStaticController::class, 'addresses']);
    Route::get('/account/reviews', [StorefrontStaticController::class, 'reviews']);
    Route::get('/account/profile', [StorefrontStaticController::class, 'profile']);
    Route::get('/account/settings', [StorefrontStaticController::class, 'settings']);
    // Info / legal / utility
    Route::get('/contact', [StorefrontStaticController::class, 'contact']);
    Route::get('/about', [StorefrontStaticController::class, 'about']);
    Route::get('/faq', [StorefrontStaticController::class, 'faq']);
    Route::get('/legal/{doc}', [StorefrontStaticController::class, 'legal'])->where('doc', 'privacy|terms|shipping|returns');
    Route::get('/search', [StorefrontStaticController::class, 'search']);
    Route::get('/not-found', [StorefrontStaticController::class, 'notFound']);
    Route::get('/maintenance', [StorefrontStaticController::class, 'maintenance']);
    Route::get('/coming-soon', [StorefrontStaticController::class, 'comingSoon']);

    Route::get('/sitemap.xml', [StorefrontPageController::class, 'sitemap']);
    Route::get('/robots.txt', [StorefrontPageController::class, 'robots']);
});

/*
|--------------------------------------------------------------------------
| Web — API backend only. The React SPA lives in sellchase-ui/ (Vite).
| Legacy Blade UI controllers/views remain in the repo but are not routed here.
|--------------------------------------------------------------------------
*/

Route::get('login', function () {
    $front = rtrim((string) config('sellchase.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

    return redirect()->away($front.'/login');
})->name('login');

/** Legacy Blade layouts (e.g. Growtech 404) link here; keeps session locale for optional SetLocale middleware. */
Route::get('locale/{locale}', function (string $locale) {
    if (! in_array($locale, ['ar', 'en'], true)) {
        abort(404);
    }
    session(['locale' => $locale]);

    return redirect()->back(fallback: url('/'));
})->name('locale.switch')->where('locale', 'ar|en');

Route::get('auth/google', [GoogleAuthController::class, 'redirect'])->middleware('throttle:60,1')->name('google.redirect');
Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:60,1')->name('google.callback');

/** Legacy Blade (Growtech/Rizz): invitation request form + listing; guests are sent to SPA login via auth middleware. */
Route::middleware('auth')->group(function () {
    Route::get('register/request', [RegisteredUserController::class, 'requestInvitation'])->name('requestInvitation');
    Route::post('invitations', [InvitationsController::class, 'store'])->name('storeInvitation');
    Route::get('invitations', [InvitationsController::class, 'index'])->name('invitations.index');
});

// "/" is handled host-agnostically by StorefrontPageController@root above:
// resolved store -> storefront homepage; main domain -> app welcome JSON.
