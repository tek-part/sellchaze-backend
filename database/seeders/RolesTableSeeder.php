<?php

namespace Database\Seeders;

use App\Services\Stores\StoreManagers;
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

        $adminRole = Role::firstOrCreate(
            ['name' => 'Admin', 'guard_name' => $guard],
            ['guard_name' => $guard]
        );
        // Admin: every app permission.
        $adminRole->syncPermissions($allPermissions);

        $dangerous = ['roles-delete', 'permissions-delete', 'users-delete'];
        $managerPerms = array_values(array_diff($allPermissions, $dangerous));
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
            'categories-create',
            'categories-edit',
            'categories-delete',
            'bundles-list',
            'bundles-create',
            'bundles-edit',
            'bundles-delete',
            'attributes-list',
            'attributes-create',
            'attributes-edit',
            'attributes-delete',
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

        $customerRole = Role::firstOrCreate(
            ['name' => 'Customer', 'guard_name' => $guard],
            ['guard_name' => $guard]
        );
        $customerRole->syncPermissions([]);

        $employeeRole = Role::firstOrCreate(
            ['name' => 'Employee', 'guard_name' => $guard],
            ['guard_name' => $guard]
        );
        Role::firstOrCreate(['name' => StoreManagers::ROLE, 'guard_name' => $guard])->syncPermissions([]);

        // Employee inherits contextual scope via parent_user_id; default limited permissions.
        $employeeRole->syncPermissions([
            'products-list',
            'categories-list',
            'bundles-list',
            'attributes-list',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
