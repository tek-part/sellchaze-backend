<?php

namespace Tests\Feature;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Commerce\StoreOrderService;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BulkVariantOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Store $store;

    private User $owner;

    private string $path;

    private array $auth;

    private string $public = 'http://bulk-stock.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([BridgeStorefrontOrderJob::class]);
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Bulk', 'slug' => 'bulk-stock', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'bulk-stock.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->product = Product::create(['store_id' => $this->store->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true]);
        foreach (['Blue', 'Red'] as $name) {
            ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $this->product->id, 'name' => $name, 'price_override' => 50, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 5]);
        }
        $this->path = '/api/v1/my-store/catalog/products/'.$this->product->id.'/variants';
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($this->owner)];
    }

    private function targets(): array
    {
        return array_map(fn ($row) => ['id' => $row['id'], 'version' => $row['edit_version'], 'expected_stock' => $row['stock_quantity'],
            'expected_reserved' => $row['reserved_quantity'], 'expected_tracking' => $row['track_inventory']], $this->getJson($this->path, $this->auth)->assertOk()->json('data'));
    }

    public function test_set_increase_decrease_keep_editorial_data_and_record_each_inventory_movement(): void
    {
        foreach ([['set', 10, 10], ['increase', 3, 13], ['decrease', 2, 11]] as [$mode, $quantity, $after]) {
            $this->putJson($this->path.'/bulk/inventory', ['variants' => $this->targets(), 'mode' => $mode, 'quantity' => $quantity, 'tracking' => 'keep', 'note' => 'Batch count'], $this->auth)->assertOk()->assertJsonPath('meta.updated', 2);
            foreach ($this->targets() as $target) {
                $this->assertSame($after, $target['expected_stock']);
                $this->assertDatabaseHas('store_product_variants', ['id' => $target['id'], 'price_override' => 50, 'is_active' => true, 'reserved_quantity' => 0]);
            }
        }
        $this->assertDatabaseCount('store_inventory_movements', 6);
        $this->assertDatabaseHas('store_inventory_movements', ['stock_delta' => -2, 'stock_after' => 11, 'actor_id' => $this->owner->id, 'note' => 'Batch count']);
        $this->putJson($this->path.'/bulk/inventory', ['variants' => $this->targets(), 'mode' => 'set', 'quantity' => '0', 'tracking' => 'off'], $this->auth)->assertOk();
        $this->assertFalse($this->targets()[0]['expected_tracking']);
    }

    public function test_stale_stock_on_the_last_target_rejects_the_batch_and_does_not_append_history(): void
    {
        $targets = $this->targets();
        ProductVariant::withoutGlobalScopes()->whereKey($targets[1]['id'])->update(['reserved_quantity' => 1]);
        $input = ['variants' => $targets, 'mode' => 'increase', 'quantity' => 4, 'tracking' => 'keep'];
        $this->putJson($this->path.'/bulk/inventory', $input, $this->auth)->assertConflict();
        $this->deleteJson($this->path.'/bulk', ['variants' => $targets, 'confirm' => true], $this->auth)->assertConflict();
        $this->assertDatabaseHas('store_product_variants', ['id' => $targets[0]['id'], 'stock_quantity' => 5]);
        $this->assertDatabaseCount('store_inventory_movements', 0);
        $this->assertDatabaseCount('store_product_variants', 2);
    }

    public function test_invalid_result_and_reserved_last_target_roll_back_prior_updates_and_movements(): void
    {
        $targets = $this->targets();
        ProductVariant::withoutGlobalScopes()->whereKey($targets[1]['id'])->update(['reserved_quantity' => 3]);
        foreach ([['set', 2, 'keep'], ['set', 5, 'off'], ['decrease', 6, 'keep'], ['increase', 100000000, 'keep']] as [$mode, $quantity, $tracking]) {
            $this->putJson($this->path.'/bulk/inventory', ['variants' => $this->targets(), 'mode' => $mode, 'quantity' => $quantity, 'tracking' => $tracking], $this->auth)->assertUnprocessable();
            $this->assertDatabaseCount('store_inventory_movements', 0);
            $this->assertDatabaseHas('store_product_variants', ['id' => $targets[0]['id'], 'stock_quantity' => 5]);
        }
        $this->deleteJson($this->path.'/bulk', ['variants' => $this->targets(), 'confirm' => true], $this->auth)->assertUnprocessable();
        $this->assertDatabaseCount('store_inventory_movements', 0);
        $this->assertDatabaseCount('store_product_variants', 2);
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'is_active' => true]);
    }

    public function test_foreign_targets_stale_editorial_versions_and_invalid_payloads_are_rejected(): void
    {
        $targets = $this->targets();
        $input = ['variants' => $targets, 'mode' => 'set', 'quantity' => 7, 'tracking' => 'on'];
        foreach ([-1, 0.5, 100000001] as $quantity) {
            $this->putJson($this->path.'/bulk/inventory', array_merge($input, ['quantity' => $quantity]), $this->auth)->assertUnprocessable();
        }
        $this->putJson($this->path.'/bulk/inventory', array_merge($input, ['variants' => [$targets[0], $targets[0]]]), $this->auth)->assertUnprocessable();
        $this->deleteJson($this->path.'/bulk', ['variants' => $targets], $this->auth)->assertUnprocessable();
        ProductVariant::withoutGlobalScopes()->whereKey($targets[1]['id'])->update(['name' => 'New red']);
        $this->putJson($this->path.'/bulk/inventory', $input, $this->auth)->assertUnprocessable();
        $this->deleteJson($this->path.'/bulk', ['variants' => $targets, 'confirm' => true], $this->auth)->assertUnprocessable();
        $targets = $this->targets();
        $other = Product::create(['store_id' => $this->store->id, 'name' => 'Other', 'slug' => 'other', 'price' => 1]);
        ProductVariant::withoutGlobalScopes()->whereKey($targets[1]['id'])->update(['store_product_id' => $other->id]);
        $this->putJson($this->path.'/bulk/inventory', array_merge($input, ['variants' => $targets]), $this->auth)->assertUnprocessable();
        $this->deleteJson($this->path.'/bulk', ['variants' => $targets, 'confirm' => true], $this->auth)->assertUnprocessable();
        $this->assertDatabaseCount('store_inventory_movements', 0);
        $this->assertDatabaseCount('store_product_variants', 2);
    }

    public function test_deletion_keeps_order_snapshots_and_audits_stock_then_hides_the_empty_product(): void
    {
        $targets = $this->targets();
        $orderId = $this->postJson($this->public.'/checkout', ['customer_name' => 'Buyer', 'customer_email' => 'buyer@example.test', 'items' => [['product_id' => $this->product->id, 'variant_id' => $targets[0]['id'], 'quantity' => 1]]])->assertCreated()->json('data.id');
        app(CurrentStore::class)->set($this->store);
        $order = StoreOrder::findOrFail($orderId);
        foreach (['confirmed', 'processing', 'shipped'] as $status) {
            app(StoreOrderService::class)->transition($order, $status);
        }
        $this->deleteJson($this->path.'/bulk', ['variants' => $this->targets(), 'confirm' => true, 'note' => 'Retired colors'], $this->auth)->assertOk()->assertJsonPath('meta.deleted', 2)->assertJsonPath('meta.product_active', false);
        $this->assertDatabaseCount('store_product_variants', 0);
        $this->assertDatabaseHas('store_order_items', ['store_order_id' => $orderId, 'variant_id' => $targets[0]['id'], 'variant_name' => 'Blue', 'inventory_status' => 'committed']);
        $this->assertDatabaseHas('store_inventory_movements', ['variant_id' => $targets[0]['id'], 'reason' => 'variant_deleted', 'stock_delta' => -4, 'stock_after' => 0, 'note' => 'Blue — Retired colors']);
        $this->getJson($this->public.'/products/bag')->assertNotFound();
        $this->deleteJson($this->path.'/bulk', ['variants' => $targets, 'confirm' => true], $this->auth)->assertUnprocessable();
        $this->assertDatabaseCount('store_inventory_movements', 4);
    }

    public function test_partial_and_single_deletion_have_consistent_final_variant_protection(): void
    {
        $targets = $this->targets();
        $this->deleteJson($this->path.'/bulk', ['variants' => [$targets[0]], 'confirm' => true], $this->auth)->assertOk()->assertJsonPath('meta.product_active', true);
        $this->assertDatabaseCount('store_product_variants', 1);
        $this->deleteJson($this->path.'/'.$targets[1]['id'], [], $this->auth)->assertOk()->assertJsonPath('meta.product_active', false);
        $this->assertDatabaseCount('store_inventory_movements', 2);
    }

    public function test_bulk_inventory_and_deletion_use_separate_permissions(): void
    {
        $targets = $this->targets();
        $role = $this->owner->roles()->firstOrFail();
        $role->revokePermissionTo('products-delete');
        $this->deleteJson($this->path.'/bulk', ['variants' => $targets, 'confirm' => true], $this->auth)->assertForbidden();
        $this->putJson($this->path.'/bulk/inventory', ['variants' => $targets, 'mode' => 'set', 'quantity' => 5, 'tracking' => 'keep'], $this->auth)->assertOk();
        $role->givePermissionTo('products-delete');
        $role->revokePermissionTo('products-edit');
        $this->putJson($this->path.'/bulk/inventory', ['variants' => $targets, 'mode' => 'set', 'quantity' => 5, 'tracking' => 'keep'], $this->auth)->assertForbidden();
        $this->deleteJson($this->path.'/bulk', ['variants' => $targets, 'confirm' => true], $this->auth)->assertOk();
    }
}
