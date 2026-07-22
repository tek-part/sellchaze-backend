<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesTableSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->call(PermissionTableSeeder::class);

        $guard = config('auth.defaults.guard', 'web');

        $allPermissions = Permission::query()->where('guard_name', $guard)->pluck('name')->all();
        $withoutDeliveriesUpdate = ['deliveries-update'];

        $adminRole = Role::firstOrCreate(
            ['name' => 'Admin', 'guard_name' => $guard],
            ['guard_name' => $guard]
        );
        // Admin: full app permissions including deliveries (list + update) for SPA shipping tools.
        $adminRole->syncPermissions($allPermissions);

        $dangerous = ['roles-delete', 'permissions-delete', 'users-delete'];
        $managerPerms = array_values(array_diff($allPermissions, array_merge($dangerous, $withoutDeliveriesUpdate)));
        $managerRole = Role::firstOrCreate(
            ['name' => 'Manager', 'guard_name' => $guard],
            ['guard_name' => $guard]
        );
        $managerRole->syncPermissions($managerPerms);

        $staffPerms = [
            'products-list',
            'categories-list',
            'bundles-list',
            'attributes-list',
            'orders-out',
            'orders-in',
            'orders-create',
            'quotations-in',
            'quotations-out',
            'deals-in',
            'deals-out',
            'balance-in',
            'balance-out',
            'notifications-orders',
            'notifications-quotations',
            'invitations-list',
            'invitations-send-request',
            'supplier-routings-manage',
            'tickets-list',
            'tickets-create',
            'tickets-manage',
            'deliveries-list',
            'shipping-companies-list',
            'gateways-list',
            'suppliers-list',
            'suppliers-payments-list',
            'users-list',
            'users-pending-list',
            'users-create',
            'users-edit',
            'articles-list',
            'articles-create',
            'articles-edit',
        ];
        $staffRole = Role::firstOrCreate(
            ['name' => 'Staff', 'guard_name' => $guard],
            ['guard_name' => $guard]
        );
        $staffRole->syncPermissions($staffPerms);

        $merchantPerms = [
            'products-list',
            'products-create',
            'products-edit',
            'products-delete',
            'categories-list',
            'bundles-list',
            'attributes-list',
            'attributes-create',
            'attributes-edit',
            'attributes-delete',
            'orders-in',
            'orders-out',
            'orders-create',
            'quotations-in',
            'quotations-out',
            'deals-in',
            'deals-out',
            'balance-in',
            'balance-out',
            'notifications-orders',
            'notifications-quotations',
            'invitations-list',
            'invitations-send-request',
            'invitations-delete',
            'supplier-routings-manage',
            'tickets-list',
            'tickets-create',
            'tickets-manage',
            'deliveries-list',
            'suppliers-list',
            'verifications-request',
            // Storefront (their single store) — full feature set.
            'store.view',
            'store.products.manage',
            'store.categories.manage',
            'store.orders.manage',
            'store.coupons.manage',
            'store.reviews.manage',
            'store.analytics.view',
            'store.themes.manage',
            'store.pages.manage',
            'store.menus.manage',
            'store.settings.manage',
        ];
        $merchantRole = Role::firstOrCreate(
            ['name' => 'Merchant', 'guard_name' => $guard],
            ['guard_name' => $guard]
        );
        $merchantRole->syncPermissions($merchantPerms);

        $supplierPerms = [
            'products-list',
            'bundles-list',
            'products-create',
            'products-edit',
            'products-delete',
            'categories-list',
            'attributes-list',
            'attributes-create',
            'attributes-edit',
            'attributes-delete',
            'orders-in',
            'orders-out',
            'quotations-out',
            'deals-in',
            'balance-in',
            'notifications-orders',
            'notifications-quotations',
            'invitations-list',
            'invitations-send-request',
            'invitations-delete',
            'tickets-list',
            'tickets-create',
            'tickets-manage',
            'deliveries-list',
            'deliveries-update',
            'verifications-request',
            // Storefront (their single store) — reduced feature set:
            // overview, catalog, orders, analytics, themes, pages, settings.
            'store.view',
            'store.products.manage',
            'store.categories.manage',
            'store.orders.manage',
            'store.analytics.view',
            'store.themes.manage',
            'store.pages.manage',
            'store.settings.manage',
        ];
        $supplierRole = Role::firstOrCreate(
            ['name' => 'Supplier', 'guard_name' => $guard],
            ['guard_name' => $guard]
        );
        $supplierRole->syncPermissions($supplierPerms);

        $customerRole = Role::firstOrCreate(
            ['name' => 'Customer', 'guard_name' => $guard],
            ['guard_name' => $guard]
        );
        $customerRole->syncPermissions([]);

        $employeeRole = Role::firstOrCreate(
            ['name' => 'Employee', 'guard_name' => $guard],
            ['guard_name' => $guard]
        );
        // Employee inherits contextual scope via parent_user_id; default limited permissions.
        $employeeRole->syncPermissions([
            'orders-in',
            'orders-out',
            'quotations-in',
            'quotations-out',
            'deals-in',
            'deals-out',
            'balance-in',
            'balance-out',
            'notifications-orders',
            'notifications-quotations',
            'products-list',
            'categories-list',
            'bundles-list',
            'attributes-list',
            'suppliers-list',
            'deliveries-list',
            'tickets-list',
            'tickets-create',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
