<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\Scopes\ProductScope;
use App\Models\Store;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Stores\StoreProvisioner;
use App\Services\StoreService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\StorePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 2 — single-store ownership. Verifies the authoritative rule:
 * one Merchant = one Store, one Supplier = one Store, Admin manages all.
 * Covers auto-provisioning, idempotency, the /my-store auto-context, the
 * service/policy/DB cardinality guards, and employee store inheritance.
 */
class SingleStoreOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        $this->seed(StorePermissionsSeeder::class);
    }

    private function owner(string $role = 'Merchant'): User
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole($role);

        return $user;
    }

    private function asUser(User $user): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    // ---- Auto-provisioning + /my-store auto-context -------------------------

    public function test_merchant_auto_provisions_a_store_on_first_my_store_access(): void
    {
        $merchant = $this->owner('Merchant');
        $this->assertNull($merchant->store, 'merchant should start with no store');

        $this->asUser($merchant)
            ->getJson('/api/v1/my-store/coupons')
            ->assertOk();

        $store = $merchant->fresh()->store;
        $this->assertNotNull($store, 'a store must be auto-provisioned');
        $this->assertSame('merchant', $store->owner_type);
        $this->assertSame($merchant->id, (int) $store->owner_user_id);
    }

    public function test_supplier_auto_provisions_a_supplier_typed_store(): void
    {
        $supplier = $this->owner('Supplier');

        $this->asUser($supplier)->getJson('/api/v1/my-store/coupons')->assertOk();

        $this->assertSame('supplier', $supplier->fresh()->store->owner_type);
    }

    public function test_my_store_resolves_to_the_owners_store_and_stays_isolated(): void
    {
        $a = $this->owner('Merchant');
        $b = $this->owner('Merchant');

        // Each owner's /my-store auto-provisions and resolves to their OWN store.
        $this->asUser($a)->getJson('/api/v1/my-store/coupons')->assertOk();
        $this->asUser($b)->getJson('/api/v1/my-store/coupons')->assertOk();

        $storeA = $a->fresh()->store;
        $storeB = $b->fresh()->store;

        $this->assertNotNull($storeA);
        $this->assertNotNull($storeB);
        $this->assertNotSame($storeA->id, $storeB->id, 'each owner gets their own store');
        $this->assertSame($a->id, (int) $storeA->owner_user_id);
        $this->assertSame($b->id, (int) $storeB->owner_user_id);

        // The unified catalog is owner-isolated (store-less, keyed on user_id).
        Product::create(['user_id' => $a->id, 'name' => 'A-Item', 'slug' => 'a-item', 'price' => 10, 'is_active' => true]);
        Product::create(['user_id' => $b->id, 'name' => 'B-Item', 'slug' => 'b-item', 'price' => 10, 'is_active' => true]);
        $this->assertSame(1, Product::withoutGlobalScope(ProductScope::class)->where('user_id', $a->id)->count());
        $this->assertSame(1, Product::withoutGlobalScope(ProductScope::class)->where('user_id', $b->id)->count());
    }

    public function test_admin_has_no_own_store_and_is_refused_my_store(): void
    {
        $admin = $this->owner('Admin');

        $this->asUser($admin)->getJson('/api/v1/my-store/coupons')->assertForbidden();
        $this->assertNull($admin->fresh()->store);
    }

    // ---- Provisioner semantics ---------------------------------------------

    public function test_provisioner_is_idempotent(): void
    {
        $merchant = $this->owner('Merchant');
        $provisioner = app(StoreProvisioner::class);

        $first = $provisioner->ensureFor($merchant);
        $second = $provisioner->ensureFor($merchant);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Store::query()->where('owner_user_id', $merchant->id)->count());
    }

    public function test_provisioner_returns_null_for_non_owner_roles(): void
    {
        $provisioner = app(StoreProvisioner::class);

        $this->assertNull($provisioner->ensureFor($this->owner('Admin')));
        $this->assertNull($provisioner->ensureFor($this->owner('Staff')));
    }

    public function test_employee_inherits_parent_owner_store(): void
    {
        $merchant = $this->owner('Merchant');
        $parentStore = app(StoreProvisioner::class)->ensureFor($merchant);

        $employee = User::factory()->create(['is_active' => true, 'pending_approval' => false, 'parent_user_id' => $merchant->id]);
        $employee->assignRole('Employee');

        $resolved = app(StoreProvisioner::class)->ensureFor($employee);

        $this->assertNotNull($resolved);
        $this->assertSame($parentStore->id, $resolved->id);
        $this->assertSame(1, Store::query()->count(), 'employee must not spawn a second store');
    }

    // ---- Legacy cardinality guards + v2-compatible database ----------------

    public function test_service_rejects_a_second_store_for_the_same_owner(): void
    {
        $merchant = $this->owner('Merchant');
        app(StoreProvisioner::class)->ensureFor($merchant);

        // createForOwner is the auth-independent path the provisioner uses; its
        // one-owner-one-store guard must reject a second store.
        $this->expectException(ValidationException::class);
        app(StoreService::class)->createForOwner($merchant, ['name' => 'Second Store']);
    }

    public function test_policy_allows_first_store_but_blocks_a_second(): void
    {
        // The policy resolves the owner via the auth context (as in a real
        // request), so establish it before checking the gate.
        $merchant = $this->owner('Merchant');
        $this->actingAs($merchant);
        $this->assertTrue($merchant->can('create', Store::class), 'may create the first store');

        app(StoreProvisioner::class)->ensureFor($merchant);

        $this->assertFalse($merchant->can('create', Store::class), 'must never create a second store');
    }

    public function test_database_allows_company_to_have_multiple_stores_for_same_owner(): void
    {
        $merchant = $this->owner('Merchant');
        $organization = Organization::create(['name' => 'Multi Store Co', 'slug' => 'multi-store-co']);
        $organization->memberships()->create([
            'user_id' => $merchant->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        Store::create(['organization_id' => $organization->id, 'owner_user_id' => $merchant->id, 'owner_type' => 'merchant', 'is_primary' => true, 'name' => 'One', 'slug' => 'one', 'currency' => 'USD', 'status' => 'active']);
        Store::create(['organization_id' => $organization->id, 'owner_user_id' => $merchant->id, 'owner_type' => 'merchant', 'is_primary' => false, 'name' => 'Two', 'slug' => 'two', 'currency' => 'USD', 'status' => 'active']);

        $this->assertSame(2, $organization->stores()->count());
        $this->assertSame('One', $merchant->fresh()->store->name, 'v1 resolves the primary store');
    }

    // ---- Consistency: owners are walled off from the multi-store surface -----

    public function test_owner_roles_have_no_multi_store_permissions(): void
    {
        foreach (['Merchant', 'Supplier'] as $roleName) {
            $perms = Role::query()
                ->where('name', $roleName)->firstOrFail()
                ->permissions->pluck('name');

            $this->assertEmpty(
                $perms->filter(fn ($p) => str_starts_with($p, 'stores-')),
                "{$roleName} must not hold any multi-store (stores-*) permission",
            );
            $this->assertContains(
                'store.products.manage',
                $perms->all(),
                "{$roleName} must hold the single-store management matrix",
            );
        }
    }

    public function test_merchant_is_forbidden_from_the_admin_multi_store_list(): void
    {
        // Owners (single-store) must never reach the admin /stores list — the
        // route is gated by permission:stores-list (Admin/Manager only).
        $merchant = $this->owner('Merchant');
        $this->asUser($merchant)->getJson('/api/v1/stores')->assertForbidden();
    }

    public function test_admin_can_reach_the_multi_store_list(): void
    {
        $admin = $this->owner('Admin');
        $this->asUser($admin)->getJson('/api/v1/stores')->assertOk();
    }
}
