<?php

use App\Http\Controllers\Api\ActivityLogsApiController;
use App\Http\Controllers\Api\AdminDashboardApiController;
use App\Http\Controllers\Api\AdminReportsApiController;
use App\Http\Controllers\Api\AdminThemesController;
use App\Http\Controllers\Api\ArticlesApiController;
use App\Http\Controllers\Api\AttributesApiController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BundlesApiController;
use App\Http\Controllers\Api\CategoriesApiController;
use App\Http\Controllers\Api\ChatApiController;
use App\Http\Controllers\Api\CouponsApiController;
use App\Http\Controllers\Api\CurrencySettingsApiController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\DealsApiController;
use App\Http\Controllers\Api\DomainMetricsApiController;
use App\Http\Controllers\Api\DeliveriesApiController;
use App\Http\Controllers\Api\EmailLogsApiController;
use App\Http\Controllers\Api\EmailSettingsApiController;
use App\Http\Controllers\Api\EmailTemplatesApiController;
use App\Http\Controllers\Api\EmployeesApiController;
use App\Http\Controllers\Api\GatewaysApiController;
use App\Http\Controllers\Api\GoogleSettingsApiController;
use App\Http\Controllers\Api\ImpersonationApiController;
use App\Http\Controllers\Api\InventoryApiController;
use App\Http\Controllers\Api\InvoicesApiController;
use App\Http\Controllers\Api\LedgerApiController;
use App\Http\Controllers\Api\MarketplaceThemesController;
use App\Http\Controllers\Api\MerchantOrderController;
use App\Http\Controllers\Api\MerchantReviewController;
use App\Http\Controllers\Api\MerchantsApiController;
use App\Http\Controllers\Api\MonitoringApiController;
use App\Http\Controllers\Api\NotificationsApiController;
use App\Http\Controllers\Api\OrderApiController;
use App\Http\Controllers\Api\OrderQuotationsApiController;
use App\Http\Controllers\Api\OrdersApiController;
use App\Http\Controllers\Api\PartnersApiController;
use App\Http\Controllers\Api\ProductsApiController;
use App\Http\Controllers\Api\PublicContactApiController;
use App\Http\Controllers\Api\PublicProfileApiController;
use App\Http\Controllers\Api\SuppliersDirectoryController;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\FinancingRequestController;
use App\Http\Controllers\Api\FollowController;
use App\Http\Controllers\Api\InvestmentOpportunityController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PostCommentController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\MeSectorController;
use App\Http\Controllers\Api\QuotationsApiController;
use App\Http\Controllers\Api\RolesApiController;
use App\Http\Controllers\Api\SettingsSummaryApiController;
use App\Http\Controllers\Api\ShippingCompaniesApiController;
use App\Http\Controllers\Api\StockTransfersApiController;
use App\Http\Controllers\Api\StoreAnalyticsController;
use App\Http\Controllers\Api\StoreDomainsApiController;
use App\Http\Controllers\Api\Storefront\CartController;
use App\Http\Controllers\Api\Storefront\CheckoutController;
use App\Http\Controllers\Api\Storefront\CustomerAddressController;
use App\Http\Controllers\Api\Storefront\CustomerAuthController;
use App\Http\Controllers\Api\Storefront\ProductReviewController;
use App\Http\Controllers\Api\Storefront\StorefrontBrandController;
use App\Http\Controllers\Api\Storefront\StorefrontCategoryController;
use App\Http\Controllers\Api\Storefront\StorefrontCollectionController;
use App\Http\Controllers\Api\Storefront\StorefrontController;
use App\Http\Controllers\Api\Storefront\StorefrontCouponController;
use App\Http\Controllers\Api\Storefront\StorefrontEngagementController;
use App\Http\Controllers\Api\Storefront\StorefrontProductController;
use App\Http\Controllers\Api\Storefront\StoreOrderController;
use App\Http\Controllers\Api\Storefront\WishlistController;
use App\Http\Controllers\Api\StorefrontContextController;
use App\Http\Controllers\Api\StoreMenusApiController;
use App\Http\Controllers\Api\StoreContentPagesApiController;
use App\Http\Controllers\Api\StorePagesApiController;
use App\Http\Controllers\Api\StoreReusableSectionsApiController;
use App\Http\Controllers\Api\StoresApiController;
use App\Http\Controllers\Api\StoreThemesApiController;
use App\Http\Controllers\Api\SuppliersApiController;
use App\Http\Controllers\Api\TicketsApiController;
use App\Http\Controllers\Api\UsersApiController;
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
use App\Http\Controllers\Api\WigpleasureSyncApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API — Sellchase SPA (JWT) + legacy machine endpoints
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/** Wigpleasure → Sellchase: single-store sync; merchant resolved in OrderSyncService (no API key). */
Route::post('/orders', [OrderApiController::class, 'store']);

