<?php

use App\Http\Controllers\Api\ActivityLogsApiController;
use App\Http\Controllers\Api\AdminDashboardApiController;
use App\Http\Controllers\Api\AdminModerationController;
use App\Http\Controllers\Api\AdminReportsApiController;
use App\Http\Controllers\Api\AdminSupportTicketsController;
use App\Http\Controllers\Api\AdminThemesController;
use App\Http\Controllers\Api\ArticlesApiController;
use App\Http\Controllers\Api\AttributesApiController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BostaWebhookController;
use App\Http\Controllers\Api\BundlesApiController;
use App\Http\Controllers\Api\CategoriesApiController;
use App\Http\Controllers\Api\ChatApiController;
use App\Http\Controllers\Api\CommunityDirectoryController;
use App\Http\Controllers\Api\CommunityGroupController;
use App\Http\Controllers\Api\CommunityMediaUploadController;
use App\Http\Controllers\Api\CommunityReelController;
use App\Http\Controllers\Api\CommunitySearchController;
use App\Http\Controllers\Api\CouponsApiController;
use App\Http\Controllers\Api\CurrencySettingsApiController;
use App\Http\Controllers\Api\DomainMetricsApiController;
use App\Http\Controllers\Api\EmailLogsApiController;
use App\Http\Controllers\Api\EmailSettingsApiController;
use App\Http\Controllers\Api\EmailTemplatesApiController;
use App\Http\Controllers\Api\EmployeesApiController;
use App\Http\Controllers\Api\ExternalStoreOrdersController;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\FeedEventController;
use App\Http\Controllers\Api\FollowController;
use App\Http\Controllers\Api\GoogleMerchantController;
use App\Http\Controllers\Api\GoogleSettingsApiController;
use App\Http\Controllers\Api\HostedPaymentReturnController;
use App\Http\Controllers\Api\ImpersonationApiController;
use App\Http\Controllers\Api\InventoryApiController;
use App\Http\Controllers\Api\InvoicesApiController;
use App\Http\Controllers\Api\ManualStoreOrderController;
use App\Http\Controllers\Api\MarketplaceThemesController;
use App\Http\Controllers\Api\MerchantBulkPaymentController;
use App\Http\Controllers\Api\MerchantOrderActionsController;
use App\Http\Controllers\Api\MerchantOrderController;
use App\Http\Controllers\Api\MerchantOrderEditController;
use App\Http\Controllers\Api\MerchantOrderPushController;
use App\Http\Controllers\Api\MerchantOrderRefundController;
use App\Http\Controllers\Api\MerchantPaymentProofController;
use App\Http\Controllers\Api\MerchantReviewController;
use App\Http\Controllers\Api\MerchantsApiController;
use App\Http\Controllers\Api\MerchantSubscriptionsController;
use App\Http\Controllers\Api\MeSectorController;
use App\Http\Controllers\Api\MonitoringApiController;
use App\Http\Controllers\Api\NotificationsApiController;
use App\Http\Controllers\Api\OrganizationFollowController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\PlatformHealthController;
use App\Http\Controllers\Api\PlatformWalletController;
use App\Http\Controllers\Api\PostCommentController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ProductsApiController;
use App\Http\Controllers\Api\PublicContactApiController;
use App\Http\Controllers\Api\PublicProfileApiController;
use App\Http\Controllers\Api\RolesApiController;
use App\Http\Controllers\Api\ServiceMarketplaceController;
use App\Http\Controllers\Api\SettingsSummaryApiController;
use App\Http\Controllers\Api\SocialSafetyController;
use App\Http\Controllers\Api\StockTransfersApiController;
use App\Http\Controllers\Api\StoreAbTestsController;
use App\Http\Controllers\Api\StoreAnalyticsController;
use App\Http\Controllers\Api\StoreApiKeysController;
use App\Http\Controllers\Api\StoreAppsController;
use App\Http\Controllers\Api\StoreAppSettingsController;
use App\Http\Controllers\Api\StoreArticlesApiController;
use App\Http\Controllers\Api\StoreBotProtectionController;
use App\Http\Controllers\Api\StoreCarrierController;
use App\Http\Controllers\Api\StoreCatalogTransferController;
use App\Http\Controllers\Api\StoreCategoriesApiController;
use App\Http\Controllers\Api\StoreCheckoutFieldsController;
use App\Http\Controllers\Api\StoreCollectionsApiController;
use App\Http\Controllers\Api\StoreContentPagesApiController;
use App\Http\Controllers\Api\StoreCustomersApiController;
use App\Http\Controllers\Api\StoreDashboardGuideController;
use App\Http\Controllers\Api\StoreDashboardMetricsController;
use App\Http\Controllers\Api\StoreDesignAssetsController;
use App\Http\Controllers\Api\StoreDigitalDeliveryController;
use App\Http\Controllers\Api\StoreDomainsApiController;
use App\Http\Controllers\Api\StoreDownsellController;
use App\Http\Controllers\Api\StoreDraftOrderController;
use App\Http\Controllers\Api\Storefront\AbTestController;
use App\Http\Controllers\Api\Storefront\BotVerificationController;
use App\Http\Controllers\Api\Storefront\CartController;
use App\Http\Controllers\Api\Storefront\CheckoutController;
use App\Http\Controllers\Api\Storefront\CheckoutLeadController;
use App\Http\Controllers\Api\Storefront\CustomerAddressController;
use App\Http\Controllers\Api\Storefront\CustomerAuthController;
use App\Http\Controllers\Api\Storefront\DownsellController;
use App\Http\Controllers\Api\Storefront\PaymentProofController;
use App\Http\Controllers\Api\Storefront\PersonalizationUploadController;
use App\Http\Controllers\Api\Storefront\PhoneVerificationController;
use App\Http\Controllers\Api\Storefront\ProductFeedController;
use App\Http\Controllers\Api\Storefront\ProductReviewController;
use App\Http\Controllers\Api\Storefront\StorefrontAppsController;
use App\Http\Controllers\Api\Storefront\StorefrontBrandController;
use App\Http\Controllers\Api\Storefront\StorefrontCategoryController;
use App\Http\Controllers\Api\Storefront\StorefrontCollectionController;
use App\Http\Controllers\Api\Storefront\StorefrontController;
use App\Http\Controllers\Api\Storefront\StorefrontCouponController;
use App\Http\Controllers\Api\Storefront\StorefrontEngagementController;
use App\Http\Controllers\Api\Storefront\StorefrontEventsController;
use App\Http\Controllers\Api\Storefront\StorefrontInvoiceController;
use App\Http\Controllers\Api\Storefront\StorefrontLayoutController;
use App\Http\Controllers\Api\Storefront\StorefrontProductController;
use App\Http\Controllers\Api\Storefront\StorefrontThankYouController;
use App\Http\Controllers\Api\Storefront\StoreOrderController;
use App\Http\Controllers\Api\Storefront\TrackingEventsController;
use App\Http\Controllers\Api\Storefront\WishlistController;
use App\Http\Controllers\Api\StorefrontContextController;
use App\Http\Controllers\Api\StorefrontProductsApiController;
use App\Http\Controllers\Api\StorefrontProductVariantsApiController;
use App\Http\Controllers\Api\StoreFunnelsApiController;
use App\Http\Controllers\Api\StoreGeneralSettingsController;
use App\Http\Controllers\Api\StoreGiftCardsController;
use App\Http\Controllers\Api\StoreHeadCodeController;
use App\Http\Controllers\Api\StoreInventoryController;
use App\Http\Controllers\Api\StoreManagersController;
use App\Http\Controllers\Api\StoreMediaApiController;
use App\Http\Controllers\Api\StoreMenusApiController;
use App\Http\Controllers\Api\StoreMissedOrdersController;
use App\Http\Controllers\Api\StoreNewsletterController;
use App\Http\Controllers\Api\StoreNotificationsController;
use App\Http\Controllers\Api\StoreOrderExportController;
use App\Http\Controllers\Api\StoreOrderLimitsController;
use App\Http\Controllers\Api\StoreOrderWebhookController;
use App\Http\Controllers\Api\StorePagesApiController;
use App\Http\Controllers\Api\StorePaymentsApiController;
use App\Http\Controllers\Api\StorePhoneBlockController;
use App\Http\Controllers\Api\StorePhoneVerificationController;
use App\Http\Controllers\Api\StoreProductAiController;
use App\Http\Controllers\Api\StoreProductLandingController;
use App\Http\Controllers\Api\StorePublishingApiController;
use App\Http\Controllers\Api\StoreQuantityOffersController;
use App\Http\Controllers\Api\StoreReferralController;
use App\Http\Controllers\Api\StoreReusableSectionsApiController;
use App\Http\Controllers\Api\StoresApiController;
use App\Http\Controllers\Api\StoreSheetsController;
use App\Http\Controllers\Api\StoreShipmentController;
use App\Http\Controllers\Api\StoreShippingController;
use App\Http\Controllers\Api\StoreShippingZonesController;
use App\Http\Controllers\Api\StoreShoppingPreferencesController;
use App\Http\Controllers\Api\StoreSimplePagesController;
use App\Http\Controllers\Api\StoreSocialLinksController;
use App\Http\Controllers\Api\StoreThankYouController;
use App\Http\Controllers\Api\StoreThemesApiController;
use App\Http\Controllers\Api\StoreTrackingController;
use App\Http\Controllers\Api\StoreUiBlocksController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SupportTicketsController;
use App\Http\Controllers\Api\TableViewsController;
use App\Http\Controllers\Api\ThemeLicensePurchaseController;
use App\Http\Controllers\Api\ThemeLicenseWebhookController;
use App\Http\Controllers\Api\UsersApiController;
use App\Http\Controllers\Api\V2\OrganizationController;
use App\Http\Controllers\Api\V2\OrganizationDiscoveryController;
use App\Http\Controllers\Api\V2\OrganizationMembershipController;
use App\Http\Controllers\Api\V2\OrganizationStoreController;
use App\Http\Controllers\Api\V2\OrganizationStorePublishingController;
use App\Http\Controllers\Api\V2\OrganizationSubscriptionController;
use App\Http\Controllers\Api\V2\PlanCatalogController;
use App\Http\Controllers\Api\VerificationsApiController;
use App\Http\Controllers\Api\WarehousesApiController;
use App\Http\Controllers\Api\Wavex\WavexCampaignsApiController;
use App\Http\Controllers\Api\Wavex\WavexChatsApiController;
use App\Http\Controllers\Api\Wavex\WavexContactGroupsApiController;
use App\Http\Controllers\Api\Wavex\WavexGroupsApiController;
use App\Http\Controllers\Api\Wavex\WavexInboxApiController;
use App\Http\Controllers\Api\Wavex\WavexMediaPickupController;
use App\Http\Controllers\Api\Wavex\WavexSessionApiController;
use App\Http\Controllers\Api\Wavex\WavexSettingsApiController;
use App\Http\Controllers\Api\Wavex\WavexTemplatesApiController;
use App\Http\Controllers\Api\Wavex\WavexWebhookController;
use App\Http\Controllers\Api\XpayPaymentReturnController;
use App\Http\Controllers\Storefront\ReferralLinkController;
use App\Http\Middleware\AuthenticateStoreApiKey;
use App\Http\Middleware\IdempotentCheckout;
use App\Http\Middleware\ResolveOwnStore;
use App\Services\PageBuilder\StorePageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API — Sellchase SPA (JWT) + legacy machine endpoints
|--------------------------------------------------------------------------
*/

Route::get('/health/live', [PlatformHealthController::class, 'live'])
    ->middleware('throttle:60,1');
