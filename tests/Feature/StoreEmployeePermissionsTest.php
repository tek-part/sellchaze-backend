<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StoreEmployeePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private StoreOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'S', 'slug' => 'emp-s', 'currency' => 'EGP', 'status' => 'active']);
        app(CurrentStore::class)->set($this->store);
        $product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'P', 'slug' => 'p', 'price' => 100, 'is_active' => true]);
        $this->order = StoreOrder::create(['store_id' => $this->store->id, 'order_number' => 'X-1', 'customer_name' => 'Cust', 'customer_email' => 'c@example.test', 'customer_phone' => '+201000000000',
            'status' => 'pending', 'currency' => 'EGP', 'subtotal' => 100, 'grand_total' => 100, 'payment_method' => 'cod', 'payment_status' => 'pending', 'placed_at' => now()]);
        $this->order->items()->create(['store_id' => $this->store->id, 'store_product_id' => $product->id, 'name' => 'P', 'unit_price' => 100, 'quantity' => 1, 'line_total' => 100]);
        app(CurrentStore::class)->forget();
    }

    private function employee(array $permissions = []): array
    {
        $employee = User::factory()->create(['parent_user_id' => $this->owner->id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        if ($permissions) {
            $employee->givePermissionTo($permissions);
        }

        return ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee)];
    }

    private function base(string $path = ''): string
    {
        return '/api/v1/stores/'.$this->store->id.$path;
    }

    public function test_employee_without_store_grants_cannot_touch_orders_payments_or_coupons(): void
    {
        $h = $this->employee();

        $this->getJson($this->base('/orders'), $h)->assertForbidden();
        $this->getJson($this->base('/orders/'.$this->order->id), $h)->assertForbidden();
        $this->patchJson($this->base('/orders/'.$this->order->id.'/status'), ['status' => 'cancelled'], $h)->assertForbidden();
        $this->putJson($this->base('/payments'), ['gateways' => [['slug' => 'transfer_receipt', 'enabled' => true,
            'credentials' => ['account_name' => 'Attacker', 'iban' => 'EG000ATTACKER']]]], $h)->assertForbidden();
        $this->postJson($this->base('/coupons'), ['code' => 'FREE100', 'type' => 'percentage', 'value' => 100, 'is_active' => true], $h)->assertForbidden();

        $this->assertSame('pending', $this->order->fresh()->status);
        $this->assertNull(StorePaymentGateway::where('store_id', $this->store->id)->where('gateway', 'transfer_receipt')->first());
        $this->assertFalse(Coupon::withoutGlobalScopes()->where('code', 'FREE100')->exists());
    }

    public function test_order_grant_allows_orders_only(): void
    {
        $h = $this->employee(['store.orders.manage']);

        $this->getJson($this->base('/orders'), $h)->assertOk();
        $this->patchJson($this->base('/orders/'.$this->order->id.'/status'), ['status' => 'confirmed'], $h)->assertOk();
        $this->putJson($this->base('/payments'), ['gateways' => []], $h)->assertForbidden();
        $this->postJson($this->base('/coupons'), ['code' => 'NOPE', 'type' => 'percentage', 'value' => 10, 'is_active' => true], $h)->assertForbidden();
    }

    public function test_catalog_keeps_its_legacy_per_route_permissions_for_employees(): void
    {
        // The Employee role carries products-list by default; creating still needs products-create.
        $h = $this->employee();
        $this->getJson($this->base('/catalog/products'), $h)->assertOk();
        $this->postJson($this->base('/catalog/products'), ['name' => 'X', 'price' => 10], $h)->assertForbidden();
    }

    public function test_the_owner_is_unaffected(): void
    {
        $h = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($this->owner)];
        $this->getJson($this->base('/orders'), $h)->assertOk();
        $this->getJson('/api/v1/my-store/orders', $h)->assertOk();
    }
}