Route::prefix('v1')->group(function () {
    Route::post('/orders', [App\Http\Controllers\Api\V1\OrderApiController::class, 'store']);
    // Numeric id only — avoids catching SPA PATCH /v1/orders/{code} (e.g. ORD-1-1) as Wigpleasure order id.
    Route::patch('/orders/{wigpleasureOrderId}', [App\Http\Controllers\Api\V1\OrderApiController::class, 'updateByWigpleasureOrderId'])
        ->whereNumber('wigpleasureOrderId');
});

Route::prefix('v1')->group(function () {
    Route::post('/wavex/webhook', WavexWebhookController::class);

    Route::get('/wavex/media-pickup/{token}', [WavexMediaPickupController::class, 'show'])
        ->middleware('signed')
        ->name('wavex.media-pickup');

    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:60,1');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
    Route::get('/auth/google-config', [AuthController::class, 'googleConfig']);
    Route::post('/auth/google', [AuthController::class, 'google'])->middleware('throttle:60,1');
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    // Public (unauthenticated) endpoints for marketing pages and shareable profiles.
    Route::get('/public/profile/{username}', [PublicProfileApiController::class, 'show']);
    Route::get('/public/profile/{username}/products', [PublicProfileApiController::class, 'products']);
    Route::post('/public/contact', [PublicContactApiController::class, 'store'])->middleware('throttle:60,1');

    // Public Supplier Directory (sellchaze.com/suppliers) — sector hub, industry & specialty pages.
    Route::get('/public/sectors', [SuppliersDirectoryController::class, 'index']);
    Route::get('/public/directory/stats', [SuppliersDirectoryController::class, 'stats']);
    Route::get('/public/suppliers', [SuppliersDirectoryController::class, 'suppliers']);
    Route::get('/public/cities', [SuppliersDirectoryController::class, 'cities']);
    Route::get('/public/suppliers/{username}/similar', [SuppliersDirectoryController::class, 'similar'])
        ->where('username', '[A-Za-z0-9_\-\.]+');
    Route::get('/public/sectors/{sector}', [SuppliersDirectoryController::class, 'sector'])
        ->where('sector', '[a-z0-9\-]+');
    Route::get('/public/sectors/{sector}/{specialty}', [SuppliersDirectoryController::class, 'specialty'])
        ->where(['sector' => '[a-z0-9\-]+', 'specialty' => '[a-z0-9\-]+']);

    // Public Blog API (unauthenticated).
    Route::get('/public/articles', [ArticlesApiController::class, 'publicIndex']);
    Route::get('/public/articles/feed.xml', [ArticlesApiController::class, 'publicFeed']);
    Route::get('/public/articles/{slug}', [ArticlesApiController::class, 'publicShow'])
        ->where('slug', '[A-Za-z0-9\-_]+');

    // Phase 2: resolve the current Host to a store (subdomain-based). Storefront
    // rendering itself is Phase 3 — this only proves/exposes host resolution.
    Route::middleware(['resolve.store', 'throttle:60,1'])->get('/storefront/resolve', StorefrontContextController::class);

    // Phase 3: public storefront API (host-resolved, store-scoped, read-only).
    Route::middleware(['resolve.store', 'throttle:180,1'])->prefix('storefront')->group(function () {
        Route::get('/', [StorefrontController::class, 'index']);
        Route::get('home', [StorefrontController::class, 'home']);
        Route::get('context', [StorefrontController::class, 'context']);
        Route::get('products', [StorefrontProductController::class, 'index']);
        Route::get('products/{slug}', [StorefrontProductController::class, 'show'])->where('slug', '[a-z0-9\-]+');
        Route::get('categories', [StorefrontCategoryController::class, 'index']);
        Route::get('categories/{slug}', [StorefrontCategoryController::class, 'show'])->where('slug', '[a-z0-9\-]+');

        // ---- Merchandising surfaces (collections / brands / active coupons) ----
        Route::get('collections', [StorefrontCollectionController::class, 'index']);
        Route::get('collections/{slug}', [StorefrontCollectionController::class, 'show'])->where('slug', '[a-z0-9\-]+');
        Route::get('brands', [StorefrontBrandController::class, 'index']);
        Route::get('coupons', [StorefrontCouponController::class, 'index']);

        // ---- Engagement capture (newsletter opt-in / contact form) — guest-friendly ----
        Route::post('newsletter', [StorefrontEngagementController::class, 'subscribe']);
        Route::post('contact', [StorefrontEngagementController::class, 'contact']);

        // ---- Editable system pages (about/contact/faq/shipping/returns/blog) ----
        Route::get('content/{key}', [StoreContentPagesApiController::class, 'storefront'])->where('key', '[a-z0-9\-]+');

        // ---- Phase 6: public product reviews (approved only + average summary) ----
        Route::get('products/{slug}/reviews', [ProductReviewController::class, 'index'])->where('slug', '[a-z0-9\-]+');

        // ---- Phase 5: Storefront Commerce ----
        // Cart + checkout are guest-friendly (X-Cart-Token header); auth is optional.
        Route::get('cart', [CartController::class, 'show']);
        Route::post('cart/items', [CartController::class, 'addItem']);
        Route::patch('cart/items/{item}', [CartController::class, 'updateItem'])->whereNumber('item');
        Route::delete('cart/items/{item}', [CartController::class, 'removeItem'])->whereNumber('item');
        Route::delete('cart', [CartController::class, 'clear']);
        Route::post('checkout', [CheckoutController::class, 'store']);

        // ---- Phase 6D: coupon apply/remove on the current cart (guest-friendly) ----
        Route::post('checkout/coupon/apply', [CheckoutController::class, 'applyCoupon']);
        Route::delete('checkout/coupon', [CheckoutController::class, 'removeCoupon']);

        Route::post('auth/register', [CustomerAuthController::class, 'register']);
        Route::post('auth/login', [CustomerAuthController::class, 'login']);

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

    Route::middleware(['jwt.auth', 'pending.restrict'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
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

        Route::get('/dashboard', DashboardApiController::class);

        Route::get('/notifications', [NotificationsApiController::class, 'index']);
        Route::post('/notifications/{id}/read', [NotificationsApiController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationsApiController::class, 'markAllRead']);

        // ---- Community feed / wall (all registered users) ----
        Route::get('/feed', [FeedController::class, 'index']);
        Route::post('/posts', [PostController::class, 'store']);
        Route::get('/posts/{post}', [PostController::class, 'show'])->whereNumber('post');
        Route::delete('/posts/{post}', [PostController::class, 'destroy'])->whereNumber('post');
        Route::post('/posts/{post}/like', [PostController::class, 'like'])->whereNumber('post');
        Route::delete('/posts/{post}/like', [PostController::class, 'unlike'])->whereNumber('post');
        Route::post('/posts/{post}/share', [PostController::class, 'share'])->whereNumber('post');
        Route::get('/posts/{post}/comments', [PostCommentController::class, 'index'])->whereNumber('post');
        Route::post('/posts/{post}/comments', [PostCommentController::class, 'store'])->whereNumber('post');
        Route::delete('/posts/{post}/comments/{comment}', [PostCommentController::class, 'destroy'])
            ->whereNumber('post')->whereNumber('comment');

        // ---- Onboarding checklist (5 steps, derived from real state) ----
        Route::get('/me/onboarding', [OnboardingController::class, 'show']);

        // ---- Company following + community suggestions ----
        Route::post('/follows', [FollowController::class, 'store']);
        Route::delete('/follows/{user}', [FollowController::class, 'destroy'])->whereNumber('user');
        Route::get('/me/following', [FollowController::class, 'following']);
        Route::get('/me/follow-suggestions', [FollowController::class, 'suggestions']);

        // ---- Financing requests (factory funding board) ----
        // Any registered user may raise a request and browse the approved board.
        Route::post('/financing-requests', [FinancingRequestController::class, 'store']);
        Route::get('/financing-requests', [FinancingRequestController::class, 'board']);
        Route::get('/me/financing-requests', [FinancingRequestController::class, 'mine']);
        Route::get('/financing-requests/{id}', [FinancingRequestController::class, 'show'])->whereNumber('id');

        // ---- Investment & partnership opportunities ----
        Route::post('/opportunities', [InvestmentOpportunityController::class, 'store']);
        Route::get('/opportunities', [InvestmentOpportunityController::class, 'board']);
        Route::get('/me/opportunities', [InvestmentOpportunityController::class, 'mine']);
        Route::get('/opportunities/{id}', [InvestmentOpportunityController::class, 'show'])->whereNumber('id');

        // ---- Subscription plan (billing tiers) + monthly posting quota ----
        Route::get('/me/subscription', [SubscriptionController::class, 'me']);
        Route::get('/plans', [SubscriptionController::class, 'plans']);
        Route::post('/me/subscription', [SubscriptionController::class, 'subscribe']);

        // ---- Billing invoices (read-only ledger for the account) ----
        Route::get('/me/invoices', [InvoicesApiController::class, 'index']);
        Route::get('/me/invoices/{invoice}', [InvoicesApiController::class, 'show'])->whereNumber('invoice');

        // ---- Supplier's own directory sectors (one or many + primary) ----
        Route::get('/me/sectors', [MeSectorController::class, 'index']);
        Route::put('/me/sectors', [MeSectorController::class, 'update']);

        Route::get('/orders', [OrdersApiController::class, 'index']);
        Route::middleware('permission:orders-create')->post('/orders', [OrdersApiController::class, 'store']);
        Route::get('/orders/{code}', [OrdersApiController::class, 'show']);
        Route::middleware('permission:orders-out|orders-in')->patch('/orders/{code}', [OrdersApiController::class, 'update']);
        Route::middleware('permission:orders-out|orders-in')->post('/orders/bulk-destroy', [OrdersApiController::class, 'bulkDestroy']);
        Route::middleware('permission:orders-in')->post('/orders/{code}/assign-supplier', [OrdersApiController::class, 'assignSupplier']);

        Route::middleware('permission:quotations-out')->post('/orders/{code}/quotations', [OrderQuotationsApiController::class, 'store']);
        Route::middleware('permission:quotations-out')->patch('/orders/{code}/quotations/{quotation}', [OrderQuotationsApiController::class, 'update']);
        Route::middleware('permission:quotations-in')->post('/orders/{code}/quotations/{quotation}/accept', [OrderQuotationsApiController::class, 'accept']);
        Route::middleware('permission:quotations-out')->patch('/orders/{code}/quotations/{quotation}/tracking', [OrderQuotationsApiController::class, 'updateTracking']);

        /** Read-only list for quotation currency picker (same data as admin Settings → Currencies). */
        Route::middleware('permission:quotations-out|quotations-in')->get('/currencies', [CurrencySettingsApiController::class, 'index']);

        Route::get('/quotations', [QuotationsApiController::class, 'index']);
        Route::get('/deals', [DealsApiController::class, 'index']);
        Route::middleware('permission:gateways-list')->get('/gateways', [GatewaysApiController::class, 'index']);
        Route::middleware('permission:gateways-list')->get('/gateways/{gateway}', [GatewaysApiController::class, 'show']);
        Route::middleware('permission:gateways-edit')->put('/gateways/{gateway}', [GatewaysApiController::class, 'update']);
        Route::middleware('permission:gateways-delete')->delete('/gateways/{gateway}', [GatewaysApiController::class, 'destroy']);
        Route::get('/suppliers', [SuppliersApiController::class, 'index']);
        Route::post('/suppliers/bulk-destroy', [SuppliersApiController::class, 'bulkDestroy']);
        Route::post('/suppliers', [SuppliersApiController::class, 'store']);
        Route::get('/suppliers/{supplier}', [SuppliersApiController::class, 'show']);
        Route::put('/suppliers/{supplier}', [SuppliersApiController::class, 'update']);
        Route::patch('/suppliers/{supplier}/status', [SuppliersApiController::class, 'updateStatus']);
        Route::delete('/suppliers/{supplier}', [SuppliersApiController::class, 'destroy']);
        Route::get('/suppliers/{supplier}/routing-categories', [SuppliersApiController::class, 'routingCategories']);
        Route::put('/suppliers/{supplier}/routing-categories', [SuppliersApiController::class, 'saveRoutingCategories']);

        Route::get('/merchants', [MerchantsApiController::class, 'index']);
        Route::post('/merchants/bulk-destroy', [MerchantsApiController::class, 'bulkDestroy']);
        Route::post('/merchants', [MerchantsApiController::class, 'store']);
        Route::get('/merchants/{merchant}', [MerchantsApiController::class, 'show']);
        Route::put('/merchants/{merchant}', [MerchantsApiController::class, 'update']);
        Route::patch('/merchants/{merchant}/status', [MerchantsApiController::class, 'updateStatus']);
        Route::delete('/merchants/{merchant}', [MerchantsApiController::class, 'destroy']);

        Route::middleware('permission:tickets-list')->group(function () {
            Route::get('/tickets', [TicketsApiController::class, 'index']);
            Route::get('/tickets/{ticket}', [TicketsApiController::class, 'show']);
        });
        Route::middleware('permission:tickets-create')->post('/tickets', [TicketsApiController::class, 'store']);
        Route::post('/tickets/{ticket}/messages', [TicketsApiController::class, 'storeMessage']);
        Route::patch('/tickets/{ticket}', [TicketsApiController::class, 'updateTicket']);
        Route::middleware('permission:tickets-manage')->group(function () {
            Route::post('/tickets/bulk-destroy', [TicketsApiController::class, 'bulkDestroy']);
            Route::post('/tickets/{ticket}/actions', [TicketsApiController::class, 'storeAction']);
        });

        // Direct user-to-user chat (Pusher broadcasting). Distinct from the Wavex
        // WhatsApp inbox — this is internal member↔member messaging.
        Route::prefix('chat')->group(function () {
            Route::get('/unread-count', [ChatApiController::class, 'unreadCount']);
            Route::get('/conversations', [ChatApiController::class, 'index']);
            Route::post('/conversations', [ChatApiController::class, 'store']);
            Route::get('/conversations/{id}/messages', [ChatApiController::class, 'messages'])->whereNumber('id');
            Route::post('/conversations/{id}/messages', [ChatApiController::class, 'send'])->whereNumber('id');
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

        // Bidirectional partners directory + invitations (Merchant↔Supplier).
        Route::get('/partners', [PartnersApiController::class, 'index']);
        Route::post('/partners/invite', [PartnersApiController::class, 'invite']);
        Route::post('/partners/invitations/{id}/accept', [PartnersApiController::class, 'acceptInvitation'])->whereNumber('id');
        Route::post('/partners/invitations/{id}/reject', [PartnersApiController::class, 'rejectInvitation'])->whereNumber('id');

        // Employees (owner manages child accounts).
        Route::get('/employees', [EmployeesApiController::class, 'index']);
        Route::post('/employees', [EmployeesApiController::class, 'store']);
        Route::put('/employees/{id}', [EmployeesApiController::class, 'update'])->whereNumber('id');
        Route::delete('/employees/{id}', [EmployeesApiController::class, 'destroy'])->whereNumber('id');

        // Ledger between current user and a partner (merchant↔supplier pair).
        Route::get('/ledger/{partnerId}', [LedgerApiController::class, 'show'])->whereNumber('partnerId');
        Route::post('/ledger/{partnerId}/payments', [LedgerApiController::class, 'addPayment'])->whereNumber('partnerId');

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

        // Financing requests + investment opportunities — admin moderation.
        Route::middleware('role:Admin|Manager')->group(function () {
            Route::get('/admin/financing-requests', [FinancingRequestController::class, 'adminIndex']);
            Route::post('/admin/financing-requests/{id}/review', [FinancingRequestController::class, 'review'])->whereNumber('id');
            Route::get('/admin/opportunities', [InvestmentOpportunityController::class, 'adminIndex']);
            Route::post('/admin/opportunities/{id}/review', [InvestmentOpportunityController::class, 'review'])->whereNumber('id');
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

        // ---- Stores (Phase 1: Merchant/Supplier store ownership) ----
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
            // ---- Custom domains: connect / verify / promote / disconnect ----
            // Identical surface for Supplier and Merchant storefronts.
            // Verification triggers outbound DNS and can lead to certificate
            // issuance, so each group carries its own rate limiter (see
            // RouteServiceProvider: domain-read / domain-write / domain-verify).
            Route::prefix('domains')->group(function () {
                Route::middleware('throttle:domain-read')->group(function () {
                    Route::get('/', [StoreDomainsApiController::class, 'index']);
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
                    Route::delete('{domain}', [StoreDomainsApiController::class, 'destroy'])->whereNumber('domain');
                });

                Route::middleware('throttle:domain-verify')->group(function () {
                    Route::post('{domain}/verification', [StoreDomainsApiController::class, 'startVerification'])->whereNumber('domain');
                    Route::post('{domain}/verify', [StoreDomainsApiController::class, 'verify'])->whereNumber('domain');
                    Route::post('{domain}/dns', [StoreDomainsApiController::class, 'refreshDns'])->whereNumber('domain');
                    Route::post('{domain}/ssl/retry', [StoreDomainsApiController::class, 'retrySsl'])->whereNumber('domain');
                    Route::post('{domain}/ssl/refresh', [StoreDomainsApiController::class, 'refreshSsl'])->whereNumber('domain');
                });
            });

            // ---- Catalog ----
            // Removed: the per-store catalog CRUD (products/categories/variants) is
            // superseded by the unified per-owner catalog managed at /products and
            // /categories. A store surfaces its owner's catalog automatically via
            // ProductScope, so there is no separate store-scoped catalog to manage.

            // ---- Coupons (Phase 6D) ----
            Route::prefix('coupons')->group(function () {
                Route::get('/', [CouponsApiController::class, 'index']);
                Route::post('/', [CouponsApiController::class, 'store']);
                Route::get('{coupon}', [CouponsApiController::class, 'show'])->whereNumber('coupon');
                Route::match(['put', 'post'], '{coupon}', [CouponsApiController::class, 'update'])->whereNumber('coupon');
                Route::delete('{coupon}', [CouponsApiController::class, 'destroy'])->whereNumber('coupon');
            });

            // ---- Analytics (Phase 6F, read-only) ----
            Route::prefix('analytics')->group(function () {
                Route::get('overview', [StoreAnalyticsController::class, 'overview']);
                Route::get('products', [StoreAnalyticsController::class, 'products']);
                Route::get('categories', [StoreAnalyticsController::class, 'categories']);
                Route::get('customers', [StoreAnalyticsController::class, 'customers']);
            });

            // ---- Orders (Phase 6E) ----
            Route::prefix('orders')->group(function () {
                Route::get('/', [MerchantOrderController::class, 'index']);
                Route::get('{order}', [MerchantOrderController::class, 'show'])->whereNumber('order');
                Route::patch('{order}/status', [MerchantOrderController::class, 'updateStatus'])->whereNumber('order');
                Route::post('{order}/note', [MerchantOrderController::class, 'addNote'])->whereNumber('order');
            });

            // ---- Reviews (Phase 6H) ----
            Route::prefix('reviews')->group(function () {
                Route::get('/', [MerchantReviewController::class, 'index']);
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
                Route::put('settings', [StoreThemesApiController::class, 'settings']);
                Route::get('{theme}', [StoreThemesApiController::class, 'show'])->whereNumber('theme');
            });

            // ---- Page Builder (Phase 4E): pages, reusable sections, menus ----
            $pages = StorePagesApiController::class;
            Route::get('pages/schema', [$pages, 'schema']);
            Route::get('pages', [$pages, 'index']);
            Route::post('pages', [$pages, 'store']);
            Route::get('pages/{page}', [$pages, 'show'])->whereNumber('page');
            Route::put('pages/{page}', [$pages, 'update'])->whereNumber('page');
            Route::delete('pages/{page}', [$pages, 'destroy'])->whereNumber('page');
            Route::put('pages/{page}/sections', [$pages, 'syncSections'])->whereNumber('page');
            Route::post('pages/{page}/publish', [$pages, 'publish'])->whereNumber('page');
            Route::post('pages/{page}/unpublish', [$pages, 'unpublish'])->whereNumber('page');
            Route::post('pages/{page}/schedule', [$pages, 'schedule'])->whereNumber('page');
            Route::post('pages/{page}/preview', [$pages, 'preview'])->whereNumber('page');
            Route::get('pages/{page}/revisions', [$pages, 'revisions'])->whereNumber('page');
            Route::post('pages/{page}/revisions/{revision}/restore', [$pages, 'restoreRevision'])->whereNumber('page')->whereNumber('revision');

            // ---- Content pages (fixed system pages: about/contact/faq/shipping/returns/blog) ----
            $content = StoreContentPagesApiController::class;
            Route::get('content', [$content, 'index']);
            Route::put('content/{key}', [$content, 'update'])->where('key', '[a-z0-9\-]+');
            Route::post('content/upload-image', [$content, 'uploadImage']);

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
        // Owner (Merchant/Supplier): auto-resolved single store, no id in the URL.
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
            Route::get('/admin/themes', [AdminThemesController::class, 'index']);
            Route::get('/admin/themes/{theme}/history', [AdminThemesController::class, 'history'])->whereNumber('theme');
            Route::post('/admin/themes/{theme}/transition', [AdminThemesController::class, 'transition'])->whereNumber('theme');
            Route::post('/admin/themes/{theme}/assets', [AdminThemesController::class, 'storeAsset'])->whereNumber('theme');
            Route::delete('/admin/themes/{theme}/assets/{asset}', [AdminThemesController::class, 'destroyAsset'])->whereNumber('theme')->whereNumber('asset');
        });

        Route::middleware('permission:deliveries-list')->group(function () {
            Route::get('/deliveries', [DeliveriesApiController::class, 'index']);
            Route::get('/deliveries/{delivery}', [DeliveriesApiController::class, 'show']);
        });
        Route::middleware('permission:deliveries-update')->group(function () {
            Route::post('/deliveries', [DeliveriesApiController::class, 'store']);
            Route::patch('/deliveries/{delivery}', [DeliveriesApiController::class, 'update']);
        });

        Route::middleware('permission:shipping-companies-list|deliveries-list|deliveries-update')->get(
            '/shipping-companies',
            [ShippingCompaniesApiController::class, 'index']
        );
        Route::middleware('permission:shipping-companies-list|shipping-companies-edit')->get(
            '/shipping-companies/{shipping_company}',
            [ShippingCompaniesApiController::class, 'show']
        );
        Route::middleware('permission:shipping-companies-create')->post('/shipping-companies', [ShippingCompaniesApiController::class, 'store']);
        Route::middleware('permission:shipping-companies-edit')->match(
            ['put', 'patch'],
            '/shipping-companies/{shipping_company}',
            [ShippingCompaniesApiController::class, 'update']
        );
        Route::middleware('permission:shipping-companies-delete')->delete(
            '/shipping-companies/{shipping_company}',
            [ShippingCompaniesApiController::class, 'destroy']
        );
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
            Route::get('/admin/settings/wigpleasure-sync', [WigpleasureSyncApiController::class, 'showSettings']);
            Route::put('/admin/settings/wigpleasure-sync', [WigpleasureSyncApiController::class, 'updateSettings']);
            Route::post('/admin/wigpleasure-sync/ping', [WigpleasureSyncApiController::class, 'ping']);
            Route::post('/admin/wigpleasure-sync/catalog', [WigpleasureSyncApiController::class, 'syncCatalog']);
            Route::post('/admin/wigpleasure-sync/orders', [WigpleasureSyncApiController::class, 'syncOrders']);

            // Admin Dashboard (aggregated KPIs + charts).
            Route::get('/admin/dashboard/overview', [AdminDashboardApiController::class, 'overview']);

            // Admin Reports (timeseries + breakdowns).
            Route::get('/admin/reports/users', [AdminReportsApiController::class, 'users']);
            Route::get('/admin/reports/orders', [AdminReportsApiController::class, 'orders']);
            Route::get('/admin/reports/revenue', [AdminReportsApiController::class, 'revenue']);
            Route::get('/admin/reports/tickets', [AdminReportsApiController::class, 'tickets']);

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
