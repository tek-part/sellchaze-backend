<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * store.gift_cards.manage issues, adjusts and updates gift cards. Every role that already manages
 * discounts gets it, so nobody loses access; store managers and employees are granted it explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        $guard = config('auth.defaults.guard', 'web');
        $permissionId = DB::table('permissions')->where('name', 'store.gift_cards.manage')->where('guard_name', $guard)->value('id')
            ?? DB::table('permissions')->insertGetId(['name' => 'store.gift_cards.manage', 'guard_name' => $guard, 'created_at' => now(), 'updated_at' => now()]);
        $coupons = DB::table('permissions')->where('name', 'store.coupons.manage')->where('guard_name', $guard)->value('id');
        if ($coupons !== null) {
            foreach (DB::table('role_has_permissions')->where('permission_id', $coupons)->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        $ids = DB::table('permissions')->where('name', 'store.gift_cards.manage')->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
