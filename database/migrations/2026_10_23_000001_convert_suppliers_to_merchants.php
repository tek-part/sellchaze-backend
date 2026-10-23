<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sellchaze is a store builder only: every Supplier account becomes a Merchant and keeps its store,
 * products and data. The Supplier role and the B2B-only permissions are removed. The B2B tables
 * themselves are dropped by a later migration.
 *
 * Irreversible by design: the role an account held before is not recorded anywhere else.
 */
return new class extends Migration
{
    /** Permissions that only gated the removed B2B screens and APIs. */
    private const B2B_PERMISSIONS = [
        'orders-in', 'orders-out', 'orders-create',
        'quotations-in', 'quotations-out',
        'deals-in', 'deals-out',
        'balance-in', 'balance-out',
        'notifications-orders', 'notifications-quotations',
        'invitations-list', 'invitations-send-request', 'invitations-delete',
        'supplier-routings-manage',
        'gateways-list', 'gateways-create', 'gateways-edit', 'gateways-delete', 'gateways-manage',
        'suppliers-list', 'suppliers-payments-list', 'suppliers-payments-manage',
        'deliveries-list', 'deliveries-update',
        'shipping-companies-list', 'shipping-companies-create', 'shipping-companies-edit', 'shipping-companies-delete',
        'tickets-list', 'tickets-create', 'tickets-manage',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        DB::transaction(function () {
            $userType = 'App\\Models\\User';
            $supplierRoleIds = DB::table('roles')->where('name', 'Supplier')->pluck('id', 'guard_name');

            foreach ($supplierRoleIds as $guard => $supplierRoleId) {
                $merchantRoleId = DB::table('roles')->where('name', 'Merchant')->where('guard_name', $guard)->value('id')
                    ?? DB::table('roles')->insertGetId(['name' => 'Merchant', 'guard_name' => $guard, 'created_at' => now(), 'updated_at' => now()]);

                $supplierIds = DB::table('model_has_roles')->where('role_id', $supplierRoleId)->where('model_type', $userType)->pluck('model_id');
                $alreadyMerchant = DB::table('model_has_roles')->where('role_id', $merchantRoleId)->where('model_type', $userType)
                    ->whereIn('model_id', $supplierIds)->pluck('model_id')->all();
                $rows = $supplierIds->diff($alreadyMerchant)
                    ->map(fn ($id) => ['role_id' => $merchantRoleId, 'model_type' => $userType, 'model_id' => $id])->values()->all();
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('model_has_roles')->insert($chunk);
                }

                DB::table('model_has_roles')->where('role_id', $supplierRoleId)->delete();
                DB::table('role_has_permissions')->where('role_id', $supplierRoleId)->delete();
                DB::table('roles')->where('id', $supplierRoleId)->delete();
            }

            DB::table('users')->whereRaw('LOWER(TRIM(registration_role)) = ?', ['supplier'])->update(['registration_role' => 'Merchant']);
            if (Schema::hasColumn('stores', 'owner_type')) {
                DB::table('stores')->where('owner_type', 'supplier')->update(['owner_type' => 'merchant']);
            }
            if (Schema::hasTable('organizations') && Schema::hasColumn('organizations', 'type')) {
                DB::table('organizations')->where('type', 'supplier')->update(['type' => 'merchant']);
            }

            $permissionIds = DB::table('permissions')->whereIn('name', self::B2B_PERMISSIONS)->pluck('id');
            DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Irreversible: former Supplier accounts cannot be told apart from merchants afterwards.
    }
};
