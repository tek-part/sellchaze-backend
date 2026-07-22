<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Multi-store management permissions (the admin console that lists/creates/edits/
 * deletes ANY store). Under the single-store model a Merchant/Supplier owns exactly
 * one store and manages it via ownership (store.* feature permissions), so these
 * multi-store permissions are granted to Admin/Manager ONLY — never to owners.
 */
class StorePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $perms = ['stores-list', 'stores-create', 'stores-edit', 'stores-delete'];

        foreach ($perms as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Grant to the admin console roles only.
        foreach (['Admin', 'Manager'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo($perms);
            }
        }

        // Revoke from owner roles in case an earlier seed granted them (idempotent).
        foreach (['Merchant', 'Supplier'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role) {
                $role->revokePermissionTo($perms);
            }
        }

        Artisan::call('permission:cache-reset');
    }
}
