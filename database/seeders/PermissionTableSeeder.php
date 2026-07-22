<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $permissions = [
            // Users
            'users-list',
            'users-pending-list',
            'users-create',
            'users-edit',
            'users-delete',
            // Roles
            'roles-list',
            'roles-create',
            'roles-edit',
            'roles-delete',
            // Permissions
            'permissions-list',
            'permissions-create',
            'permissions-edit',
            'permissions-delete',
            // Products
            'products-list',
            'products-create',
            'products-edit',
            'products-delete',
            // Categories
            'categories-list',
            'categories-create',
            'categories-edit',
            'categories-delete',
            // Bundles (product kits / packages)
            'bundles-list',
            'bundles-create',
            'bundles-edit',
            'bundles-delete',
            // Attributes
            'attributes-list',
            'attributes-create',
            'attributes-edit',
            'attributes-delete',
            // Orders
            'orders-out',
            'orders-in',
            'orders-create',
            // Quotations
            'quotations-in',
            'quotations-out',
            // Deals
            'deals-in',
            'deals-out',
            // Balance
            'balance-in',
            'balance-out',
            // Notifications
            'notifications-orders',
            'notifications-quotations',
            // Invitations
            'invitations-list',
            'invitations-send-request',
            'invitations-delete',
            // Store category → supplier routing (Wigpleasure category id)
            'supplier-routings-manage',
            // Settings
            'settings-view',
            'settings-edit',
            // Gateways
            'gateways-list',
            'gateways-create',
            'gateways-edit',
            'gateways-delete',
            'gateways-manage',
            // Supplier accounts (API scopes list by role; SPA sidebar)
            'suppliers-list',
            // Deliveries
            'deliveries-list',
            'deliveries-update',
            // Shipping companies (carriers)
            'shipping-companies-list',
            'shipping-companies-create',
            'shipping-companies-edit',
            'shipping-companies-delete',
            // Tickets
            'tickets-list',
            'tickets-create',
            'tickets-manage',
            // Supplier Payments
            'suppliers-payments-list',
            'suppliers-payments-manage',
            // Website (external site management)
            'website-settings-view',
            'website-settings-edit',
            // Articles
            'articles-list',
            'articles-create',
            'articles-edit',
            'articles-delete',
            // Wavex (WhatsApp / Wawp)
            'wavex-access',
            // Activity / audit log (admin UI)
            'activity-logs-list',
            // Live monitoring
            'monitoring-live-view',
            // Verification / blue-badge
            'verifications-request',
            'verifications-review',

            // ---- Storefront (single-store) feature permissions ----------------
            // Feature-based (not entity-CRUD) permissions for a Merchant/Supplier
            // working inside THEIR OWN store. The store itself is resolved by
            // ownership (store.own middleware); these gate the dashboard surface.
            'store.view',              // My Store overview / dashboard
            'store.products.manage',   // storefront catalog products
            'store.categories.manage', // storefront catalog categories
            'store.orders.manage',     // storefront orders
            'store.coupons.manage',    // discounts / coupons
            'store.reviews.manage',    // product review moderation
            'store.analytics.view',    // storefront analytics
            'store.themes.manage',     // appearance / themes
            'store.pages.manage',      // page builder
            'store.menus.manage',      // navigation menus
            'store.settings.manage',   // store settings
        ];

        $guard = config('auth.defaults.guard', 'web');

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => $guard],
                ['guard_name' => $guard]
            );
        }
    }
}
