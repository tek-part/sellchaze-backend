<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Admin was previously seeded without deliveries-update; SPA shipping routes require it.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = config('auth.defaults.guard', 'web');
        $admin = Role::query()->where('name', 'Admin')->where('guard_name', $guard)->first();
        if (! $admin) {
            return;
        }

        $all = Permission::query()->where('guard_name', $guard)->pluck('name')->all();
        $admin->syncPermissions($all);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally empty: do not strip permissions on rollback.
    }
};
