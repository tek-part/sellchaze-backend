<?php

namespace App\Http;

use App\Http\Middleware\ApiKeyMiddleware;
use App\Http\Middleware\AttachRequestContext;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\AuthenticateStoreCustomer;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\EnsureUserApproved;
use App\Http\Middleware\HasInvitation;
use App\Http\Middleware\IsAdmin;
use App\Http\Middleware\LogApiActivity;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\ResolveOwnStore;
use App\Http\Middleware\ResolveStoreFromHost;
use App\Http\Middleware\ResolveStorefrontLocale;
use App\Http\Middleware\RestrictPendingApiUser;
use App\Http\Middleware\ScopeOrganizationRoute;
use App\Http\Middleware\ScopeToStore;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrimStrings;
use App\Http\Middleware\TrustHosts;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\ValidateSignature;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

class Kernel extends HttpKernel
{
    /**
     * Tenant identity must be resolved before named workload throttles choose
     * their bucket. Laravel otherwise prioritizes ThrottleRequests ahead of
     * route middleware, which collapses tenants behind the same IP.
     *
     * @var array<int, class-string|string>
     */
    protected $middlewarePriority = [
        AuthenticateJwt::class,
        ResolveStoreFromHost::class,
        ThrottleRequests::class,
        SubstituteBindings::class,
        Authorize::class,
    ];

    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        TrustHosts::class,
        TrustProxies::class,
        HandleCors::class,
        PreventRequestsDuringMaintenance::class,
        ValidatePostSize::class,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
        AttachRequestContext::class,
        SecurityHeaders::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
            SetLocale::class,
        ],

        'api' => [
            // \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            'throttle:api',
            SubstituteBindings::class,
            LogApiActivity::class,
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array<string, class-string|string>
     */
    protected $routeMiddleware = [
        'auth' => Authenticate::class,
        'auth.basic' => AuthenticateWithBasicAuth::class,
        'auth.session' => AuthenticateSession::class,
        'cache.headers' => SetCacheHeaders::class,
        'can' => Authorize::class,
        'guest' => RedirectIfAuthenticated::class,
        'password.confirm' => RequirePassword::class,
        'signed' => ValidateSignature::class,
        'throttle' => ThrottleRequests::class,
        'verified' => EnsureEmailIsVerified::class,
        'role' => RoleMiddleware::class,
        'permission' => PermissionMiddleware::class,
        'role_or_permission' => RoleOrPermissionMiddleware::class,
        'hasInvitation' => HasInvitation::class,
        'profile.complete' => EnsureProfileComplete::class,
        'user.approved' => EnsureUserApproved::class,
        'admin' => IsAdmin::class,
        'api.key' => ApiKeyMiddleware::class,
        'jwt.auth' => AuthenticateJwt::class,
        'scope.organization.route' => ScopeOrganizationRoute::class,
        'pending.restrict' => RestrictPendingApiUser::class,
        'idempotent' => EnsureIdempotentRequest::class,
        'resolve.store' => ResolveStoreFromHost::class,
        'store.scope' => ScopeToStore::class,
        'store.own' => ResolveOwnStore::class,
        'store.customer' => AuthenticateStoreCustomer::class,
        'storefront.locale' => ResolveStorefrontLocale::class,
    ];
}
