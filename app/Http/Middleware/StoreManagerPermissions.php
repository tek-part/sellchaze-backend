<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Services\Stores\StoreManagers;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Fail closed for store managers and employees: each store area needs its matching store.* grant. */
class StoreManagerPermissions
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }
        $manager = $user->hasRole(StoreManagers::ROLE);
        // Employees act for their parent merchant too; they get exactly the store.* grants they hold.
        $delegate = ! $manager && ! empty($user->parent_user_id) && ! $user->hasAnyRole(['Admin', 'Manager', 'Staff']);
        if (! $manager && ! $delegate) {
            return $next($request);
        }
        $store = $request->attributes->get('store');
        $parent = $manager ? StoreManagers::parentOwner($user) : $user->parent;
        abort_unless($store instanceof Store && $parent && (int) $store->owner_user_id === (int) $parent->id, 403);
        if ($manager) {
            abort_unless($user->can('store.view'), 403);
        }
        $uri = (string) $request->route()?->uri();
        $relative = (string) preg_replace('#^.*?/(?:my-store|stores/\{store\})(?:/|$)#', '', $uri);
        // The catalog keeps its per-route products-* / categories-* checks for employees.
        if ($delegate && explode('/', trim($relative, '/'))[0] === 'catalog') {
            return $next($request);
        }
        $grants = $this->required($relative, $request->isMethod('GET') || $request->isMethod('HEAD'));
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
                // Collections are catalog structure like categories; product editors may read them.
                'collections' => $read ? ['store.categories.manage', 'store.products.manage'] : ['store.categories.manage'],
                default => [],
            };
        }

        return match ($root) {
            'orders', 'customers', 'missed-orders', 'newsletter', 'blocked-phone-numbers', 'blocked-phones' => ['store.orders.manage'],
            'ab-tests' => ['store.products.manage'],
            'reviews' => ['store.reviews.manage'],
            'coupons' => ['store.coupons.manage'],
            // Discount managers may look up gift cards; issuing, adjusting and disabling needs its own grant.
            'gift-cards' => $read ? ['store.gift_cards.manage', 'store.coupons.manage'] : ['store.gift_cards.manage'],
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
