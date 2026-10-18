<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Services\Stores\StoreManagers;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Fail closed for dedicated managers without changing legacy owner/employee contracts. */
class StoreManagerPermissions
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->hasRole(StoreManagers::ROLE)) {
            return $next($request);
        }
        $store = $request->attributes->get('store');
        $parent = StoreManagers::parentOwner($user);
        abort_unless($store instanceof Store && $parent && (int) $store->owner_user_id === (int) $parent->id && $user->can('store.view'), 403);
        $uri = (string) $request->route()?->uri();
        $relative = preg_replace('#^.*?/(?:my-store|stores/\{store\})(?:/|$)#', '', $uri);
        $grants = $this->required((string) $relative, $request->isMethod('GET') || $request->isMethod('HEAD'));
        abort_unless($grants && collect($grants)->contains(fn ($grant) => $user->can($grant)), 403);

        return $next($request);
    }

    private function required(string $path, bool $read): array
    {
        if (preg_match('#(?:^|/)purchase(?:/|$)#', $path)) {
            return [];
        }
        $parts = explode('/', trim($path, '/'));
        $root = $parts[0];
        if ($root === '') {
            return [$read ? 'store.view' : 'store.settings.manage'];
        }
        if ($root === 'catalog') {
            return match ($parts[1] ?? '') {
                'products', 'inventory' => ['store.products.manage'],
                // Product editors may read category choices, but cannot edit them.
                'categories' => $read ? ['store.categories.manage', 'store.products.manage'] : ['store.categories.manage'],
                default => [],
            };
        }

        return match ($root) {
            'orders', 'customers', 'missed-orders', 'newsletter', 'blocked-phone-numbers', 'blocked-phones' => ['store.orders.manage'],
            'ab-tests' => ['store.products.manage'],
            'reviews' => ['store.reviews.manage'],
            'coupons' => ['store.coupons.manage'],
            'analytics' => ['store.analytics.view'],
            'themes', 'design-assets' => ['store.themes.manage'],
            'pages', 'simple-pages', 'articles', 'content', 'reusable-sections', 'funnels', 'ui-blocks' => ['store.pages.manage'],
            'menus' => $read ? ['store.themes.manage', 'store.pages.manage'] : ['store.themes.manage'],
            'media' => ['store.themes.manage', 'store.pages.manage', 'store.products.manage'],
            'currencies' => ['store.view'],
            'domains', 'general-settings', 'seo-settings', 'social-links', 'tracking', 'apps', 'dashboard-guide',
            'downsell', 'sheets', 'referral-products', 'google-merchant', 'product-feed', 'bot-protection',
            'shopping-preferences', 'phone-verification', 'order-limits', 'digital-delivery', 'payments',
            'readiness', 'publish', 'unpublish', 'shipping', 'carriers', 'checkout-fields', 'thank-you' => ['store.settings.manage'],
            // Managers, credentials/keys, head code and owner-device registration remain owner-only.
            default => [],
        };
    }
}
