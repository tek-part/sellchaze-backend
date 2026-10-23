<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupplierConversionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runConversion(): void
    {
        $migration = require database_path('migrations/2026_10_23_000001_convert_suppliers_to_merchants.php');
        $migration->up();
    }

    public function test_suppliers_become_merchants_and_keep_their_store_while_b2b_permissions_go(): void
    {
        $this->seed(RolesTableSeeder::class);
        $supplierRole = Role::create(['name' => 'Supplier', 'guard_name' => 'web']);
        $legacy = Permission::create(['name' => 'quotations-out', 'guard_name' => 'web']);
        $supplierRole->givePermissionTo($legacy);

        $supplier = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $supplier->assignRole('Supplier');
        $store = Store::create(['owner_user_id' => $supplier->id, 'owner_type' => 'supplier', 'name' => 'Delta', 'slug' => 'delta-conv', 'status' => 'active']);
        $both = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $both->assignRole(['Supplier', 'Merchant']);
        $pending = User::factory()->create(['pending_approval' => true, 'registration_role' => 'supplier']);
        $merchant = User::factory()->create();
        $merchant->assignRole('Merchant');
        $merchant->givePermissionTo($legacy);

        $this->runConversion();

        $this->assertTrue($supplier->fresh()->hasRole('Merchant'));
        $this->assertFalse(Role::query()->where('name', 'Supplier')->exists());
        $this->assertSame(1, DB::table('model_has_roles')->where('model_id', $both->id)->count());
        $this->assertTrue($both->fresh()->hasRole('Merchant'));
        $this->assertSame('merchant', $store->fresh()->owner_type);
        $this->assertSame((int) $supplier->id, (int) $store->fresh()->owner_user_id);
        $this->assertSame('Merchant', $pending->fresh()->registration_role);
        $this->assertTrue($pending->fresh()->pending_approval);
        $this->assertFalse(Permission::query()->where('name', 'quotations-out')->exists());
        $this->assertSame(0, DB::table('model_has_permissions')->where('model_id', $merchant->id)->count());

        $this->runConversion();
        $this->assertTrue($supplier->fresh()->hasRole('Merchant'));
    }
}