Route::get('/health/ready', [PlatformHealthController::class, 'ready'])
    ->middleware('throttle:60,1');

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| API v2 — organization-scoped contracts
|--------------------------------------------------------------------------
|
| v1 remains available during the incremental migration. Every tenant-owned
| v2 resource is nested under an organization so authorization cannot rely on
| a user-supplied organization_id in the request body.
|
*/
Route::prefix('v2')->middleware(['jwt.auth', 'pending.restrict'])->group(function () {
    Route::get('plans', PlanCatalogController::class);
    Route::get('organizations', [OrganizationController::class, 'index']);
    Route::get('directory/organizations', [OrganizationDiscoveryController::class, 'index']);
    Route::get('directory/organizations/{organization:slug}', [OrganizationDiscoveryController::class, 'show']);
    Route::post('organizations', [OrganizationController::class, 'store']);
    Route::get('organizations/{organization}', [OrganizationController::class, 'show']);
    Route::patch('organizations/{organization}', [OrganizationController::class, 'update']);
    Route::post('organizations/{organization}/posts', [PostController::class, 'store'])
        ->name('v2.organization.posts.store')->middleware(['scope.organization.route', 'throttle:20,1']);

    Route::get('organizations/{organization}/memberships', [OrganizationMembershipController::class, 'index']);
    Route::post('organizations/{organization}/memberships', [OrganizationMembershipController::class, 'store']);
    Route::patch('organizations/{organization}/memberships/{membership}', [OrganizationMembershipController::class, 'update']);
    Route::delete('organizations/{organization}/memberships/{membership}', [OrganizationMembershipController::class, 'destroy']);

    Route::get('organizations/{organization}/stores', [OrganizationStoreController::class, 'index']);
    Route::post('organizations/{organization}/stores', [OrganizationStoreController::class, 'store']);
    Route::get('organizations/{organization}/stores/{store}/readiness', [OrganizationStorePublishingController::class, 'readiness']);
    Route::post('organizations/{organization}/stores/{store}/publish', [OrganizationStorePublishingController::class, 'publish']);
    Route::post('organizations/{organization}/stores/{store}/unpublish', [OrganizationStorePublishingController::class, 'unpublish']);
    Route::middleware(['scope.organization.route', 'store.scope'])->prefix('organizations/{organization}/stores/{store}')->group(function () {
        Route::get('themes', [StoreThemesApiController::class, 'index']);
        Route::post('themes/install', [StoreThemesApiController::class, 'install']);
        Route::post('themes/activate', [StoreThemesApiController::class, 'activate']);
        Route::post('themes/rollback', [StoreThemesApiController::class, 'rollback']);
        Route::put('themes/settings', [StoreThemesApiController::class, 'settings']);
        Route::post('themes/publish', [StoreThemesApiController::class, 'publish']);
        Route::put('themes/custom-css', [StoreThemesApiController::class, 'customCss']);
        Route::get('media', [StoreMediaApiController::class, 'index']);
        Route::post('media', [StoreMediaApiController::class, 'store']);
        Route::patch('media/{asset}', [StoreMediaApiController::class, 'update'])->whereNumber('asset');
        Route::delete('media/{asset}', [StoreMediaApiController::class, 'destroy'])->whereNumber('asset');
        Route::get('page-drafts', [StorePagesApiController::class, 'index']);
        Route::post('page-drafts', [StorePagesApiController::class, 'store']);
        Route::get('page-drafts/schema', [StorePagesApiController::class, 'schema']);
        Route::get('page-drafts/{page}', [StorePagesApiController::class, 'show']);
        Route::put('page-drafts/{page}', [StorePagesApiController::class, 'update']);
        Route::put('page-drafts/{page}/sections', [StorePagesApiController::class, 'syncSections']);
        Route::post('page-drafts/{page}/preview', [StorePagesApiController::class, 'preview']);
        Route::post('page-drafts/{page}/publications', [StorePagesApiController::class, 'publish']);
        Route::get('page-drafts/{page}/revisions', [StorePagesApiController::class, 'revisions']);
        Route::post('page-drafts/{page}/revisions/{revision}/restore', [StorePagesApiController::class, 'restoreRevision']);
    });
    Route::get('organizations/{organization}/subscription', [OrganizationSubscriptionController::class, 'show']);
    Route::post('organizations/{organization}/subscription', [OrganizationSubscriptionController::class, 'store']);
});

