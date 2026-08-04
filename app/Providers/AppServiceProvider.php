<?php

namespace App\Providers;

use App\Core\Bootstrap\BootstrapDefault;
use App\Models\Article;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderQuotations;
use App\Models\Setting;
use App\Services\EmailTemplateService;
use Auth;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Request-scoped tenant holder (Octane-safe: reset between requests).
        $this->app->scoped(\App\Support\Tenancy\CurrentStore::class);

        // Request-scoped memo for order attribute-badge lookups (Octane-safe).
        $this->app->scoped(\App\Support\AttributeBadgeCache::class);

        // Theme settings migrations registry (Phase 4D, Task 4).
        if (class_exists(\App\Services\Themes\ThemeSettingsMigrator::class)) {
            $this->app->singleton(\App\Services\Themes\ThemeSettingsMigrator::class);
        }

        // SSL provider registry. A singleton so providers registered at runtime
        // via extend() are visible to every later resolution.
        $this->app->singleton(\App\Services\Stores\Ssl\SslProviderManager::class);

        // Trusted-host lookup runs on every request; keep one instance.
        $this->app->singleton(\App\Services\Stores\TrustedHostRegistry::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->ensureFileCacheWritable();

        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $base = rtrim((string) config('sellchase.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

            return $base.'/reset-password?token='.urlencode($token).'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });

        ResetPassword::toMailUsing(function (object $notifiable, string $token) {
            $base = rtrim((string) config('sellchase.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');
            $url = $base.'/reset-password?token='.urlencode($token).'&email='.urlencode($notifiable->getEmailForPasswordReset());
            $expire = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
            $vars = EmailTemplateService::varsForAuthResetPassword($url, $expire);

            return app(EmailTemplateService::class)->mailMessage(EmailTemplate::KEY_AUTH_RESET_PASSWORD, $vars);
        });

        // Phase 4D: Default theme 1.0.0 -> 1.1.0 renames "primary" to "brand_primary".
        if (class_exists(\App\Services\Themes\ThemeSettingsMigrator::class)) {
            app(\App\Services\Themes\ThemeSettingsMigrator::class)->register(
                'default', '1.0.0', '1.1.0',
                \App\Services\Themes\ThemeSettingsMigrator::rename(['primary' => 'brand_primary']),
            );
        }

        // Use Bootstrap 5 for pagination views
        Paginator::useBootstrapFive();

        // Update defaultStringLength
        Builder::defaultStringLength(191);

        // Init layout file
        app(BootstrapDefault::class)->init();

        // Apply settings from database (overrides .env when set)
        if ($this->hasSettingsTable()) {
            $googleClientId = Setting::get('google_client_id');
            if ($googleClientId !== null && $googleClientId !== '') {
                Config::set('services.google.client_id', $googleClientId);
                Config::set('services.google.client_secret', Setting::get('google_client_secret', ''));
            }
            if (filled(config('services.google.client_id'))) {
                Config::set('services.google.redirect', url('/auth/google/callback'));
            }
            $mailHost = Setting::get('mail_host');
            if ($mailHost !== null && $mailHost !== '') {
                Config::set('mail.default', Setting::get('mail_mailer', 'smtp'));
                Config::set('mail.from.address', Setting::get('mail_from_address', config('mail.from.address')));
                Config::set('mail.from.name', Setting::get('mail_from_name', config('mail.from.name')));
                Config::set('mail.mailers.smtp.host', $mailHost);
                Config::set('mail.mailers.smtp.port', Setting::get('mail_port', 465));
                Config::set('mail.mailers.smtp.username', Setting::get('mail_username'));
                Config::set('mail.mailers.smtp.password', Setting::get('mail_password'));
                $enc = Setting::get('mail_encryption');
                Config::set('mail.mailers.smtp.encryption', $enc === 'null' || $enc === 'none' ? null : $enc);
            }
        }

        view()->composer('partials.drawers._activity-drawer', function ($view) {
            if (! Auth::check()) {
                $view->with('recent_activities', collect());

                return;
            }

            $uid = (int) Auth::id();
            $authUser = Auth::user();
            $isAdmin = ($uid === 1) || (($authUser->email ?? '') === 'admin@admin.com') || $authUser->hasRole('Admin');
            $effectiveUserId = $isAdmin ? $uid : b2bListingsUserId();

            $ordersQuery = Order::with('product', 'user')->latest();
            if (! $isAdmin) {
                $ordersQuery->where(function ($q) use ($effectiveUserId) {
                    $q->where('user_id', $effectiveUserId)
                        ->orWhereHas('suppliers', fn ($sq) => $sq->where('customer', $effectiveUserId))
                        ->orWhereHas('suppliers', fn ($sq) => $sq->where('supplier', $effectiveUserId));
                });
            }
            $orders = $ordersQuery->take(10)->get()->map(function ($o) {
                return [
                    'type' => 'order',
                    'model' => $o,
                    'created_at' => $o->created_at,
                    'title' => 'Order '.($o->code ?? $o->id),
                    'url' => $o->code ? route('orders.show', $o->code) : null,
                ];
            });

            $quotationsQuery = OrderQuotations::with('order', 'order.product')->latest();
            if (! $isAdmin) {
                $quotationsQuery->where(function ($q) use ($effectiveUserId) {
                    $q->where('supplier_user_id', $effectiveUserId)
                        ->orWhere('customer_user_id', $effectiveUserId);
                });
            }
            $quotations = $quotationsQuery->take(10)->get()->map(function ($q) {
                return [
                    'type' => 'quotation',
                    'model' => $q,
                    'created_at' => $q->created_at,
                    'title' => 'Quotation for order '.($q->order->code ?? $q->order_id ?? '-'),
                    'url' => $q->order && $q->order->code ? route('orders.show', $q->order->code) : null,
                ];
            });

            $recent_activities = $orders->concat($quotations)->sortByDesc('created_at')->take(15)->values();
            $view->with('recent_activities', $recent_activities);
        });

        // Share website settings and default SEO for frontend (Growtech) layouts
        foreach (['layout.growtech._landing', 'layout.growtech._auth'] as $viewName) {
            view()->composer($viewName, function ($view) {
                $siteTitle = config('app.name');
                $siteLogo = asset('logo.png');
                $siteDescription = '';
                if ($this->hasSettingsTable()) {
                    $siteTitle = Setting::get('site_title') ?: config('app.name');
                    $logoPath = Setting::get('site_logo');
                    $siteLogo = $logoPath ? asset('storage/'.$logoPath) : asset('logo.png');
                    $siteDescription = Setting::get('site_description') ?: '';
                }
                $view->with('siteTitle', $siteTitle);
                $view->with('siteLogo', $siteLogo);
                $view->with('siteDescription', $siteDescription);

                $seoMeta = null;
                if (Route::currentRouteName() === 'blog.show') {
                    $slug = Route::current()->parameter('slug');
                    $article = Article::published()->where('slug', $slug)->first();
                    if ($article) {
                        $seoMeta = [
                            'title' => $article->meta_title ?: $article->title,
                            'description' => $article->meta_description ?: Str::limit($article->excerpt ?? $article->content, 160),
                            'image' => $article->featured_image ? asset('storage/'.$article->featured_image) : $siteLogo,
                            'url' => url()->current(),
                            'type' => 'article',
                        ];
                    }
                } elseif (Route::currentRouteName() === 'blog.index') {
                    $seoMeta = [
                        'title' => __('Articles').' - '.$siteTitle,
                        'description' => $siteDescription ?: __('landing.blog.subtitle'),
                        'image' => $siteLogo,
                        'url' => url()->current(),
                    ];
                }
                if (! isset($view->getData()['seoMeta']) && $seoMeta === null) {
                    $seoMeta = [
                        'title' => $siteTitle,
                        'description' => $siteDescription,
                        'image' => $siteLogo,
                        'url' => url()->current(),
                    ];
                }
                if ($seoMeta !== null) {
                    $view->with('seoMeta', $seoMeta);
                }

                // JSON-LD structured data
                $jsonLd = null;
                $routeName = Route::currentRouteName();
                if ($routeName === 'landing') {
                    $jsonLd = [
                        '@context' => 'https://schema.org',
                        '@type' => 'WebSite',
                        'name' => $siteTitle,
                        'url' => url('/'),
                        'description' => $siteDescription,
                    ];
                } elseif ($routeName === 'blog.show') {
                    $slug = Route::current()->parameter('slug');
                    $article = Article::published()->where('slug', $slug)->with('author')->first();
                    if ($article) {
                        $jsonLd = [
                            '@context' => 'https://schema.org',
                            '@type' => 'Article',
                            'headline' => $article->title,
                            'description' => $article->meta_description ?: Str::limit($article->excerpt ?? $article->content, 160),
                            'image' => $article->featured_image ? asset('storage/'.$article->featured_image) : $siteLogo,
                            'datePublished' => $article->published_at?->toIso8601String(),
                            'dateModified' => $article->updated_at->toIso8601String(),
                            'author' => $article->author ? [
                                '@type' => 'Person',
                                'name' => $article->author->name,
                            ] : null,
                        ];
                    }
                }
                if ($jsonLd !== null) {
                    $view->with('jsonLd', $jsonLd);
                }
            });
        }

        // Share notifications only on main layout (not on every view)
        view()->composer(config('settings.KT_THEME_LAYOUT_DIR').'.master', function ($view) {
            if (Auth::check()) {
                /** @var \App\Models\User $user */
                $user = Auth::user();
                $orders_notifications = $user->notifications->where('type', 'App\Notifications\OrderCreated')->all();
                $quotations_notifications = $user->notifications->where('type', 'App\Notifications\QuotationCreated')->all();
                $view->with('orders_notifications', $orders_notifications)
                    ->with('quotations_notifications', $quotations_notifications);
            } else {
                $view->with('orders_notifications', null)->with('quotations_notifications', null);
            }
        });
    }

    private function hasSettingsTable(): bool
    {
        try {
            return Schema::hasTable('settings');
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    private function ensureFileCacheWritable(): void
    {
        if (! app()->runningInConsole()) {
            $cacheData = storage_path('framework/cache/data');

            if (! File::isDirectory($cacheData)) {
                try {
                    File::makeDirectory($cacheData, 0775, true, true);
                } catch (\Throwable) {
                    return;
                }
            } elseif (! is_writable($cacheData)) {
                @chmod($cacheData, 0775);
            }

            // Keep cache directory roots writable even when background jobs or cleanup
            // scripts remove deeper entries unexpectedly.
            @chmod(storage_path('framework/cache'), 0775);
            @chmod(storage_path('framework/cache/data'), 0775);
        }
    }
}
