<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\InvitationsController;
use App\Http\Controllers\Storefront\StorefrontPageController;
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
    // Transactional / account pages (cart, checkout, account, …) are rendered by the
    // React storefront SPA; the legacy Blade "modern" previews were removed with the
    // legacy theme shells.

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

// Any other GET on a resolved tenant host is a React-storefront route (cart, checkout,
// account, search, …). Registered as a fallback so it never shadows the app-host routes.
Route::fallback([StorefrontPageController::class, 'spa'])->middleware(['resolve.store', 'storefront.locale']);

// "/" is handled host-agnostically by StorefrontPageController@root above:
// resolved store -> storefront homepage; main domain -> app welcome JSON.
