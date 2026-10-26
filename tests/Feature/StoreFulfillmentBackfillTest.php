<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Support\Commerce\FulfillmentBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** Counters for orders placed before fulfillments existed, filled in small idempotent chunks. */
class StoreFulfillmentBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Backfill fixture', 'slug' => 'backfill', 'status' => 'active', 'currency' => 'EGP']);
    }

    /** A pre-feature order: raw rows with zero counters, as the schema migration leaves them. */
    private function legacyOrder(string $status, array $lines, bool $trashed = false): int
    {
        $id = DB::table('store_orders')->insertGetId(['store_id' => $this->store->id, 'order_number' => 'LEG-'.$status.'-'.uniqid(), 'status' => $status,
            'currency' => 'EGP', 'customer_name' => 'Synthetic buyer', 'subtotal' => 10, 'grand_total' => 10, 'payment_method' => 'cod', 'payment_status' => 'pending', 'fulfillment_status' => 'unfulfilled',
            'deleted_at' => $trashed ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($lines as $line) {
            DB::table('store_order_items')->insert(array_merge(['store_id' => $this->store->id, 'store_order_id' => $id, 'name' => 'Line', 'unit_price' => 5,
                'quantity' => 2, 'line_total' => 10, 'inventory_status' => null, 'digital_delivery' => null, 'superseded_at' => null,
                'fulfilled_quantity' => 0, 'released_quantity' => 0, 'created_at' => now(), 'updated_at' => now()], $line));
        }

        return $id;
    }

    /** @return list<array{0:int,1:int}> fulfilled/released per line, in id order */
    private function counters(int $orderId): array
    {
        return DB::table('store_order_items')->where('store_order_id', $orderId)->orderBy('id')->get(['fulfilled_quantity', 'released_quantity'])
            ->map(fn ($row) => [(int) $row->fulfilled_quantity, (int) $row->released_quantity])->all();
    }

    private function fulfillmentStatus(int $orderId): string
    {
        return (string) DB::table('store_orders')->where('id', $orderId)->value('fulfillment_status');
    }

    public function test_backfill_covers_every_status_archived_digital_and_released_lines_and_is_idempotent(): void
    {
        $orders = [];
        foreach (['pending', 'confirmed', 'processing', 'waiting_for_pickup'] as $status) {
            $orders[$status] = $this->legacyOrder($status, [['inventory_status' => 'reserved'], ['inventory_status' => null]]);
        }
        foreach (['shipped', 'delivered', 'returning_from_delivery', 'request_refund', 'refund_in_progress', 'refunded'] as $status) {
            $orders[$status] = $this->legacyOrder($status, [['inventory_status' => 'committed'], ['inventory_status' => null]]);
        }
        $orders['cancelled'] = $this->legacyOrder('cancelled', [['inventory_status' => 'released'], ['inventory_status' => null]]);
        $orders['archived'] = $this->legacyOrder('delivered', [['inventory_status' => 'committed']], true);
        $orders['digital'] = $this->legacyOrder('delivered', [['digital_delivery' => 'encrypted-snapshot', 'inventory_status' => 'committed']]);
        // An order edit removed a line (quantity 0, released) and superseded another.
        $orders['edited'] = $this->legacyOrder('processing', [['quantity' => 0, 'inventory_status' => 'released'], ['superseded_at' => now()], ['inventory_status' => 'reserved']]);
        $orders['edited_shipped'] = $this->legacyOrder('delivered', [['superseded_at' => now(), 'inventory_status' => 'committed'], ['inventory_status' => 'committed']]);
        // Already handled by the new code: one of two units shipped through a fulfillment.
        $orders['owned'] = $this->legacyOrder('shipped', [['inventory_status' => 'reserved', 'fulfilled_quantity' => 1]]);
        DB::table('store_order_fulfillments')->insert(['store_id' => $this->store->id, 'store_order_id' => $orders['owned'], 'status' => 'in_transit', 'carrier' => 'other',
            'items' => json_encode([]), 'notify_customer' => false, 'source' => 'manual', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('store_orders')->where('id', $orders['owned'])->update(['fulfillment_status' => 'partial']);

        $first = app(FulfillmentBackfill::class)->run(3);
        $this->assertSame(count($orders), $first['orders']);

        foreach (['pending', 'confirmed', 'processing', 'waiting_for_pickup'] as $status) {
            $this->assertSame([[0, 0], [0, 0]], $this->counters($orders[$status]), $status);
            $this->assertSame('unfulfilled', $this->fulfillmentStatus($orders[$status]), $status);
        }
        foreach (['shipped', 'delivered', 'returning_from_delivery', 'request_refund', 'refund_in_progress', 'refunded'] as $status) {
            $this->assertSame([[2, 0], [2, 0]], $this->counters($orders[$status]), $status);
            $this->assertSame('fulfilled', $this->fulfillmentStatus($orders[$status]), $status);
        }
        $this->assertSame([[0, 2], [0, 2]], $this->counters($orders['cancelled']));
        $this->assertSame('unfulfilled', $this->fulfillmentStatus($orders['cancelled']));
        $this->assertSame([[2, 0]], $this->counters($orders['archived']), 'Archived (soft-deleted) orders are included.');
        $this->assertSame('fulfilled', $this->fulfillmentStatus($orders['archived']));
        $this->assertSame([[0, 0]], $this->counters($orders['digital']), 'Digital lines never ship through fulfillments.');
        $this->assertSame('fulfilled', $this->fulfillmentStatus($orders['digital']));
        $this->assertSame([[0, 0], [0, 0], [0, 0]], $this->counters($orders['edited']), 'Removed (0) and superseded lines keep zero counters.');
        $this->assertSame([[0, 0], [2, 0]], $this->counters($orders['edited_shipped']), 'Superseded snapshots are not counted.');
        $this->assertSame([[1, 0]], $this->counters($orders['owned']), 'Orders with fulfillment rows are left to the new code.');
        $this->assertSame('partial', $this->fulfillmentStatus($orders['owned']));
        $this->assertNotNull(DB::table('store_orders')->where('id', $orders['archived'])->value('deleted_at'));

        $second = app(FulfillmentBackfill::class)->run(3);
        $this->assertSame(0, $second['fulfilled_lines']);
        $this->assertSame(0, $second['released_lines']);
        $this->assertSame([[2, 0], [2, 0]], $this->counters($orders['delivered']));
    }

    public function test_schema_migration_is_rerunnable_and_refuses_to_roll_back_over_partial_shipments(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_10_28_000001_create_store_order_fulfillments_table.php');
        $migration->up(); // everything exists already: a retried deploy is a no-op
        $this->assertTrue(Schema::hasColumn('store_order_items', 'fulfilled_quantity'));
        $this->assertTrue(Schema::hasIndex('store_orders', 'store_orders_fulfillment_status_index'));

        $this->legacyOrder('shipped', [['inventory_status' => 'reserved', 'fulfilled_quantity' => 1]]);
        try {
            $migration->down();
            $this->fail('Rolling back over a partly shipped line must be refused.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('partly shipped or partly released', $error->getMessage());
        }
        $this->assertTrue(Schema::hasTable('store_order_fulfillments'), 'Nothing was dropped.');
        $this->assertTrue(Schema::hasColumn('store_order_items', 'released_quantity'));
    }
}
