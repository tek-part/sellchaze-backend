<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderExport;
use App\Models\StoreOrderWebhook;
use App\Models\StoreOrderWebhookDelivery;
use App\Models\User;
use App\Services\Commerce\StoreInventory;
use App\Services\Commerce\StoreOrderExports;
use App\Services\Commerce\StoreOrderService;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Lifecycle fixture', 'slug' => 'lifecycle-fixture', 'status' => 'active', 'currency' => 'USD']);
        app(CurrentStore::class)->set($this->store);
    }

    private function url(string $tail = ''): string
    {
        return '/api/v1/stores/'.$this->store->id.'/orders'.$tail;
    }

    private function order(array $attributes = []): StoreOrder
    {
        return StoreOrder::create(array_merge(['store_id' => $this->store->id, 'order_number' => 'LIFECYCLE-'.Str::uuid(),
            'status' => 'pending', 'currency' => 'USD', 'customer_name' => 'Synthetic customer', 'customer_email' => 'lifecycle@example.test',
            'subtotal' => '20.00', 'grand_total' => '20.00', 'payment_method' => 'cod', 'payment_status' => 'pending', 'placed_at' => now()], $attributes));
    }

    private function stock(StoreOrder $order): Product
    {
        $product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Tracked fixture', 'slug' => 'tracked-'.Str::random(8),
            'price' => 10, 'track_inventory' => true, 'stock_quantity' => 10, 'reserved_quantity' => 0]);
        $item = $order->items()->create(['store_id' => $this->store->id, 'store_product_id' => $product->id, 'name' => 'Tracked snapshot', 'unit_price' => 10, 'quantity' => 2, 'line_total' => 20]);
        DB::transaction(fn () => app(StoreInventory::class)->reserve($product, $item));

        return $product;
    }

    private function transition(StoreOrder $order, string $to): void
    {
        $this->patchJson($this->url('/'.$order->id.'/status'), ['status' => $to])->assertOk()->assertJsonPath('data.status', $to);
        $order->refresh();
    }

    public function test_pickup_delivery_and_customer_return_keep_payment_truth_and_stock_ledger(): void
    {
        $order = $this->order(['payment_status' => 'paid', 'payment_reference' => 'FIXTURE-PAID']);
        $stock = $this->stock($order);
        foreach (['confirmed', 'processing', 'waiting_for_pickup'] as $to) {
            $this->transition($order, $to);
            $this->assertSame(10, $stock->fresh()->stock_quantity);
            $this->assertSame(2, $stock->fresh()->reserved_quantity);
        }
        $this->transition($order, 'shipped');
        $this->assertSame(8, $stock->fresh()->stock_quantity);
        $this->assertSame(0, $stock->fresh()->reserved_quantity);
        foreach (['delivered', 'request_refund', 'refund_in_progress', 'refunded'] as $to) {
            $this->transition($order, $to);
            $this->assertSame('paid', $order->payment_status);
            $this->assertSame('FIXTURE-PAID', $order->payment_reference);
            $this->assertSame(8, $stock->fresh()->stock_quantity);
        }
        $this->assertDatabaseCount('store_inventory_movements', 2);
        $this->assertDatabaseCount('store_order_status_changes', 8);
        $this->assertDatabaseCount('store_payment_transactions', 0);
        $this->assertDatabaseCount('platform_wallet_entries', 0);
        $this->getJson($this->url('?status=refunded'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson($this->url('/'.$order->id.'/archive'), ['archived' => true, 'expected_updated_at' => $order->updated_at->toIso8601String()])->assertOk();
        Http::assertNothingSent();
    }

    public function test_failed_delivery_return_records_long_code_without_restock_or_duplicate_webhooks(): void
    {
        $order = $this->order(['status' => 'processing']);
        $stock = $this->stock($order);
        StoreOrderWebhook::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'request_key' => Str::uuid(),
            'url' => 'https://hooks.example.test/orders', 'event_type' => 'order-status-update', 'secret' => 'fixture-secret', 'enabled' => true, 'version' => 1]);
        foreach (['shipped', 'returning_from_delivery', 'refunded'] as $to) {
            $this->transition($order, $to);
        }
        $this->assertSame(8, $stock->fresh()->stock_quantity);
        $this->assertSame('pending', $order->payment_status);
        $this->assertDatabaseHas('store_order_status_changes', ['from_status' => 'shipped', 'to_status' => 'returning_from_delivery']);
        $this->assertSame('returning_from_delivery', StoreOrderWebhookDelivery::orderBy('id')->skip(1)->firstOrFail()->payload['new_status']);
        $payload = ['status' => 'refunded', 'eligible_only' => true, 'orders' => [['id' => $order->id, 'status' => 'returning_from_delivery']]];
        $this->postJson($this->url('/bulk-status'), $payload)->assertOk()->assertJsonPath('updated', 0)->assertJsonPath('unchanged', 1);
        $this->assertDatabaseCount('store_order_webhook_deliveries', 3);
        $this->assertDatabaseCount('store_inventory_movements', 2);
        Http::assertNothingSent();
    }

    public function test_cancel_at_pickup_releases_reservation_once_and_digital_orders_cannot_enter_physical_return_workflow(): void
    {
        $order = $this->order(['status' => 'waiting_for_pickup']);
        $stock = $this->stock($order);
        $this->transition($order, 'cancelled');
        $this->assertSame(10, $stock->fresh()->stock_quantity);
        $this->assertSame(0, $stock->fresh()->reserved_quantity);
        $digital = $this->order(['status' => 'delivered', 'payment_status' => 'paid']);
        $digital->items()->create(['store_id' => $this->store->id, 'name' => 'Digital fixture', 'unit_price' => 20, 'quantity' => 1, 'line_total' => 20,
            'digital_delivery' => ['type' => 'codes', 'values' => ['FIXTURE-ONLY']]]);
        $this->patchJson($this->url('/'.$digital->id.'/status'), ['status' => 'request_refund'])->assertUnprocessable();
        $this->assertSame([], app(StoreOrderService::class)->nextStatuses($digital));
        $this->patchJson($this->url('/'.$order->id.'/status'), ['status' => 'paid'])->assertUnprocessable();
    }

    public function test_bulk_eligible_mode_skips_illegal_transitions_but_stale_or_foreign_rows_keep_batch_atomic(): void
    {
        $eligible = $this->order(['status' => 'processing']);
        $ineligible = $this->order(['status' => 'pending']);
        $payload = ['status' => 'waiting_for_pickup', 'eligible_only' => true, 'orders' => [
            ['id' => $eligible->id, 'status' => 'confirmed'], ['id' => $ineligible->id, 'status' => 'pending']]];
        $this->postJson($this->url('/bulk-status'), $payload)->assertStatus(409);
        $this->assertSame('processing', $eligible->fresh()->status);
        $payload['orders'][0]['status'] = 'processing';
        $this->postJson($this->url('/bulk-status'), $payload)->assertOk()->assertJsonPath('updated', 1)->assertJsonPath('skipped', 1);
        $this->postJson($this->url('/bulk-status'), $payload)->assertOk()->assertJsonPath('updated', 0)->assertJsonPath('unchanged', 1)->assertJsonPath('skipped', 1);
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Foreign', 'slug' => 'lifecycle-foreign', 'status' => 'active']);
        $foreign = $this->order(['store_id' => $other->id]);
        $payload['status'] = 'cancelled';
        $payload['orders'][0]['status'] = 'waiting_for_pickup';
        $payload['orders'][] = ['id' => $foreign->id, 'status' => 'pending'];
        $this->postJson($this->url('/bulk-status'), $payload)->assertNotFound();
        $this->assertSame('waiting_for_pickup', $eligible->fresh()->status);
        $this->assertDatabaseCount('store_order_status_changes', 1);
    }

    public function test_risk_tabs_and_bulk_classification_are_versioned_private_and_independent_of_fulfillment(): void
    {
        $one = $this->order();
        $two = $this->order(['status' => 'delivered', 'payment_status' => 'paid']);
        $before = [$one->fresh()->getAttributes(), $two->fresh()->getAttributes()];
        $payload = ['status' => 'high_risk', 'eligible_only' => true, 'orders' => [
            ['id' => $one->id, 'status' => 'pending', 'review_version' => 0], ['id' => $two->id, 'status' => 'delivered', 'review_version' => 0]]];
        $this->postJson($this->url('/bulk-status'), $payload)->assertOk()->assertJsonPath('updated', 2);
        $this->postJson($this->url('/bulk-status'), $payload)->assertOk()->assertJsonPath('updated', 0)->assertJsonPath('unchanged', 2);
        $this->getJson($this->url('?classification=high_risk'))->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->url('?classification=moderate_risk'))->assertOk()->assertJsonPath('meta.total', 0);
        $payload['status'] = 'moderate_risk';
        $payload['orders'][0]['review_version'] = 1;
        $this->postJson($this->url('/bulk-status'), $payload)->assertStatus(409);
        $this->assertDatabaseMissing('store_order_reviews', ['classification' => 'moderate_risk']);
        $this->assertSame($before, [$one->fresh()->getAttributes(), $two->fresh()->getAttributes()]);
        $this->assertDatabaseCount('store_order_status_changes', 2);
        $this->assertDatabaseCount('store_inventory_movements', 0);
        $this->assertDatabaseCount('store_payment_transactions', 0);
        $this->getJson($this->url('?classification=unknown'))->assertUnprocessable();
    }

    public function test_return_and_risk_filters_are_preserved_by_real_exports(): void
    {
        $returned = $this->order(['status' => 'returning_from_delivery', 'customer_name' => 'Export this fixture']);
        $this->order(['status' => 'pending', 'customer_name' => 'Exclude this fixture']);
        $this->postJson($this->url('/'.$returned->id.'/review'), ['classification' => 'moderate_risk', 'version' => 0])->assertOk();
        $id = $this->postJson($this->url('/exports'), ['date_from' => now()->subDay()->format('Y-m-d'), 'date_to' => now()->format('Y-m-d'),
            'status' => 'returning_from_delivery', 'classification' => 'moderate_risk', 'request_key' => Str::uuid()])->assertAccepted()->json('data.id');
        $this->assertSame(1, app(StoreOrderExports::class)->process());
        $this->assertSame(1, StoreOrderExport::findOrFail($id)->row_count);
        $csv = $this->get($this->url('/exports/'.$id.'/download'))->assertOk()->getContent();
        $this->assertStringContainsString('returning_from_delivery', $csv);
        $this->assertStringContainsString('Export this fixture', $csv);
        $this->assertStringNotContainsString('Exclude this fixture', $csv);
    }

    public function test_lifecycle_migration_rollback_preserves_populated_timeline(): void
    {
        $order = $this->order(['status' => 'pending']);
        $order->statusChanges()->create(['store_id' => $this->store->id, 'from_status' => 'returning_from_delivery', 'to_status' => 'refunded']);
        $migration = require database_path('migrations/2026_10_18_040000_expand_store_order_lifecycle_statuses.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