Route::prefix('v1')->group(function () {
    Route::post('/wavex/webhook', WavexWebhookController::class);
    Route::post('/carriers/bosta/webhook/{reference}', BostaWebhookController::class)->where('reference', 'sc-[a-fA-F0-9-]{36}')->middleware('throttle:240,1');

    Route::get('/wavex/media-pickup/{token}', [WavexMediaPickupController::class, 'show'])
        ->middleware('signed')
        ->name('wavex.media-pickup');

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:60,1');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
    Route::get('/auth/google-config', [AuthController::class, 'googleConfig']);
    Route::post('/auth/google', [AuthController::class, 'google'])->middleware('throttle:60,1');
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    // Public (unauthenticated) endpoints for marketing pages and shareable profiles.
    Route::get('/public/profile/{username}', [PublicProfileApiController::class, 'show']);
    Route::post('/public/contact', [PublicContactApiController::class, 'store'])->middleware('throttle:60,1');

    // Public Blog API (unauthenticated).
    Route::get('/public/articles', [ArticlesApiController::class, 'publicIndex']);
    Route::get('/public/articles/feed.xml', [ArticlesApiController::class, 'publicFeed']);
    Route::get('/public/articles/{slug}', [ArticlesApiController::class, 'publicShow'])
        ->where('slug', '[A-Za-z0-9\-_]+');

    // Phase 2: resolve the current Host to a store (subdomain-based). Storefront
    // rendering itself is Phase 3 — this only proves/exposes host resolution.
    Route::middleware(['resolve.store', 'storefront.locale', 'throttle:storefront-read'])->get('/storefront/resolve', StorefrontContextController::class);
    Route::post('/payment-webhooks/{store}/{gateway}', [PaymentWebhookController::class, 'handle'])
        ->whereNumber('store')->whereIn('gateway', ['stripe', 'paypal', 'tabby', 'tamara', 'paymob', 'tap', 'fawaterak', 'paytabs', 'fawry']);
    Route::post('/platform-wallet/fawaterak_json', [PlatformWalletController::class, 'fawaterak'])->middleware('throttle:120,1');
    Route::get('/service-marketplace/media/{provider}/{file}', [ServiceMarketplaceController::class, 'media'])->whereUuid('provider')->middleware('throttle:storefront-read');
    Route::post('/payment-webhooks/{store}/fawaterak_json', [PaymentWebhookController::class, 'fawaterak'])
        ->whereNumber('store');
    Route::get('/payment-returns/hyperpay/{transaction}', [PaymentWebhookController::class, 'hyperpayReturn']);
    Route::get('/payment-returns/hosted/{transaction}', HostedPaymentReturnController::class)->middleware('throttle:60,1')->name('payments.hosted.return');
    Route::post('/payment-notifications/hosted/{transaction}', HostedPaymentReturnController::class)->middleware('throttle:60,1')->name('payments.hosted.notify');
    Route::match(['get', 'post'], '/payment-returns/xpay/{store}', XpayPaymentReturnController::class)->whereNumber('store')->middleware('throttle:30,1')->name('payments.xpay.return');
    Route::get('/payment-sessions/hyperpay/{transaction}', [PaymentWebhookController::class, 'hyperpaySession'])->name('payments.hyperpay.session');
    Route::get('/payment-sessions/fawry/{transaction}', [PaymentWebhookController::class, 'fawrySession'])->name('payments.fawry.session');
    Route::match(['get', 'post'], '/payment-returns/fawry/{transaction}', [PaymentWebhookController::class, 'fawryReturn']);

    Route::get('personalization-files/{store}/{upload}', [PersonalizationUploadController::class, 'show'])->whereNumber('store')->whereUuid('upload')->middleware(['signed:relative', 'throttle:60,1'])->name('personalization.download');

    // Phase 3: public storefront API (host-resolved, store-scoped, read-only).
    Route::middleware(['resolve.store', 'storefront.locale', 'throttle:storefront-read'])->prefix('storefront')->group(function () {
        Route::get('/', [StorefrontController::class, 'index']);
        Route::get('home', [StorefrontController::class, 'home']);
        Route::get('context', [StorefrontController::class, 'context']);
        Route::get('products', [StorefrontProductController::class, 'index']);
        Route::post('cart/catalog', [StorefrontProductController::class, 'cartCatalog']);
        Route::get('products/{slug}', [StorefrontProductController::class, 'show'])->where('slug', '[a-z0-9\-]+');
        Route::get('referral-products/{product}', [ReferralLinkController::class, 'resolve'])->whereNumber('product');
        Route::post('ab-tests/{slug}/resolve', [AbTestController::class, 'resolve'])->where('slug', '[a-z0-9\-]+');
        Route::get('categories', [StorefrontCategoryController::class, 'index']);
        Route::get('categories/{slug}', [StorefrontCategoryController::class, 'show'])->where('slug', '[a-z0-9\-]+');

        // ---- Merchandising surfaces (collections / brands / active coupons) ----
        Route::get('collections', [StorefrontCollectionController::class, 'index']);
        Route::get('collections/{slug}', [StorefrontCollectionController::class, 'show'])->where('slug', '[a-z0-9\-]+');
        Route::get('brands', [StorefrontBrandController::class, 'index']);
        Route::get('coupons', [StorefrontCouponController::class, 'index']);
        Route::get('downsell', [DownsellController::class, 'index']);
        Route::get('social-proof', [StorefrontAppsController::class, 'socialProof']);
        Route::get('bundles', [StorefrontAppsController::class, 'bundles']);

        // ---- Engagement capture (newsletter opt-in / contact form) — guest-friendly ----
        Route::post('newsletter', [StorefrontEngagementController::class, 'subscribe']);
        Route::post('checkout-leads', [CheckoutLeadController::class, 'store'])->middleware('throttle:30,1');
        Route::post('events', [StorefrontEventsController::class, 'store'])->middleware('throttle:90,1');
        Route::get('tracking', [TrackingEventsController::class, 'configuration']);
        Route::get('product-feed/{channel}', [ProductFeedController::class, 'show'])->where('channel', 'google|meta|snapchat|tiktok')->middleware('throttle:10,1');
        Route::post('tracking/events', [TrackingEventsController::class, 'store'])->middleware('throttle:90,1');
        Route::post('contact', [StorefrontEngagementController::class, 'contact']);

        // ---- Editable system pages (about/contact/faq/shipping/returns/blog) ----
        Route::get('content/{key}', [StoreContentPagesApiController::class, 'storefront'])->where('key', '[a-z0-9\-]+');

        // ---- Theme customizer: published section layouts for the SPA (home template + custom pages) ----
        Route::get('layout', [StorefrontLayoutController::class, 'layout']);
        Route::get('pages/{slug}', [StorefrontLayoutController::class, 'page'])->where('slug', '[a-z0-9\-]+');
        Route::get('funnels/{slug}', [StorefrontLayoutController::class, 'funnel'])->where('slug', '[a-z0-9\-]+');
        Route::get('thank-you', [StorefrontThankYouController::class, 'show']);
        Route::get('ui-blocks', [StoreUiBlocksController::class, 'publicConfiguration']);
        Route::get('design-assets', [StoreDesignAssetsController::class, 'manifest']);
        Route::get('design-assets/{file}', [StoreDesignAssetsController::class, 'content'])->where('file', '[a-f0-9-]{36}\.(css|js)');

        // ---- Phase 6: public product reviews (approved only + average summary) ----
        Route::get('products/{slug}/reviews', [ProductReviewController::class, 'index'])->where('slug', '[a-z0-9\-]+');

        // ---- Phase 5: Storefront Commerce ----
        // Cart + checkout are guest-friendly (X-Cart-Token header); auth is optional.
        Route::get('cart', [CartController::class, 'show']);
        Route::post('products/{product}/personalization-image', [PersonalizationUploadController::class, 'store'])->whereNumber('product')->middleware('throttle:5,1');
        Route::post('cart/items', [CartController::class, 'addItem']);
        Route::patch('cart/items/{item}', [CartController::class, 'updateItem'])->whereNumber('item');
        Route::delete('cart/items/{item}', [CartController::class, 'removeItem'])->whereNumber('item');
        Route::delete('cart', [CartController::class, 'clear']);
        Route::get('payment-methods', [CheckoutController::class, 'paymentMethods']);
        Route::post('checkout', [CheckoutController::class, 'store'])->middleware(IdempotentCheckout::class);
        Route::post('checkout/recover', [CheckoutController::class, 'recover']);
        Route::get('checkout/fields', [CheckoutController::class, 'fields']);
        Route::post('checkout/phone/send', [PhoneVerificationController::class, 'send'])->middleware('throttle:10,1');
        Route::post('checkout/phone/verify', [PhoneVerificationController::class, 'verify'])->middleware('throttle:30,1');
        Route::post('checkout/bot/verify', BotVerificationController::class)->middleware('throttle:10,1');
        Route::post('checkout/quote', [CheckoutController::class, 'quote']);
        Route::post('checkout/gift-card/check', [CheckoutController::class, 'checkGiftCard'])->middleware('throttle:10,1');
        Route::post('checkout/payment/retry', [CheckoutController::class, 'retryPayment']);
        // Draft-order invoices: the random token is the capability; foreign/expired tokens 404.
        Route::get('invoices/{token}', [StorefrontInvoiceController::class, 'show'])->where('token', '[A-Za-z0-9]{1,128}')->middleware('throttle:60,1');
        Route::post('invoices/{token}/checkout', [StorefrontInvoiceController::class, 'checkout'])->where('token', '[A-Za-z0-9]{1,128}')->middleware('throttle:10,1');
        Route::post('checkout/receipt/upload', PaymentProofController::class)->middleware('throttle:6,1');
        Route::post('checkout/receipt', [CheckoutController::class, 'receipt'])->middleware('throttle:30,1');

        // ---- Phase 6D: coupon apply/remove on the current cart (guest-friendly) ----
        Route::post('checkout/coupon/apply', [CheckoutController::class, 'applyCoupon']);
        Route::delete('checkout/coupon', [CheckoutController::class, 'removeCoupon']);

        Route::post('auth/register', [CustomerAuthController::class, 'register']);
        Route::post('auth/login', [CustomerAuthController::class, 'login'])->middleware('throttle:login');
        Route::post('auth/forgot-password', [CustomerAuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
        Route::post('auth/reset-password', [CustomerAuthController::class, 'resetPassword'])->middleware('throttle:10,1');

        // Authenticated store-customer area (bearer token, store-scoped).
        Route::middleware('store.customer')->group(function () {
            Route::post('auth/logout', [CustomerAuthController::class, 'logout']);
            Route::get('account', [CustomerAuthController::class, 'me']);
            Route::patch('account', [CustomerAuthController::class, 'update']);
            Route::get('account/addresses', [CustomerAddressController::class, 'index']);
            Route::post('account/addresses', [CustomerAddressController::class, 'store']);
            Route::put('account/addresses/{address}', [CustomerAddressController::class, 'update'])->whereNumber('address');
            Route::delete('account/addresses/{address}', [CustomerAddressController::class, 'destroy'])->whereNumber('address');
            Route::get('orders', [StoreOrderController::class, 'index']);
            Route::get('orders/{number}', [StoreOrderController::class, 'show'])->where('number', '[A-Za-z0-9\-]+');
            Route::post('orders/{number}/cancel', [StoreOrderController::class, 'cancel'])->where('number', '[A-Za-z0-9\-]+');
            Route::post('orders/{number}/returns', [StoreOrderController::class, 'requestReturn'])->where('number', '[A-Za-z0-9\-]+')->middleware('throttle:10,1');

            // ---- Phase 6: account password, wishlist, review authoring ----
            Route::put('account/password', [CustomerAuthController::class, 'changePassword']);
            Route::get('wishlist', [WishlistController::class, 'index']);
            Route::post('wishlist', [WishlistController::class, 'store']);
            Route::delete('wishlist/{product}', [WishlistController::class, 'destroy'])->whereNumber('product');
            Route::post('products/{slug}/reviews', [ProductReviewController::class, 'store'])->where('slug', '[a-z0-9\-]+');
            Route::put('products/{slug}/reviews/{review}', [ProductReviewController::class, 'update'])->where('slug', '[a-z0-9\-]+')->whereNumber('review');
            Route::delete('products/{slug}/reviews/{review}', [ProductReviewController::class, 'destroy'])->where('slug', '[a-z0-9\-]+')->whereNumber('review');
        });
    });

    // Isolated machine credentials: never accept profile/global keys or JWTs here.
    Route::prefix('external-apps')->withoutMiddleware('throttle:api')->group(function () {
        $key = fn (string $permission) => AuthenticateStoreApiKey::class.':'.$permission;
        Route::get('products', [StorefrontProductsApiController::class, 'index'])->middleware($key('products:read'));
        Route::post('products', [StorefrontProductsApiController::class, 'store'])->middleware($key('products:create'));
        Route::get('products/{product}', [StorefrontProductsApiController::class, 'show'])->whereNumber('product')->middleware($key('products:read'));
        Route::match(['put', 'patch'], 'products/{product}', [StorefrontProductsApiController::class, 'update'])->whereNumber('product')->middleware($key('products:update'));
        Route::delete('products/{product}', [StorefrontProductsApiController::class, 'destroy'])->whereNumber('product')->middleware($key('products:delete'));
        Route::get('categories', [StoreCategoriesApiController::class, 'index'])->middleware($key('categories:read'));
        Route::post('categories', [StoreCategoriesApiController::class, 'store'])->middleware($key('categories:create'));
        Route::get('categories/{category}', [StoreCategoriesApiController::class, 'show'])->whereNumber('category')->middleware($key('categories:read'));
        Route::match(['put', 'patch'], 'categories/{category}', [StoreCategoriesApiController::class, 'update'])->whereNumber('category')->middleware($key('categories:update'));
        Route::delete('categories/{category}', [StoreCategoriesApiController::class, 'destroy'])->whereNumber('category')->middleware($key('categories:delete'));
        Route::get('orders', [MerchantOrderController::class, 'index'])->middleware($key('orders:read'));
        Route::get('orders/short-id/{number}', [ExternalStoreOrdersController::class, 'byNumber'])->where('number', '[A-Za-z0-9\-]+')->middleware($key('orders:read'));
        Route::get('orders/{order}', [MerchantOrderController::class, 'show'])->whereNumber('order')->middleware($key('orders:read'));
        Route::match(['put', 'patch', 'post'], 'orders/{order}/status', [MerchantOrderController::class, 'updateStatus'])->whereNumber('order')->middleware($key('orders:update'));
        Route::post('orders/{order}/notes', [MerchantOrderController::class, 'addNote'])->whereNumber('order')->middleware($key('orders:update'));
        Route::delete('orders/{order}', [ExternalStoreOrdersController::class, 'destroy'])->whereNumber('order')->middleware($key('orders:delete'));
        Route::get('shipping-areas', [StoreShippingController::class, 'index'])->middleware($key('shipping_areas'));
        Route::put('shipping-areas', [StoreShippingController::class, 'update'])->middleware($key('shipping_areas'));
        Route::delete('webhooks/delete-by-url', [ExternalStoreOrdersController::class, 'deleteWebhook'])->middleware($key('orders:delete'));
    });

    Route::middleware(['jwt.auth', 'pending.restrict'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::get('/me/platform-wallet', [PlatformWalletController::class, 'wallet'])->middleware('throttle:tenant-read');
        Route::get('/me/merchant-subscription', [MerchantSubscriptionsController::class, 'show'])->middleware('throttle:tenant-read');
        Route::post('/me/merchant-subscription', [MerchantSubscriptionsController::class, 'purchase'])->middleware('throttle:10,1');
        Route::put('/me/merchant-subscription/preferences', [MerchantSubscriptionsController::class, 'preferences'])->middleware('throttle:10,1');
        Route::post('/me/platform-wallet/fund', [PlatformWalletController::class, 'fund'])->middleware('throttle:20,1');
        Route::post('/me/platform-wallet/payments/{payment}/refresh', [PlatformWalletController::class, 'refreshPayment'])->whereUuid('payment')->middleware('throttle:20,1');
        Route::get('/me/platform-wallet/entries', [PlatformWalletController::class, 'entries'])->middleware('throttle:tenant-read');
        Route::get('/me/platform-referral', [PlatformWalletController::class, 'referral'])->middleware('throttle:tenant-read');
        Route::post('/me/platform-referral', [PlatformWalletController::class, 'createReferral'])->middleware('throttle:20,1');
        Route::post('/admin/platform-wallet/reconcile', [PlatformWalletController::class, 'reconcile'])->middleware('throttle:20,1');
        Route::get('/service-marketplace', [ServiceMarketplaceController::class, 'index'])->middleware('throttle:tenant-read');
        Route::get('/service-marketplace/providers/{provider}', [ServiceMarketplaceController::class, 'provider'])->whereUuid('provider')->middleware('throttle:tenant-read');
        Route::get('/service-marketplace/services/{service}', [ServiceMarketplaceController::class, 'show'])->whereUuid('service')->middleware('throttle:tenant-read');
        Route::put('/service-marketplace/services/{service}/rating', [ServiceMarketplaceController::class, 'rate'])->whereUuid('service')->middleware('throttle:20,1');
        Route::get('/me/service-marketplace', [ServiceMarketplaceController::class, 'mine'])->middleware('throttle:tenant-read');
        Route::put('/me/service-marketplace/provider', [ServiceMarketplaceController::class, 'saveProvider'])->middleware('throttle:20,1');
        Route::post('/me/service-marketplace/images', [ServiceMarketplaceController::class, 'upload'])->middleware('throttle:20,1');
        Route::get('/me/service-marketplace/media/{file}', [ServiceMarketplaceController::class, 'privateMedia'])->middleware('throttle:tenant-read');
        Route::post('/me/service-marketplace/services', [ServiceMarketplaceController::class, 'store'])->middleware('throttle:20,1');
        Route::put('/me/service-marketplace/services/{service}', [ServiceMarketplaceController::class, 'update'])->whereUuid('service')->middleware('throttle:20,1');
        Route::delete('/me/service-marketplace/services/{service}', [ServiceMarketplaceController::class, 'destroy'])->whereUuid('service')->middleware('throttle:20,1');
        Route::patch('/auth/me', [AuthController::class, 'updateMe']);
        Route::put('/auth/password', [AuthController::class, 'changePassword']);
        Route::post('/auth/me/avatar', [AuthController::class, 'uploadAvatar']);
        Route::delete('/auth/me/avatar', [AuthController::class, 'deleteAvatar']);
        Route::post('/auth/me/cover', [AuthController::class, 'uploadCover']);
        Route::delete('/auth/me/cover', [AuthController::class, 'deleteCover']);
        Route::post('/auth/google/connect', [AuthController::class, 'connectGoogle']);
        Route::delete('/auth/google/disconnect', [AuthController::class, 'disconnectGoogle']);
        Route::get('/auth/login-history', [AuthController::class, 'loginHistory']);
        Route::get('/auth/sessions', [AuthController::class, 'sessions']);
        Route::delete('/auth/sessions/others', [AuthController::class, 'revokeOtherSessions']);
        Route::delete('/auth/sessions/{sessionId}', [AuthController::class, 'revokeSession']);

        Route::get('/notifications', [NotificationsApiController::class, 'index'])->middleware('throttle:tenant-read');
        Route::post('/notifications/{id}/read', [NotificationsApiController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationsApiController::class, 'markAllRead']);

        // ---- Saved views for dashboard index tables (private to the user) ----
        Route::get('/table-views', [TableViewsController::class, 'index'])->middleware('throttle:tenant-read');
        Route::post('/table-views', [TableViewsController::class, 'store'])->middleware('throttle:30,1');
        Route::patch('/table-views/{view}', [TableViewsController::class, 'update'])->whereNumber('view')->middleware('throttle:30,1');
        Route::delete('/table-views/{view}', [TableViewsController::class, 'destroy'])->whereNumber('view')->middleware('throttle:30,1');

        // ---- Community feed / wall (all registered users) ----
        Route::get('/feed', [FeedController::class, 'index'])->middleware('throttle:tenant-read');
        Route::get('/reels', [CommunityReelController::class, 'index'])->middleware('throttle:tenant-read');
        Route::post('/feed/events', [FeedEventController::class, 'batch'])->middleware('throttle:120,1');
        Route::post('/posts', [PostController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/posts/{post}', [PostController::class, 'show'])->whereNumber('post');
        Route::patch('/posts/{post}', [PostController::class, 'update'])->whereNumber('post')->middleware('throttle:30,1');
        Route::delete('/posts/{post}', [PostController::class, 'destroy'])->whereNumber('post');
        Route::get('/posts/{post}/reactions', [PostController::class, 'reactions'])->whereNumber('post')->middleware('throttle:tenant-read');
        Route::post('/posts/{post}/like', [PostController::class, 'like'])->whereNumber('post');
        Route::delete('/posts/{post}/like', [PostController::class, 'unlike'])->whereNumber('post');
        Route::post('/posts/{post}/share', [PostController::class, 'share'])->whereNumber('post');
        Route::post('/posts/{post}/save', [PostController::class, 'save'])->whereNumber('post');
        Route::delete('/posts/{post}/save', [PostController::class, 'unsave'])->whereNumber('post');
        Route::post('/posts/{post}/reaction', [PostController::class, 'react'])->whereNumber('post');
        Route::delete('/posts/{post}/reaction', [PostController::class, 'unreact'])->whereNumber('post');
        Route::get('/posts/{post}/comments', [PostCommentController::class, 'index'])->whereNumber('post');
        Route::post('/posts/{post}/comments', [PostCommentController::class, 'store'])->whereNumber('post')->middleware('throttle:60,1');
        Route::patch('/posts/{post}/comments/{comment}', [PostCommentController::class, 'update'])
            ->whereNumber('post')->whereNumber('comment')->middleware('throttle:60,1');
        Route::delete('/posts/{post}/comments/{comment}', [PostCommentController::class, 'destroy'])
            ->whereNumber('post')->whereNumber('comment');
        Route::get('/users/{user}/followers', [FollowController::class, 'followersOf'])->whereNumber('user')->middleware('throttle:tenant-read');
        Route::get('/users/{user}/following', [FollowController::class, 'followingOf'])->whereNumber('user')->middleware('throttle:tenant-read');
        Route::get('/search', [CommunitySearchController::class, 'index'])->middleware('throttle:tenant-read');
        Route::get('/hashtags/{slug}/posts', [CommunitySearchController::class, 'hashtagPosts'])->middleware('throttle:tenant-read');

        Route::prefix('community/media/uploads')->group(function () {
            Route::post('/', [CommunityMediaUploadController::class, 'initiate'])->middleware('throttle:20,1');
            Route::get('{upload}', [CommunityMediaUploadController::class, 'show']);
            Route::post('{upload}/parts/{part}', [CommunityMediaUploadController::class, 'part'])->whereNumber('part')->middleware('throttle:240,1');
            Route::post('{upload}/complete', [CommunityMediaUploadController::class, 'complete'])->middleware('idempotent');
            Route::delete('{upload}', [CommunityMediaUploadController::class, 'destroy']);
        });
        Route::get('/community/sectors', [CommunityDirectoryController::class, 'sectors']);
        Route::get('/community/members', [CommunityDirectoryController::class, 'members'])->middleware('throttle:tenant-read');
        Route::prefix('community/groups')->group(function () {
            Route::get('/', [CommunityGroupController::class, 'index']);
            Route::post('/', [CommunityGroupController::class, 'store'])->middleware('idempotent');
            Route::get('{group}', [CommunityGroupController::class, 'show'])->whereNumber('group');
            Route::post('{group}/join', [CommunityGroupController::class, 'join'])->whereNumber('group');
            Route::delete('{group}/join', [CommunityGroupController::class, 'leave'])->whereNumber('group');
        });

        // ---- Company following + community suggestions ----
        Route::post('/follows', [FollowController::class, 'store']);
        Route::delete('/follows/{user}', [FollowController::class, 'destroy'])->whereNumber('user');
        Route::get('/me/following', [FollowController::class, 'following']);
        Route::get('/me/follow-suggestions', [FollowController::class, 'suggestions']);
        Route::post('/organizations/{organization}/follow', [OrganizationFollowController::class, 'store'])->whereNumber('organization');
        Route::delete('/organizations/{organization}/follow', [OrganizationFollowController::class, 'destroy'])->whereNumber('organization');
        Route::post('/users/{user}/{type}', [SocialSafetyController::class, 'relate'])
            ->whereNumber('user')->whereIn('type', ['block', 'mute']);
        Route::delete('/users/{user}/{type}', [SocialSafetyController::class, 'unrelate'])
            ->whereNumber('user')->whereIn('type', ['block', 'mute']);
        Route::post('/reports', [SocialSafetyController::class, 'report'])->middleware('throttle:10,1');

        // ---- Subscription plan (billing tiers) + monthly posting quota ----
        Route::get('/me/subscription', [SubscriptionController::class, 'me']);
        Route::get('/plans', [SubscriptionController::class, 'plans']);
        Route::post('/me/subscription', [SubscriptionController::class, 'subscribe']);

        // ---- Billing invoices (read-only ledger for the account) ----
        Route::get('/me/invoices', [InvoicesApiController::class, 'index']);
        Route::get('/me/invoices/{invoice}', [InvoicesApiController::class, 'show'])->whereNumber('invoice');

        // ---- Member's own community sectors (one or many + primary) ----
        Route::get('/me/sectors', [MeSectorController::class, 'index']);
        Route::put('/me/sectors', [MeSectorController::class, 'update']);

        Route::get('/merchants', [MerchantsApiController::class, 'index']);
        Route::post('/merchants/bulk-destroy', [MerchantsApiController::class, 'bulkDestroy']);
        Route::post('/merchants', [MerchantsApiController::class, 'store']);
        Route::get('/merchants/{merchant}', [MerchantsApiController::class, 'show']);
        Route::put('/merchants/{merchant}', [MerchantsApiController::class, 'update']);
        Route::patch('/merchants/{merchant}/status', [MerchantsApiController::class, 'updateStatus']);
        Route::delete('/merchants/{merchant}', [MerchantsApiController::class, 'destroy']);

        // Merchant support tickets (merchant ↔ platform support team).
        Route::prefix('support/tickets')->group(function () {
            Route::get('/', [SupportTicketsController::class, 'index']);
            Route::post('/', [SupportTicketsController::class, 'store'])->middleware('throttle:10,1');
            Route::get('{ticket}', [SupportTicketsController::class, 'show'])->whereNumber('ticket');
            Route::post('{ticket}/messages', [SupportTicketsController::class, 'reply'])->whereNumber('ticket')->middleware('throttle:30,1');
            Route::post('{ticket}/close', [SupportTicketsController::class, 'close'])->whereNumber('ticket');
            Route::get('{ticket}/messages/{message}/attachments/{index}', [SupportTicketsController::class, 'attachment'])
                ->whereNumber(['ticket', 'message', 'index']);
        });
        Route::middleware('permission:support-tickets-manage')->prefix('admin/support/tickets')->group(function () {
            Route::get('/', [AdminSupportTicketsController::class, 'index']);
            Route::get('{ticket}', [AdminSupportTicketsController::class, 'show'])->whereNumber('ticket');
            Route::post('{ticket}/messages', [AdminSupportTicketsController::class, 'reply'])->whereNumber('ticket');
            Route::patch('{ticket}', [AdminSupportTicketsController::class, 'update'])->whereNumber('ticket');
            Route::get('{ticket}/messages/{message}/attachments/{index}', [AdminSupportTicketsController::class, 'attachment'])
                ->whereNumber(['ticket', 'message', 'index']);
        });

        // Direct user-to-user chat (Pusher broadcasting). Distinct from the Wavex
        // WhatsApp inbox — this is internal member↔member messaging.
        Route::prefix('chat')->group(function () {
            Route::get('/unread-count', [ChatApiController::class, 'unreadCount']);
            Route::get('/conversations', [ChatApiController::class, 'index']);
            Route::post('/conversations', [ChatApiController::class, 'store']);
            Route::get('/conversations/{id}/messages', [ChatApiController::class, 'messages'])->whereNumber('id');
            Route::post('/conversations/{id}/messages', [ChatApiController::class, 'send'])->whereNumber('id')->middleware('throttle:60,1');
            Route::post('/conversations/{id}/read', [ChatApiController::class, 'read'])->whereNumber('id');
        });

        Route::middleware('permission:products-list')->group(function () {
            Route::get('/products', [ProductsApiController::class, 'index']);
            Route::get('/products/{product}', [ProductsApiController::class, 'show']);
            Route::get('/warehouses', [WarehousesApiController::class, 'index']);
            Route::get('/inventory', [InventoryApiController::class, 'index']);
            Route::get('/stock-transfers', [StockTransfersApiController::class, 'index']);
        });
        Route::middleware('permission:products-create')->post('/products', [ProductsApiController::class, 'store']);
        Route::middleware('permission:products-edit')->group(function () {
            Route::put('/products/{product}', [ProductsApiController::class, 'update']);
            Route::post('/inventory', [InventoryApiController::class, 'store']);
            Route::patch('/inventory/{inventory}', [InventoryApiController::class, 'update']);
            Route::delete('/inventory/{inventory}', [InventoryApiController::class, 'destroy']);
            Route::post('/stock-transfers', [StockTransfersApiController::class, 'store']);
        });
        Route::middleware('permission:products-delete')->group(function () {
            Route::post('/products/bulk-destroy', [ProductsApiController::class, 'bulkDestroy']);
            Route::delete('/products/{product}', [ProductsApiController::class, 'destroy']);
        });

        // Employees (owner manages child accounts).
        Route::get('/employees', [EmployeesApiController::class, 'index']);
        Route::post('/employees', [EmployeesApiController::class, 'store']);
        Route::put('/employees/{id}', [EmployeesApiController::class, 'update'])->whereNumber('id');
        Route::delete('/employees/{id}', [EmployeesApiController::class, 'destroy'])->whereNumber('id');

        // Verification (blue-badge) — user side.
        Route::middleware('permission:verifications-request')->group(function () {
            Route::get('/verifications/me', [VerificationsApiController::class, 'me']);
            Route::post('/verifications', [VerificationsApiController::class, 'store']);
        });

        // Verification — admin review queue.
        Route::middleware('permission:verifications-review')->group(function () {
            Route::get('/admin/verifications', [VerificationsApiController::class, 'index']);
            Route::post('/admin/verifications/{id}/approve', [VerificationsApiController::class, 'approve'])->whereNumber('id');
            Route::post('/admin/verifications/{id}/reject', [VerificationsApiController::class, 'reject'])->whereNumber('id');
        });

        Route::middleware('permission:attributes-list')->group(function () {
            Route::get('/attributes', [AttributesApiController::class, 'index']);
            Route::get('/attributes/{id}', [AttributesApiController::class, 'show'])->whereNumber('id');
        });
        Route::middleware('permission:attributes-create')->post('/attributes', [AttributesApiController::class, 'store']);
        Route::middleware('permission:attributes-edit')->put('/attributes/{id}', [AttributesApiController::class, 'update'])->whereNumber('id');
        Route::middleware('permission:attributes-delete')->delete('/attributes/{id}', [AttributesApiController::class, 'destroy'])->whereNumber('id');

        Route::middleware('permission:categories-list')->group(function () {
            Route::get('/categories', [CategoriesApiController::class, 'index']);
            Route::get('/categories/{category}', [CategoriesApiController::class, 'show']);
        });
        Route::middleware('permission:categories-create')->post('/categories', [CategoriesApiController::class, 'store']);
        Route::middleware('permission:categories-edit')->put('/categories/{category}', [CategoriesApiController::class, 'update']);
        Route::middleware('permission:categories-delete')->group(function () {
            Route::post('/categories/bulk-destroy', [CategoriesApiController::class, 'bulkDestroy']);
            Route::delete('/categories/{category}', [CategoriesApiController::class, 'destroy']);
        });

        Route::middleware('permission:bundles-list')->group(function () {
            Route::get('/bundles', [BundlesApiController::class, 'index']);
            Route::get('/bundles/{bundle}', [BundlesApiController::class, 'show']);
        });
        Route::middleware('permission:bundles-create')->post('/bundles', [BundlesApiController::class, 'store']);
        Route::middleware('permission:bundles-edit')->put('/bundles/{bundle}', [BundlesApiController::class, 'update']);
        Route::middleware('permission:bundles-delete')->group(function () {
            Route::post('/bundles/bulk-destroy', [BundlesApiController::class, 'bulkDestroy']);
            Route::delete('/bundles/{bundle}', [BundlesApiController::class, 'destroy']);
        });

        // ---- Stores (Phase 1: Merchant store ownership) ----
        // Platform-wide custom-domain metrics (Prometheus-compatible).
        // Admin is enforced in the controller rather than by a `permission:`
        // middleware, because these aggregate across every tenant and are not
        // tied to any single store permission.
        Route::get('/domain-metrics', [DomainMetricsApiController::class, 'index']);
        Route::get('/domain-metrics/prometheus', [DomainMetricsApiController::class, 'prometheus']);

        Route::middleware('permission:stores-list')->group(function () {
            Route::get('/stores', [StoresApiController::class, 'index']);
            Route::get('/stores/{store}', [StoresApiController::class, 'show'])->whereNumber('store');
        });
        Route::middleware('permission:stores-create')->post('/stores', [StoresApiController::class, 'store']);
        Route::middleware('permission:stores-edit')->match(['put', 'post'], '/stores/{store}', [StoresApiController::class, 'update'])->whereNumber('store');
        Route::middleware('permission:stores-delete')->group(function () {
            Route::post('/stores/bulk-destroy', [StoresApiController::class, 'bulkDestroy']);
            Route::delete('/stores/{store}', [StoresApiController::class, 'destroy'])->whereNumber('store');
        });

        // ---- Store-scoped owner/admin surface (catalog, coupons, analytics, orders,
        //      reviews, themes, page builder) ------------------------------------
        // The SAME relative routes are mounted under two entry points that differ
        // only in how the tenant store is resolved:
        //   • Admin:  /stores/{store}/…  (explicit id — any store the admin manages)
        //   • Owner:  /my-store/…        (auto-resolved single store, no id in URL)
        // Both bind the resolved Store as the `store` route parameter, so every
        // controller below is shared verbatim between the two prefixes.
        $storeScopedRoutes = function (): void {
            Route::prefix('managers')->middleware('throttle:30,1')->group(function () {
                Route::get('/', [StoreManagersController::class, 'index']);
                Route::post('/', [StoreManagersController::class, 'create']);
                Route::put('{manager}', [StoreManagersController::class, 'update'])->whereNumber('manager');
                Route::delete('{manager}', [StoreManagersController::class, 'remove'])->whereNumber('manager');
                Route::post('{manager}/restore', [StoreManagersController::class, 'restore'])->whereNumber('manager');
            });
            Route::middleware('permission:store.products.manage')->prefix('ab-tests')->group(function () {
                Route::get('/', [StoreAbTestsController::class, 'index']);
                Route::get('products', [StoreAbTestsController::class, 'products']);
                Route::post('/', [StoreAbTestsController::class, 'save']);
                Route::get('{abTest}', [StoreAbTestsController::class, 'show'])->whereNumber('abTest');
                Route::match(['put', 'post'], '{abTest}', [StoreAbTestsController::class, 'save'])->whereNumber('abTest');
                Route::post('{abTest}/delete', [StoreAbTestsController::class, 'destroy'])->whereNumber('abTest');
                Route::delete('{abTest}', [StoreAbTestsController::class, 'destroy'])->whereNumber('abTest');
            });
            Route::middleware('permission:store.orders.manage')->prefix('missed-orders')->group(function () {
                Route::get('/', [StoreMissedOrdersController::class, 'index']);
                Route::post('bulk-status', [StoreMissedOrdersController::class, 'bulkStatus']);
            });
            Route::middleware('permission:store.orders.manage')->get('newsletter', [StoreNewsletterController::class, 'index']);
            Route::get('funnels/templates', [StoreFunnelsApiController::class, 'templates']);
            Route::get('funnels/products', [StoreFunnelsApiController::class, 'products']);
            Route::get('funnels/by-code/{code}', [StoreFunnelsApiController::class, 'byCode'])->whereUuid('code');
            Route::get('funnels', [StoreFunnelsApiController::class, 'index']);
            Route::post('funnels/generate', [StoreFunnelsApiController::class, 'generate'])->middleware('throttle:3,1');
            Route::post('funnels', [StoreFunnelsApiController::class, 'store']);
            Route::post('funnels/bulk-delete', [StoreFunnelsApiController::class, 'bulkDelete']);
            Route::get('funnels/{funnel}', [StoreFunnelsApiController::class, 'show'])->whereNumber('funnel');
            Route::put('funnels/{funnel}', [StoreFunnelsApiController::class, 'update'])->whereNumber('funnel');
            Route::post('funnels/{funnel}/duplicate', [StoreFunnelsApiController::class, 'duplicate'])->whereNumber('funnel');
            // ---- Custom domains: connect / verify / promote / disconnect ----
            // Owner storefront surface.
            // Verification triggers outbound DNS and can lead to certificate
            // issuance, so each group carries its own rate limiter (see
            // RouteServiceProvider: domain-read / domain-write / domain-verify).
            Route::prefix('domains')->group(function () {
                Route::middleware('throttle:domain-read')->group(function () {
                    Route::get('/', [StoreDomainsApiController::class, 'index']);
                    Route::get('setup', [StoreDomainsApiController::class, 'setup']);
                    Route::get('health', [StoreDomainsApiController::class, 'healthSummary']);
                    Route::get('events', [StoreDomainsApiController::class, 'events']);
                    Route::get('{domain}/health', [StoreDomainsApiController::class, 'health'])->whereNumber('domain');
                    Route::get('{domain}/events', [StoreDomainsApiController::class, 'events'])->whereNumber('domain');
                });

                Route::middleware('throttle:domain-write')->group(function () {
                    Route::post('/', [StoreDomainsApiController::class, 'store']);
                    Route::post('{domain}/primary', [StoreDomainsApiController::class, 'makePrimary'])->whereNumber('domain');
                    Route::post('{domain}/disable', [StoreDomainsApiController::class, 'disable'])->whereNumber('domain');
                    Route::post('{domain}/enable', [StoreDomainsApiController::class, 'enable'])->whereNumber('domain');
                    // POST alias supports hosting proxies that normalize DELETE
                    // before PHP receives it. Authorization remains identical.
                    Route::match(['post', 'delete'], '{domain}', [StoreDomainsApiController::class, 'destroy'])->whereNumber('domain');
                });

                Route::middleware('throttle:domain-verify')->group(function () {
                    Route::post('{domain}/verification', [StoreDomainsApiController::class, 'startVerification'])->whereNumber('domain');
                    Route::post('{domain}/hosting', [StoreDomainsApiController::class, 'prepareHosting'])->whereNumber('domain');
                    Route::post('{domain}/verify', [StoreDomainsApiController::class, 'verify'])->whereNumber('domain');
                    Route::post('{domain}/dns', [StoreDomainsApiController::class, 'refreshDns'])->whereNumber('domain');
                    Route::post('{domain}/ssl/retry', [StoreDomainsApiController::class, 'retrySsl'])->whereNumber('domain');
                    Route::post('{domain}/ssl/refresh', [StoreDomainsApiController::class, 'refreshSsl'])->whereNumber('domain');
                });
            });

            // Storefront catalog remains tenant-scoped and separate from the B2B catalog.
            Route::prefix('catalog/products')->group(function () {
                Route::middleware('store.catalog.permission:products-create')->get('ai-draft', [StoreProductAiController::class, 'status']);
                Route::middleware('store.catalog.permission:products-create')->post('ai-draft', [StoreProductAiController::class, 'generate']);
                Route::middleware('store.catalog.permission:products-edit')->get('{product}/ai-draft', [StoreProductAiController::class, 'status'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-edit')->post('{product}/ai-draft', [StoreProductAiController::class, 'generate'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-create')->get('import-template', [StoreCatalogTransferController::class, 'template']);
                Route::middleware('store.catalog.permission:products-create')->post('import/preview', [StoreCatalogTransferController::class, 'preview']);
                Route::middleware('store.catalog.permission:products-create')->post('import', [StoreCatalogTransferController::class, 'import']);
                Route::middleware('store.catalog.permission:products-list')->post('export', [StoreCatalogTransferController::class, 'export']);
                Route::middleware('store.catalog.permission:products-delete')->post('bulk-delete', [StoreCatalogTransferController::class, 'delete']);
                Route::middleware(['store.catalog.permission:products-create', 'store.catalog.permission:products-list'])->post('{product}/duplicate', [StoreCatalogTransferController::class, 'duplicate'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-edit')->get('{product}/landing-page', [StoreProductLandingController::class, 'show'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-edit')->get('{product}/landing-page/destinations', [StoreProductLandingController::class, 'destinations'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-edit')->put('{product}/landing-page', [StoreProductLandingController::class, 'update'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-edit')->post('{product}/landing-page/media', [StoreProductLandingController::class, 'upload'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-list')->get('{product}/quantity-offers', [StoreQuantityOffersController::class, 'show'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-edit')->put('{product}/quantity-offers', [StoreQuantityOffersController::class, 'update'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-list')->get('/', [StorefrontProductsApiController::class, 'index']);
                Route::middleware('store.catalog.permission:products-create')->post('/', [StorefrontProductsApiController::class, 'store']);
                Route::middleware('store.catalog.permission:products-edit')->put('bulk', [StorefrontProductsApiController::class, 'bulkUpdate']);
                Route::middleware('store.catalog.permission:products-list')->get('{product}', [StorefrontProductsApiController::class, 'show'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-edit')->match(['put', 'post'], '{product}', [StorefrontProductsApiController::class, 'update'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-delete')->delete('{product}', [StorefrontProductsApiController::class, 'destroy'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-list')->get('{product}/variants', [StorefrontProductVariantsApiController::class, 'index'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-edit')->post('{product}/variants', [StorefrontProductVariantsApiController::class, 'store'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-edit')->post('{product}/variants/generate', [StorefrontProductVariantsApiController::class, 'generate'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-edit')->put('{product}/variants/bulk', [StorefrontProductVariantsApiController::class, 'bulkUpdate'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-edit')->put('{product}/variants/bulk/inventory', [StorefrontProductVariantsApiController::class, 'bulkInventory'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-delete')->delete('{product}/variants/bulk', [StorefrontProductVariantsApiController::class, 'bulkDelete'])->whereNumber('product');
                Route::middleware('store.catalog.permission:products-edit')->put('{product}/variants/{variant}', [StorefrontProductVariantsApiController::class, 'update'])->whereNumber(['product', 'variant']);
                Route::middleware('store.catalog.permission:products-delete')->delete('{product}/variants/{variant}', [StorefrontProductVariantsApiController::class, 'destroy'])->whereNumber(['product', 'variant']);
            });
            Route::middleware('store.catalog.permission:products-list')->get('catalog/inventory', [StoreInventoryController::class, 'index']);
            Route::middleware('store.catalog.permission:products-list')->get('catalog/inventory/{product}/history', [StoreInventoryController::class, 'history'])->whereNumber('product');
            Route::middleware('store.catalog.permission:products-edit')->put('catalog/inventory/{product}', [StoreInventoryController::class, 'update'])->whereNumber('product');

            Route::prefix('catalog/categories')->group(function () {
                Route::middleware('store.catalog.permission:categories-delete')->post('bulk-delete', [StoreCategoriesApiController::class, 'bulkDestroy']);
                Route::middleware('store.catalog.permission:categories-delete')->delete('{category}', [StoreCategoriesApiController::class, 'destroy'])->whereNumber('category');
                Route::middleware('store.catalog.permission:categories-list')->get('/', [StoreCategoriesApiController::class, 'index']);
                Route::middleware('store.catalog.permission:categories-create')->post('/', [StoreCategoriesApiController::class, 'store']);
                Route::middleware('store.catalog.permission:categories-list')->get('{category}', [StoreCategoriesApiController::class, 'show'])->whereNumber('category');
                Route::middleware('store.catalog.permission:categories-edit')->match(['put', 'post'], '{category}', [StoreCategoriesApiController::class, 'update'])->whereNumber('category');
            });

            // ---- Collections (manual + automated); guarded like categories ----
            Route::prefix('catalog/collections')->group(function () {
                Route::middleware('store.catalog.permission:categories-list')->get('/', [StoreCollectionsApiController::class, 'index']);
                Route::middleware('store.catalog.permission:categories-list')->get('rule-options', [StoreCollectionsApiController::class, 'ruleOptions']);
                Route::middleware('store.catalog.permission:categories-list')->post('preview', [StoreCollectionsApiController::class, 'preview']);
                Route::middleware('store.catalog.permission:categories-create')->post('/', [StoreCollectionsApiController::class, 'store']);
                Route::middleware('store.catalog.permission:categories-edit')->post('bulk-update', [StoreCollectionsApiController::class, 'bulkUpdate']);
                Route::middleware('store.catalog.permission:categories-delete')->post('bulk-delete', [StoreCollectionsApiController::class, 'bulkDestroy']);
                Route::middleware('store.catalog.permission:categories-list')->get('{collection}', [StoreCollectionsApiController::class, 'show'])->whereNumber('collection');
                Route::middleware('store.catalog.permission:categories-edit')->match(['put', 'post'], '{collection}', [StoreCollectionsApiController::class, 'update'])->whereNumber('collection');
                Route::middleware('store.catalog.permission:categories-edit')->post('{collection}/products', [StoreCollectionsApiController::class, 'products'])->whereNumber('collection');
                Route::middleware('store.catalog.permission:categories-delete')->delete('{collection}', [StoreCollectionsApiController::class, 'destroy'])->whereNumber('collection');
            });

            // ---- Coupons (Phase 6D) ----
            Route::prefix('coupons')->group(function () {
                Route::get('/', [CouponsApiController::class, 'index']);
                Route::post('/', [CouponsApiController::class, 'store']);
                Route::get('{coupon}', [CouponsApiController::class, 'show'])->whereNumber('coupon');
                Route::match(['put', 'post'], '{coupon}', [CouponsApiController::class, 'update'])->whereNumber('coupon');
                Route::delete('{coupon}', [CouponsApiController::class, 'destroy'])->whereNumber('coupon');
            });

            // ---- Gift cards: a tender managed with discounts (store.coupons.manage) ----
            Route::prefix('gift-cards')->group(function () {
                Route::get('/', [StoreGiftCardsController::class, 'index']);
                Route::post('/', [StoreGiftCardsController::class, 'store'])->middleware('throttle:30,1');
                Route::get('{giftCard}', [StoreGiftCardsController::class, 'show'])->whereNumber('giftCard');
                Route::match(['put', 'patch'], '{giftCard}', [StoreGiftCardsController::class, 'update'])->whereNumber('giftCard');
                Route::post('{giftCard}/adjust', [StoreGiftCardsController::class, 'adjust'])->whereNumber('giftCard');
            });

            // ---- Analytics (Phase 6F, read-only) ----
            Route::prefix('analytics')->group(function () {
                Route::get('dashboard', StoreDashboardMetricsController::class)->middleware('permission:store.analytics.view');
                Route::get('overview', [StoreAnalyticsController::class, 'overview']);
                Route::get('products', [StoreAnalyticsController::class, 'products']);
                Route::get('categories', [StoreAnalyticsController::class, 'categories']);
                Route::get('customers', [StoreAnalyticsController::class, 'customers']);
            });

            Route::middleware('permission:store.orders.manage')->prefix('customers')->group(function () {
                Route::get('/', [StoreCustomersApiController::class, 'index']);
                Route::get('directory', [StoreCustomersApiController::class, 'directory'])->middleware('throttle:tenant-read');
                Route::get('directory/export', [StoreCustomersApiController::class, 'export'])->middleware('throttle:10,1');
                Route::get('profile', [StoreCustomersApiController::class, 'profile'])->middleware('throttle:tenant-read');
                Route::put('profile', [StoreCustomersApiController::class, 'updateProfile'])->middleware('throttle:60,1');
                Route::get('tags', [StoreCustomersApiController::class, 'tags'])->middleware('throttle:tenant-read');
                Route::get('{customer}', [StoreCustomersApiController::class, 'show'])->whereNumber('customer');
            });

            // ---- Draft orders: merchant quotes with a shareable invoice link ----
            Route::middleware('permission:store.orders.manage')->prefix('draft-orders')->group(function () {
                Route::get('/', [StoreDraftOrderController::class, 'index']);
                Route::post('/', [StoreDraftOrderController::class, 'store'])->middleware('throttle:60,1');
                Route::get('options', [StoreDraftOrderController::class, 'options']);
                Route::post('calculate', [StoreDraftOrderController::class, 'calculate'])->middleware('throttle:120,1');
                Route::get('{draft}', [StoreDraftOrderController::class, 'show'])->whereNumber('draft');
                Route::match(['put', 'patch'], '{draft}', [StoreDraftOrderController::class, 'update'])->whereNumber('draft')->middleware('throttle:60,1');
                Route::delete('{draft}', [StoreDraftOrderController::class, 'destroy'])->whereNumber('draft');
                Route::post('{draft}/calculate', [StoreDraftOrderController::class, 'calculate'])->whereNumber('draft')->middleware('throttle:120,1');
                Route::post('{draft}/invoice', [StoreDraftOrderController::class, 'invoice'])->whereNumber('draft')->middleware('throttle:20,1');
                Route::post('{draft}/complete', [StoreDraftOrderController::class, 'complete'])->whereNumber('draft')->middleware('throttle:20,1');
                Route::post('{draft}/duplicate', [StoreDraftOrderController::class, 'duplicate'])->whereNumber('draft')->middleware('throttle:30,1');
                Route::post('{draft}/cancel', [StoreDraftOrderController::class, 'cancel'])->whereNumber('draft');
            });

            // ---- Orders (Phase 6E) ----
            Route::prefix('orders')->group(function () {
                Route::get('/', [MerchantOrderController::class, 'index']);
                Route::get('print-data', [MerchantOrderController::class, 'printData'])->middleware('permission:store.orders.manage');
                Route::get('filter-products', [MerchantOrderController::class, 'filterProducts'])->middleware('permission:store.orders.manage');
                Route::middleware('permission:store.orders.manage')->prefix('manual')->group(function () {
                    Route::get('products', [ManualStoreOrderController::class, 'products']);
                    Route::get('request/{key}', [ManualStoreOrderController::class, 'reconcile'])->whereUuid('key');
                    Route::get('products/{product}/variants', [ManualStoreOrderController::class, 'variants'])->whereNumber('product');
                    Route::post('products/{product}/personalization-image', [ManualStoreOrderController::class, 'upload'])->whereNumber('product')->middleware('throttle:5,1');
                    Route::post('/', [ManualStoreOrderController::class, 'store'])->middleware('throttle:20,1');
                });
                Route::post('bulk-status', [MerchantOrderController::class, 'bulkStatus'])->middleware(['permission:store.orders.manage', 'throttle:20,1']);
                Route::post('bulk-payment-review', [MerchantBulkPaymentController::class, 'preview'])->middleware(['permission:store.orders.manage', 'throttle:40,1']);
                Route::post('bulk-payment-status', [MerchantBulkPaymentController::class, 'apply'])->middleware(['permission:store.orders.manage', 'throttle:20,1']);
                Route::middleware('permission:store.orders.manage')->group(function () {
                    Route::get('{order}/detail', [MerchantOrderActionsController::class, 'show'])->whereNumber('order');
                    Route::get('{order}/edit', [MerchantOrderEditController::class, 'show'])->whereNumber('order');
                    Route::post('{order}/edit', [MerchantOrderEditController::class, 'update'])->whereNumber('order')->middleware('throttle:20,1');
                    Route::get('{order}/edits/request/{key}', [MerchantOrderEditController::class, 'reconcile'])->whereNumber('order')->whereUuid('key');
                    Route::post('{order}/archive', [MerchantOrderActionsController::class, 'archive'])->whereNumber('order');
                    Route::post('{order}/review', [MerchantOrderActionsController::class, 'review'])->whereNumber('order');
                    Route::post('{order}/block-phone', [MerchantOrderActionsController::class, 'blockPhone'])->whereNumber('order')->middleware('throttle:10,1');
                    Route::post('{order}/resend-webhook', [MerchantOrderActionsController::class, 'resendWebhook'])->whereNumber('order')->middleware('throttle:10,1');
                    // Refunds are recorded for the merchant's books; no payment gateway is called.
                    Route::get('{order}/refunds', [MerchantOrderRefundController::class, 'index'])->whereNumber('order');
                    Route::post('{order}/refunds', [MerchantOrderRefundController::class, 'store'])->whereNumber('order')->middleware('throttle:30,1');
                    Route::get('{order}/returns', [MerchantOrderRefundController::class, 'orderReturns'])->whereNumber('order');
                });
                Route::middleware('permission:store.orders.manage')->prefix('exports')->group(function () {
                    Route::get('/', [StoreOrderExportController::class, 'index']);
                    Route::post('/', [StoreOrderExportController::class, 'store'])->middleware('throttle:10,1');
                    Route::get('{export}', [StoreOrderExportController::class, 'show'])->whereUuid('export');
                    Route::get('{export}/download', [StoreOrderExportController::class, 'download'])->whereUuid('export')->middleware('throttle:20,1');
                });
                Route::get('{order}', [MerchantOrderController::class, 'show'])->whereNumber('order');
                Route::match(['patch', 'post'], '{order}/status', [MerchantOrderController::class, 'updateStatus'])->whereNumber('order');
                Route::post('{order}/note', [MerchantOrderController::class, 'addNote'])->whereNumber('order')->middleware('permission:store.orders.manage');
                Route::get('{order}/payment/proofs', [MerchantPaymentProofController::class, 'index'])->whereNumber('order')->middleware('permission:store.orders.manage');
                Route::get('{order}/payment/proofs/{proof}', [MerchantPaymentProofController::class, 'download'])->whereNumber('order')->whereUuid('proof')->middleware('permission:store.orders.manage');
                Route::post('{order}/payment/proofs/{proof}/reject', [MerchantPaymentProofController::class, 'reject'])->whereNumber('order')->whereUuid('proof')->middleware(['permission:store.orders.manage', 'throttle:20,1']);
                Route::post('{order}/payment/confirm-bank', [MerchantOrderController::class, 'confirmBankPayment'])->whereNumber('order')->middleware(['permission:store.orders.manage', 'throttle:20,1']);
                Route::post('{order}/payment/confirm-cash', [MerchantOrderController::class, 'confirmCashPayment'])->whereNumber('order')->middleware(['permission:store.orders.manage', 'throttle:20,1']);
                Route::get('{order}/digital-email', [MerchantOrderController::class, 'digitalEmail'])->whereNumber('order')->middleware('permission:store.orders.manage');
                Route::get('{order}/digital-delivery', [MerchantOrderController::class, 'digitalDeliveryStatus'])->whereNumber('order')->middleware('permission:store.orders.manage');
                Route::post('{order}/digital-delivery/email', [MerchantOrderController::class, 'resendDigitalItemEmail'])->whereNumber('order')->middleware(['permission:store.orders.manage', 'throttle:10,1']);
                Route::post('{order}/digital-email', [MerchantOrderController::class, 'resendDigitalEmail'])->whereNumber('order')->middleware(['permission:store.orders.manage', 'throttle:10,1']);
                Route::get('{order}/notifications', [StoreNotificationsController::class, 'orderLog'])->whereNumber('order')->middleware('permission:store.orders.manage');
                Route::post('{order}/notifications/resend', [StoreNotificationsController::class, 'resend'])->whereNumber('order')->middleware(['permission:store.orders.manage', 'throttle:10,1']);
                Route::get('{order}/shipment', [StoreShipmentController::class, 'show'])->whereNumber('order');
                Route::post('{order}/shipment', [StoreShipmentController::class, 'create'])->whereNumber('order')->middleware('throttle:20,1');
                Route::post('{order}/shipment/sync', [StoreShipmentController::class, 'sync'])->whereNumber('order')->middleware('throttle:30,1');
                Route::post('{order}/shipment/label', [StoreShipmentController::class, 'label'])->whereNumber('order')->middleware('throttle:20,1');
            });

            Route::middleware('permission:store.orders.manage')->prefix('returns')->group(function () {
                Route::get('/', [MerchantOrderRefundController::class, 'returnsIndex']);
                Route::patch('{return}', [MerchantOrderRefundController::class, 'updateReturn'])->whereNumber('return')->middleware('throttle:30,1');
            });

            // ---- Reviews (Phase 6H) ----
            Route::prefix('reviews')->group(function () {
                Route::get('/', [MerchantReviewController::class, 'index']);
                Route::post('bulk-status', [MerchantReviewController::class, 'bulkStatus'])->middleware('throttle:30,1');
                Route::patch('{review}/status', [MerchantReviewController::class, 'updateStatus'])->whereNumber('review');
                Route::delete('{review}', [MerchantReviewController::class, 'destroy'])->whereNumber('review');
            });

            // ---- Themes (Phase 4C) ----
            Route::prefix('themes')->group(function () {
                Route::get('/', [StoreThemesApiController::class, 'index']);
                Route::get('history', [StoreThemesApiController::class, 'history']);
                Route::post('install', [StoreThemesApiController::class, 'install']);
                Route::post('activate', [StoreThemesApiController::class, 'activate']);
                Route::post('upgrade', [StoreThemesApiController::class, 'upgrade']);
                Route::post('preview', [StoreThemesApiController::class, 'preview']);
                Route::post('rollback', [StoreThemesApiController::class, 'rollback']);
                // POST alias mirrors store settings and works behind proxies
                // that do not preserve PUT requests.
                Route::match(['put', 'post'], 'settings', [StoreThemesApiController::class, 'settings']);
                Route::post('publish', [StoreThemesApiController::class, 'publish']);
                Route::match(['put', 'post'], 'custom-css', [StoreThemesApiController::class, 'customCss']);
                Route::get('{theme}/revisions', [StoreThemesApiController::class, 'revisions'])->whereNumber('theme');
                Route::post('{theme}/revisions/{revision}/restore', [StoreThemesApiController::class, 'restoreRevision'])->whereNumber('theme')->whereNumber('revision');
                Route::get('{theme}', [StoreThemesApiController::class, 'show'])->whereNumber('theme');
            });

            Route::get('shipping', [StoreShippingController::class, 'index']);
            Route::put('shipping', [StoreShippingController::class, 'update']);
            Route::get('shipping/zones', [StoreShippingZonesController::class, 'index']);
            Route::post('shipping/zones', [StoreShippingZonesController::class, 'create']);
            Route::put('shipping/zones/{zone}', [StoreShippingZonesController::class, 'update'])->whereUuid('zone');
            Route::delete('shipping/zones/{zone}', [StoreShippingZonesController::class, 'destroy'])->whereUuid('zone');
            Route::post('shipping/tax-overrides', [StoreShippingZonesController::class, 'storeOverride']);
            Route::put('shipping/tax-overrides/{override}', [StoreShippingZonesController::class, 'updateOverride'])->whereUuid('override');
            Route::delete('shipping/tax-overrides/{override}', [StoreShippingZonesController::class, 'destroyOverride'])->whereUuid('override');
            Route::post('shipping/quote-preview', [StoreShippingZonesController::class, 'preview'])->middleware('throttle:60,1');
            Route::get('carriers/bosta', [StoreCarrierController::class, 'show']);
            Route::put('carriers/bosta', [StoreCarrierController::class, 'update']);
            Route::post('carriers/bosta/verify', [StoreCarrierController::class, 'verify'])->middleware('throttle:10,1');
            Route::get('carriers/bosta/cities', [StoreCarrierController::class, 'cities'])->middleware('throttle:60,1');
            Route::get('carriers/bosta/districts', [StoreCarrierController::class, 'districts'])->middleware('throttle:60,1');
            Route::get('checkout-fields', [StoreCheckoutFieldsController::class, 'index']);
            Route::put('checkout-fields', [StoreCheckoutFieldsController::class, 'update']);
            Route::get('design-assets', [StoreDesignAssetsController::class, 'index']);
            Route::post('design-assets', [StoreDesignAssetsController::class, 'upload'])->middleware('throttle:30,1');
            Route::put('design-assets/configuration', [StoreDesignAssetsController::class, 'settings']);
            Route::delete('design-assets/{asset}', [StoreDesignAssetsController::class, 'archive'])->whereNumber('asset');
            Route::post('design-assets/{asset}/restore', [StoreDesignAssetsController::class, 'restore'])->whereNumber('asset');
            Route::get('ui-blocks', [StoreUiBlocksController::class, 'index']);
            Route::put('ui-blocks', [StoreUiBlocksController::class, 'update']);
            Route::get('thank-you', [StoreThankYouController::class, 'index']);
            Route::put('thank-you', [StoreThankYouController::class, 'update']);
            Route::get('digital-delivery', [StoreDigitalDeliveryController::class, 'index']);
            Route::get('notifications', [StoreNotificationsController::class, 'index']);
            Route::get('order-limits', [StoreOrderLimitsController::class, 'index']);
            Route::get('blocked-phone-numbers', [StorePhoneBlockController::class, 'index']);
            Route::get('blocked-phones', [StorePhoneBlockController::class, 'index']);
            Route::post('blocked-phones', [StorePhoneBlockController::class, 'storePhone'])->middleware('throttle:30,1');
            Route::put('blocked-phones/{block}', [StorePhoneBlockController::class, 'update'])->whereNumber('block')->middleware('throttle:30,1');
            Route::get('blocked-phones/{block}/history', [StorePhoneBlockController::class, 'history'])->whereNumber('block');
            Route::get('phone-verification', [StorePhoneVerificationController::class, 'index']);
            Route::prefix('tracking')->middleware('permission:store.settings.manage')->group(function () {
                Route::get('pixels', [StoreTrackingController::class, 'pixels']);
                Route::put('pixels', [StoreTrackingController::class, 'updatePixels']);
                Route::get('google-tag', [StoreTrackingController::class, 'google']);
                Route::put('google-tag', [StoreTrackingController::class, 'updateGoogle']);
                Route::get('conversions', [StoreTrackingController::class, 'conversions']);
                Route::post('conversions', [StoreTrackingController::class, 'saveConversion'])->middleware('throttle:20,1');
                Route::put('conversions/{destination}', [StoreTrackingController::class, 'saveConversion'])->whereUuid('destination')->middleware('throttle:20,1');
                Route::delete('conversions/{destination}', [StoreTrackingController::class, 'removeConversion'])->whereUuid('destination')->middleware('throttle:20,1');
            });
            Route::get('google-merchant', [GoogleMerchantController::class, 'show'])->middleware('permission:store.settings.manage');
            Route::get('apps', [StoreAppsController::class, 'index'])->middleware(['permission:store.settings.manage', 'throttle:tenant-read']);
            Route::prefix('apps/{app}/settings')->where(['app' => 'social-proof|country-restriction|fake-orders|bundles'])->group(function () {
                Route::get('/', [StoreAppSettingsController::class, 'show'])->middleware('throttle:tenant-read');
                Route::put('/', [StoreAppSettingsController::class, 'update'])->middleware('throttle:30,1');
            });
            Route::prefix('general-settings')->middleware('permission:store.settings.manage')->group(function () {
                Route::get('/', [StoreGeneralSettingsController::class, 'index'])->middleware('throttle:tenant-read');
                Route::put('/', [StoreGeneralSettingsController::class, 'update'])->middleware('throttle:30,1');
            });
            Route::middleware('permission:store.settings.manage')->group(function () {
                Route::get('public-api', [StoreApiKeysController::class, 'index'])->middleware('throttle:tenant-read');
                Route::post('public-api', [StoreApiKeysController::class, 'create'])->middleware('throttle:20,1');
                Route::get('public-api/{key}', [StoreApiKeysController::class, 'show'])->whereUuid('key')->middleware('throttle:tenant-read');
                Route::put('public-api/{key}', [StoreApiKeysController::class, 'update'])->whereUuid('key')->middleware('throttle:20,1');
                Route::delete('public-api/{key}', [StoreApiKeysController::class, 'destroy'])->whereUuid('key')->middleware('throttle:20,1');
                Route::get('webhooks', [StoreOrderWebhookController::class, 'index'])->middleware('throttle:tenant-read');
                Route::post('webhooks', [StoreOrderWebhookController::class, 'create'])->middleware('throttle:20,1');
                Route::get('webhooks/{webhook}', [StoreOrderWebhookController::class, 'show'])->whereUuid('webhook')->middleware('throttle:tenant-read');
                Route::put('webhooks/{webhook}', [StoreOrderWebhookController::class, 'update'])->whereUuid('webhook')->middleware('throttle:20,1');
                Route::delete('webhooks/{webhook}', [StoreOrderWebhookController::class, 'destroy'])->whereUuid('webhook')->middleware('throttle:20,1');
                Route::get('order-push', [MerchantOrderPushController::class, 'index'])->middleware('throttle:tenant-read');
                Route::get('head-code', [StoreHeadCodeController::class, 'index'])->middleware('throttle:tenant-read');
                Route::put('head-code', [StoreHeadCodeController::class, 'update'])->middleware('throttle:30,1');
                Route::put('order-push', [MerchantOrderPushController::class, 'update'])->middleware('throttle:30,1');
                Route::get('seo-settings', [StoreGeneralSettingsController::class, 'seo'])->middleware('throttle:tenant-read');
                Route::put('seo-settings', [StoreGeneralSettingsController::class, 'updateSeo'])->middleware('throttle:30,1');
                Route::get('social-links', [StoreSocialLinksController::class, 'index'])->middleware('throttle:tenant-read');
                Route::put('social-links', [StoreSocialLinksController::class, 'update'])->middleware('throttle:30,1');
            });
            Route::prefix('dashboard-guide')->middleware('permission:store.settings.manage')->group(function () {
                Route::get('/', [StoreDashboardGuideController::class, 'index'])->middleware('throttle:tenant-read');
                // POST supports hosts whose method policy blocks PUT; both use the same authorization and validation.
                Route::match(['put', 'post'], '/', [StoreDashboardGuideController::class, 'update'])->middleware('throttle:30,1');
            });
            Route::prefix('downsell')->middleware(['permission:store.settings.manage', 'permission:store.coupons.manage'])->group(function () {
                Route::get('/', [StoreDownsellController::class, 'index'])->middleware('throttle:tenant-read');
                Route::put('/', [StoreDownsellController::class, 'update'])->middleware('throttle:20,1');
            });
            Route::prefix('sheets')->middleware(['permission:store.settings.manage', 'permission:store.orders.manage'])->group(function () {
                Route::get('/', [StoreSheetsController::class, 'index'])->middleware('throttle:tenant-read');
                Route::post('/', [StoreSheetsController::class, 'save'])->middleware('throttle:20,1');
                Route::put('{connection}', [StoreSheetsController::class, 'save'])->whereUuid('connection')->middleware('throttle:20,1');
                Route::delete('{connection}', [StoreSheetsController::class, 'destroy'])->whereUuid('connection')->middleware('throttle:20,1');
                Route::post('deliveries/{delivery}/retry', [StoreSheetsController::class, 'retry'])->whereNumber('delivery')->middleware('throttle:10,1');
            });
            Route::get('referral-products', [StoreReferralController::class, 'products'])->middleware('permission:store.settings.manage');
            Route::put('google-merchant', [GoogleMerchantController::class, 'update'])->middleware(['permission:store.settings.manage', 'throttle:20,1']);
            Route::get('product-feed', [GoogleMerchantController::class, 'feedInformation'])->middleware('permission:store.settings.manage');
            Route::get('product-feed/download', [GoogleMerchantController::class, 'download'])->middleware(['permission:store.settings.manage', 'throttle:10,1']);
            Route::get('bot-protection', [StoreBotProtectionController::class, 'index']);
            Route::get('shopping-preferences', [StoreShoppingPreferencesController::class, 'index']);
            Route::put('shopping-preferences', [StoreShoppingPreferencesController::class, 'update']);
            Route::put('bot-protection', [StoreBotProtectionController::class, 'update'])->middleware('throttle:10,1');
            Route::put('phone-verification', [StorePhoneVerificationController::class, 'update'])->middleware('throttle:10,1');
            Route::post('blocked-phone-numbers', [StorePhoneBlockController::class, 'storePhone'])->middleware('throttle:30,1');
            Route::put('blocked-phone-numbers/{block}', [StorePhoneBlockController::class, 'update'])->whereNumber('block')->middleware('throttle:30,1');
            Route::get('blocked-phone-numbers/{block}/history', [StorePhoneBlockController::class, 'history'])->whereNumber('block');
            Route::put('order-limits', [StoreOrderLimitsController::class, 'update']);
            Route::put('digital-delivery', [StoreDigitalDeliveryController::class, 'update']);
            Route::put('notifications', [StoreNotificationsController::class, 'update']);
            Route::post('notifications/preview', [StoreNotificationsController::class, 'preview'])->middleware('throttle:60,1');
            Route::post('notifications/test', [StoreNotificationsController::class, 'test'])->middleware('throttle:5,1');
            Route::put('digital-delivery/connection', [StoreDigitalDeliveryController::class, 'connection']);
            Route::post('digital-delivery/verify', [StoreDigitalDeliveryController::class, 'verify'])->middleware('throttle:10,1');

            Route::get('payments', [StorePaymentsApiController::class, 'index']);
            Route::match(['put', 'post'], 'payments', [StorePaymentsApiController::class, 'update']);

            // ---- Publishing (owner + admin): readiness gate, publish/unpublish ----
            Route::get('readiness', [StorePublishingApiController::class, 'readiness']);
            Route::post('publish', [StorePublishingApiController::class, 'publish']);
            Route::post('unpublish', [StorePublishingApiController::class, 'unpublish']);
            // Currency codes available to store settings (no rates, no admin permission).
            Route::get('currencies', [CurrencySettingsApiController::class, 'codes']);

            // ---- Page Builder (Phase 4E): pages, reusable sections, menus ----
            $simplePages = StoreSimplePagesController::class;
            Route::get('simple-pages', [$simplePages, 'index']);
            Route::post('simple-pages', [$simplePages, 'save']);
            Route::post('simple-pages/archive', [$simplePages, 'archive']);
            Route::get('simple-pages/{page}', [$simplePages, 'show'])->whereNumber('page');
            Route::put('simple-pages/{page}', [$simplePages, 'save'])->whereNumber('page');
            Route::post('simple-pages/{page}/restore', [$simplePages, 'restore'])->whereNumber('page');
            $pages = StorePagesApiController::class;
            Route::get('pages/schema', [$pages, 'schema']);
            Route::get('pages/template/{template}', [$pages, 'ensureTemplate'])->whereIn('template', StorePageService::TEMPLATE_PAGES);
            Route::get('pages', [$pages, 'index']);
            Route::post('pages', [$pages, 'store']);
            Route::get('pages/{page}', [$pages, 'show'])->whereNumber('page');
            Route::match(['put', 'post'], 'pages/{page}', [$pages, 'update'])->whereNumber('page');
            Route::delete('pages/{page}', [$pages, 'destroy'])->whereNumber('page');
            Route::match(['put', 'post'], 'pages/{page}/sections', [$pages, 'syncSections'])->whereNumber('page');
            Route::post('pages/{page}/publish', [$pages, 'publish'])->whereNumber('page');
            Route::post('pages/{page}/unpublish', [$pages, 'unpublish'])->whereNumber('page');
            Route::post('pages/{page}/schedule', [$pages, 'schedule'])->whereNumber('page');
            Route::post('pages/{page}/preview', [$pages, 'preview'])->whereNumber('page');
            Route::get('pages/{page}/revisions', [$pages, 'revisions'])->whereNumber('page');
            Route::post('pages/{page}/revisions/{revision}/restore', [$pages, 'restoreRevision'])->whereNumber('page')->whereNumber('revision');

            // Independent merchant articles. POST aliases support restrictive hosting proxies.
            $articles = StoreArticlesApiController::class;
            Route::get('articles', [$articles, 'index']);
            Route::post('articles', [$articles, 'store']);
            Route::post('articles/import-legacy', [$articles, 'importLegacy']);
            Route::get('articles/{article}', [$articles, 'show'])->whereNumber('article');
            Route::match(['put', 'post'], 'articles/{article}', [$articles, 'update'])->whereNumber('article');
            Route::post('articles/{article}/preview', [$articles, 'preview'])->whereNumber('article');
            Route::post('articles/{article}/publish', [$articles, 'publish'])->whereNumber('article');
            Route::post('articles/{article}/unpublish', [$articles, 'unpublish'])->whereNumber('article');
            Route::post('articles/{article}/archive', [$articles, 'archive'])->whereNumber('article');

            // ---- Content pages (fixed system pages: about/contact/faq/shipping/returns/blog) ----
            $content = StoreContentPagesApiController::class;
            Route::get('content', [$content, 'index']);
            Route::put('content/{key}', [$content, 'update'])->where('key', '[a-z0-9\-]+');
            Route::post('content/upload-image', [$content, 'uploadImage']);
            Route::get('media', [StoreMediaApiController::class, 'index']);
            Route::post('media', [StoreMediaApiController::class, 'store']);
            Route::patch('media/{asset}', [StoreMediaApiController::class, 'update'])->whereNumber('asset');
            Route::delete('media/{asset}', [StoreMediaApiController::class, 'destroy'])->whereNumber('asset');

            $reusable = StoreReusableSectionsApiController::class;
            Route::get('reusable-sections', [$reusable, 'index']);
            Route::post('reusable-sections', [$reusable, 'store']);
            Route::put('reusable-sections/{reusable}', [$reusable, 'update'])->whereNumber('reusable');
            Route::delete('reusable-sections/{reusable}', [$reusable, 'destroy'])->whereNumber('reusable');

            $menus = StoreMenusApiController::class;
            Route::get('menus', [$menus, 'index']);
            Route::get('menus/{handle}', [$menus, 'show'])->where('handle', '[a-z0-9\-]+');
            Route::put('menus/{handle}', [$menus, 'upsert'])->where('handle', '[a-z0-9\-]+');
            Route::delete('menus/{handle}', [$menus, 'destroy'])->where('handle', '[a-z0-9\-]+');
        };

        // Admin: explicit store id (multi-store console).
        Route::middleware('store.scope')->prefix('stores/{store}')->whereNumber('store')->group($storeScopedRoutes);
        // Owner (Merchant): auto-resolved single store, no id in the URL.
        // The store record itself (read + settings update) plus all sub-resources.
        Route::middleware('store.own')->group(function () {
            Route::get('my-store', [StoresApiController::class, 'current']);
            Route::match(['put', 'post'], 'my-store', [StoresApiController::class, 'update']);
        });
        Route::middleware('store.own')->prefix('my-store')->group($storeScopedRoutes);

        // ---- Theme marketplace catalog (Phase 4D): authenticated browsing ----
        Route::get('/marketplace/themes', [MarketplaceThemesController::class, 'index']);
        Route::get('/marketplace/themes/{key}', [MarketplaceThemesController::class, 'show'])->where('key', '[a-z0-9\-]+');

        // ---- Admin theme administration (Phase 4D): publishing + assets ----
        Route::middleware('role:Admin')->group(function () {
            Route::get('/admin/moderation/reports', [AdminModerationController::class, 'index']);
            Route::post('/admin/moderation/reports/{report}/review', [AdminModerationController::class, 'review'])->whereNumber('report');
            Route::post('/admin/organizations/{organization}/verification', [AdminModerationController::class, 'verifyOrganization'])->whereNumber('organization');
            Route::get('/admin/themes', [AdminThemesController::class, 'index']);
            Route::patch('/admin/themes/{theme}/commercial', [AdminThemesController::class, 'updateCommercial'])->whereNumber('theme');
            Route::post('/admin/themes/{theme}/versions', [AdminThemesController::class, 'storeVersion'])->whereNumber('theme');
            Route::post('/admin/themes/{theme}/versions/{version}/transition', [AdminThemesController::class, 'transitionVersion'])->whereNumber('theme')->whereNumber('version');
            Route::get('/admin/themes/{theme}/history', [AdminThemesController::class, 'history'])->whereNumber('theme');
            Route::post('/admin/themes/{theme}/transition', [AdminThemesController::class, 'transition'])->whereNumber('theme');
            Route::post('/admin/themes/{theme}/assets', [AdminThemesController::class, 'storeAsset'])->whereNumber('theme');
            Route::delete('/admin/themes/{theme}/assets/{asset}', [AdminThemesController::class, 'destroyAsset'])->whereNumber('theme')->whereNumber('asset');
        });

        Route::get('/admin/monitoring/live', [MonitoringApiController::class, 'live']);
        Route::get('/admin/monitoring/sessions', [MonitoringApiController::class, 'sessions']);
        Route::post('/admin/monitoring/sessions/bulk-destroy', [MonitoringApiController::class, 'bulkDestroySessions']);

        Route::middleware('permission:users-list|users-pending-list')->group(function () {
            Route::get('/admin/users', [UsersApiController::class, 'index']);
            Route::get('/admin/users/{user}', [UsersApiController::class, 'show']);
        });
        Route::middleware('permission:users-create|users-edit')->get('/admin/staff-role-options', [UsersApiController::class, 'staffRoleOptions']);
        Route::middleware('permission:users-create')->post('/admin/users', [UsersApiController::class, 'store']);
        Route::middleware('permission:users-list')->group(function () {
            Route::get('/admin/users/{user}/notification-preferences', [UsersApiController::class, 'notificationPreferences']);
            Route::patch('/admin/users/{user}/notification-preferences', [UsersApiController::class, 'updateNotificationPreferences']);
        });
        Route::middleware('permission:users-edit')->group(function () {
            Route::patch('/admin/users/{user}', [UsersApiController::class, 'update']);
            Route::patch('/admin/users/{user}/status', [UsersApiController::class, 'updateStatus']);
        });
        Route::middleware('permission:users-delete')->delete('/admin/users/{user}', [UsersApiController::class, 'destroy']);

        Route::middleware('role:Admin')->group(function () {
            Route::post('/admin/impersonate', [ImpersonationApiController::class, 'store']);
            Route::get('/admin/activity-logs', [ActivityLogsApiController::class, 'index']);
            Route::delete('/admin/activity-logs/{activityLog}', [ActivityLogsApiController::class, 'destroy']);
            Route::post('/admin/activity-logs/bulk-destroy', [ActivityLogsApiController::class, 'bulkDestroy']);
            Route::get('/admin/roles', [RolesApiController::class, 'index']);
            Route::post('/admin/roles', [RolesApiController::class, 'store']);
            Route::get('/admin/roles/{role}/users', [RolesApiController::class, 'usersForRole']);
            Route::get('/admin/roles/{role}', [RolesApiController::class, 'show']);
            Route::patch('/admin/roles/{role}', [RolesApiController::class, 'update']);
            Route::delete('/admin/roles/{role}', [RolesApiController::class, 'destroy']);
            Route::get('/admin/settings/summary', [SettingsSummaryApiController::class, 'show']);
            Route::get('/admin/settings/google', [GoogleSettingsApiController::class, 'show']);
            Route::put('/admin/settings/google', [GoogleSettingsApiController::class, 'update']);
            Route::get('/admin/settings/email', [EmailSettingsApiController::class, 'show']);
            Route::put('/admin/settings/email', [EmailSettingsApiController::class, 'update']);
            Route::post('/admin/settings/email/test', [EmailSettingsApiController::class, 'sendTest']);
            Route::get('/admin/settings/email-templates', [EmailTemplatesApiController::class, 'index']);
            Route::get('/admin/settings/email-templates/{key}', [EmailTemplatesApiController::class, 'show'])
                ->where('key', '[a-z0-9_\-]+');
            Route::put('/admin/settings/email-templates/{key}', [EmailTemplatesApiController::class, 'update'])
                ->where('key', '[a-z0-9_\-]+');
            Route::get('/admin/settings/email-logs', [EmailLogsApiController::class, 'index']);
            Route::get('/admin/settings/currencies', [CurrencySettingsApiController::class, 'index']);
            Route::post('/admin/settings/currencies', [CurrencySettingsApiController::class, 'store']);
            Route::put('/admin/settings/currencies/{currencyCode}', [CurrencySettingsApiController::class, 'update']);
            Route::post('/admin/settings/currencies/refresh', [CurrencySettingsApiController::class, 'refresh']);

            // Admin Dashboard (aggregated KPIs + charts).
            Route::get('/admin/dashboard/overview', [AdminDashboardApiController::class, 'overview']);

            // Admin Reports (timeseries + breakdowns).
            Route::get('/admin/reports/users', [AdminReportsApiController::class, 'users']);
            Route::get('/admin/reports/orders', [AdminReportsApiController::class, 'orders']);
            Route::get('/admin/reports/revenue', [AdminReportsApiController::class, 'revenue']);

            // Articles (Blog) management — Admin CRUD.
            Route::get('/admin/articles', [ArticlesApiController::class, 'adminIndex']);
            Route::post('/admin/articles', [ArticlesApiController::class, 'adminStore']);
            Route::post('/admin/articles/upload-image', [ArticlesApiController::class, 'adminUploadImage']);
            Route::get('/admin/articles/{id}', [ArticlesApiController::class, 'adminShow'])->whereNumber('id');
            Route::match(['put', 'patch'], '/admin/articles/{id}', [ArticlesApiController::class, 'adminUpdate'])->whereNumber('id');
            Route::delete('/admin/articles/{id}', [ArticlesApiController::class, 'adminDestroy'])->whereNumber('id');
            Route::post('/admin/articles/{id}/publish', [ArticlesApiController::class, 'adminPublish'])->whereNumber('id');
            Route::post('/admin/articles/{id}/unpublish', [ArticlesApiController::class, 'adminUnpublish'])->whereNumber('id');
        });

        Route::middleware('permission:wavex-access')->prefix('wavex')->group(function () {
            Route::get('/settings', [WavexSettingsApiController::class, 'show']);
            Route::put('/settings', [WavexSettingsApiController::class, 'update']);

            Route::post('/session/create', [WavexSessionApiController::class, 'create']);
            Route::post('/session/start', [WavexSessionApiController::class, 'start']);
            Route::post('/session/stop', [WavexSessionApiController::class, 'stop']);
            Route::get('/session/qr', [WavexSessionApiController::class, 'qr']);
            Route::get('/session/info', [WavexSessionApiController::class, 'info']);

            Route::get('/chats', [WavexChatsApiController::class, 'index']);
            Route::get('/chats/media/proxy', [WavexChatsApiController::class, 'proxyMedia']);
            Route::get('/chats/{chatId}/messages', [WavexChatsApiController::class, 'messages'])
                ->where('chatId', '.+');
            Route::get('/contacts/profile-picture', [WavexChatsApiController::class, 'profilePicture']);
            Route::post('/send', [WavexChatsApiController::class, 'send']);
            Route::post('/send/media', [WavexChatsApiController::class, 'sendMedia']);

            Route::get('/groups', [WavexGroupsApiController::class, 'index']);
            Route::post('/groups', [WavexGroupsApiController::class, 'store']);
            Route::get('/groups/{groupId}/participants', [WavexGroupsApiController::class, 'participants'])
                ->where('groupId', '.+');
            Route::post('/groups/{groupId}/participants', [WavexGroupsApiController::class, 'addParticipants'])
                ->where('groupId', '.+');

            Route::get('/contact-groups', [WavexContactGroupsApiController::class, 'index']);
            Route::post('/contact-groups', [WavexContactGroupsApiController::class, 'store']);
            Route::get('/contact-groups/{wavexContactGroup}', [WavexContactGroupsApiController::class, 'show']);
            Route::put('/contact-groups/{wavexContactGroup}', [WavexContactGroupsApiController::class, 'update']);
            Route::delete('/contact-groups/{wavexContactGroup}', [WavexContactGroupsApiController::class, 'destroy']);
            Route::post('/contact-groups/bulk-destroy', [WavexContactGroupsApiController::class, 'bulkDestroy']);
            Route::get('/contact-groups/{wavexContactGroup}/members', [WavexContactGroupsApiController::class, 'membersIndex']);
            Route::post('/contact-groups/{wavexContactGroup}/members', [WavexContactGroupsApiController::class, 'membersStore']);
            Route::delete('/contact-groups/{wavexContactGroup}/members/{wavexContactGroupMember}', [WavexContactGroupsApiController::class, 'membersDestroy']);
            Route::post('/contact-groups/{wavexContactGroup}/import', [WavexContactGroupsApiController::class, 'import']);
            Route::post('/contact-groups/{wavexContactGroup}/import-whatsapp', [WavexContactGroupsApiController::class, 'importWhatsapp']);
            Route::post('/contact-groups/{wavexContactGroup}/import-whatsapp-contacts', [WavexContactGroupsApiController::class, 'importWhatsappContacts']);
            Route::get('/contact-groups/{wavexContactGroup}/export', [WavexContactGroupsApiController::class, 'export']);

            Route::get('/inbox', [WavexInboxApiController::class, 'index']);

            Route::get('/templates', [WavexTemplatesApiController::class, 'index']);
            Route::post('/templates', [WavexTemplatesApiController::class, 'store']);
            Route::put('/templates/{wavexTemplate}', [WavexTemplatesApiController::class, 'update']);
            Route::post('/templates/{wavexTemplate}', [WavexTemplatesApiController::class, 'update']);
            Route::delete('/templates/{wavexTemplate}', [WavexTemplatesApiController::class, 'destroy']);
            Route::get('/templates/{wavexTemplate}', [WavexTemplatesApiController::class, 'show']);
            Route::post('/templates/bulk-destroy', [WavexTemplatesApiController::class, 'bulkDestroy']);

            Route::get('/campaigns', [WavexCampaignsApiController::class, 'index']);
            Route::get('/campaigns/queue-status', [WavexCampaignsApiController::class, 'queueStatus']);
            Route::post('/campaigns', [WavexCampaignsApiController::class, 'store']);
            Route::get('/campaigns/{wavexCampaign}', [WavexCampaignsApiController::class, 'show']);
            Route::delete('/campaigns/{wavexCampaign}', [WavexCampaignsApiController::class, 'destroy']);
            Route::post('/campaigns/bulk-destroy', [WavexCampaignsApiController::class, 'bulkDestroy']);
            Route::post('/campaigns/{wavexCampaign}/start', [WavexCampaignsApiController::class, 'start']);
            Route::post('/campaigns/{wavexCampaign}/pause', [WavexCampaignsApiController::class, 'pause']);
            Route::post('/campaigns/{wavexCampaign}/resume', [WavexCampaignsApiController::class, 'resume']);
            Route::post('/campaigns/{wavexCampaign}/retry-failed', [WavexCampaignsApiController::class, 'retryFailed']);
            Route::post('/campaigns/{wavexCampaign}/recipients/{recipientId}/skip', [WavexCampaignsApiController::class, 'skipRecipient']);
            Route::post('/campaigns/{wavexCampaign}/cancel', [WavexCampaignsApiController::class, 'cancel']);
            Route::post('/campaigns/{wavexCampaign}/import-csv', [WavexCampaignsApiController::class, 'importCsv']);
        });
    });
});

// Paid theme marketplace: platform-owned Stripe Checkout and signed fulfillment webhook.
Route::post('/theme-marketplace/stripe/webhook', [ThemeLicenseWebhookController::class, 'stripe']);
Route::middleware(['auth:sanctum', ResolveOwnStore::class])
    ->post('/my-store/themes/{theme}/purchase', [ThemeLicensePurchaseController::class, 'store']);
Route::middleware(['auth:sanctum', 'store.scope'])
    ->post('/stores/{store}/themes/{theme}/purchase', [ThemeLicensePurchaseController::class, 'store']);
Route::middleware(['auth:sanctum', ResolveOwnStore::class])
    ->get('/my-store/theme-purchases/{session}', [ThemeLicensePurchaseController::class, 'status']);
Route::middleware(['auth:sanctum', 'store.scope'])
    ->get('/stores/{store}/theme-purchases/{session}', [ThemeLicensePurchaseController::class, 'status']);
