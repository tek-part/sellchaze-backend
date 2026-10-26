<?php

namespace Tests\Feature;

use App\Models\OutboxMessage;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreInventoryMovement;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use App\Services\Commerce\StoreInventory;
use App\Services\Commerce\StoreOrderService;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreRefundsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Refund fixture', 'slug' => 'refund-fixture', 'status' => 'active', 'currency' => 'EGP']);
        app(CurrentStore::class)->set($this->store);
    }

    private function url(StoreOrder $order, string $tail = '/refunds'): string
    {
        return '/api/v1/stores/'.$this->store->id.'/orders/'.$order->id.$tail;
    }

    /**
     * Two tracked lines (2 × 50 and 1 × 100), shipping 50, optional discount; shipped and delivered so stock is committed.
     *
     * @return array{0: StoreOrder, 1: StoreOrderItem, 2: StoreOrderItem, 3: Product, 4: Product}
     */
    private function delivered(array $attributes = [], string $until = 'delivered'): array
    {
        $order = StoreOrder::create(array_merge(['store_id' => $this->store->id, 'order_number' => 'REFUND-'.Str::upper(Str::random(8)),
            'status' => 'processing', 'currency' => 'EGP', 'customer_name' => 'Synthetic buyer', 'customer_email' => 'buyer@example.test',
            'subtotal' => '200.00', 'shipping_total' => '50.00', 'discount_total' => '0.00', 'grand_total' => '250.00',
            'payment_method' => 'card', 'payment_status' => 'paid', 'placed_at' => now()], $attributes));
        [$shirt, $first] = $this->line($order, 'Shirt', 50, 2);
        [$cap, $second] = $this->line($order, 'Cap', 100, 1);
        $service = app(StoreOrderService::class);
        $service->transition($order, 'shipped');
        if ($until === 'delivered') {
            $service->transition($order, 'delivered');
        } elseif ($until === 'returning_from_delivery') {
            $service->transition($order, 'returning_from_delivery');
        }

        return [$order->refresh(), $first, $second, $shirt, $cap];
    }

    /** @return array{0: Product, 1: StoreOrderItem} */
    private function line(StoreOrder $order, string $name, int $price, int $quantity): array
    {
        $product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6),
            'price' => $price, 'track_inventory' => true, 'stock_quantity' => 10, 'reserved_quantity' => 0]);
        $item = $order->items()->create(['store_id' => $this->store->id, 'store_product_id' => $product->id, 'name' => $name, 'unit_price' => $price,
            'quantity' => $quantity, 'line_total' => $price * $quantity]);
        DB::transaction(fn () => app(StoreInventory::class)->reserve($product, $item));

        return [$product, $item];
    }

    private function refundedEmails(StoreOrder $order): int
    {
        return OutboxMessage::query()->where('event_type', 'StoreNotificationEmail')->where('aggregate_id', (string) $order->id)
            ->where('payload->event', 'order_refunded')->count();
    }

    public function test_partial_refund_by_item_updates_totals_payment_status_and_timeline(): void
    {
        [$order, $shirt, , $shirtProduct] = $this->delivered();
        $this->getJson($this->url($order))->assertOk()
            ->assertJsonPath('data.refundable_total', '250.00')->assertJsonPath('data.shipping.refundable', '50.00')
            ->assertJsonPath('data.items.0.allocated_amount', '100.00')->assertJsonPath('data.items.0.refundable_quantity', 2)
            ->assertJsonPath('data.items.0.restockable', true)->assertJsonPath('data.items.1.refundable_amount', '100.00');

        $this->postJson($this->url($order), ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 1]], 'reason' => 'customer_request', 'method' => 'cash', 'note' => 'Wrong size'])
            ->assertCreated()->assertJsonPath('data.refunded_total', '50.00')->assertJsonPath('data.refundable_total', '200.00')
            ->assertJsonPath('data.payment_status', 'partially_refunded')->assertJsonPath('data.items.0.refundable_quantity', 1)
            ->assertJsonPath('data.refunds.0.amount', '50.00')->assertJsonPath('data.refunds.0.items.0.name', 'Shirt')
            ->assertJsonPath('data.refunds.0.restocked', false);

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame('partially_refunded', $order->payment_status);
        $this->assertSame('50.00', $order->refunded_total);
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $order->id, 'from_status' => 'delivered', 'to_status' => 'delivered', 'source' => 'refund']);
        $this->getJson('/api/v1/stores/'.$this->store->id.'/orders/'.$order->id.'/detail')->assertOk()->assertJsonPath('data.refunded_total', '50.00');
        $this->getJson('/api/v1/stores/'.$this->store->id.'/orders?payment_status=partially_refunded')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame(0, $this->refundedEmails($order));
        $this->assertSame(8, $shirtProduct->fresh()->stock_quantity, 'No restock without the flag.');
        $this->assertSame(0, StoreInventoryMovement::query()->where('reason', 'refund_restock')->count());
    }

    public function test_shipping_refund_follows_discounted_allocation_and_cannot_exceed_shipping_paid(): void
    {
        // Subtotal 200 - discount 20 + shipping 30 = 210: goods share 180 spread 90/90.
        [$order, $shirt] = $this->delivered(['discount_total' => '20.00', 'shipping_total' => '30.00', 'grand_total' => '210.00']);
        $this->getJson($this->url($order))->assertOk()->assertJsonPath('data.items.0.allocated_amount', '90.00')->assertJsonPath('data.items.1.allocated_amount', '90.00');

        $this->postJson($this->url($order), ['shipping_amount' => '30.00', 'reason' => 'not_delivered', 'method' => 'original_payment'])
            ->assertCreated()->assertJsonPath('data.shipping.refundable', '0.00')->assertJsonPath('data.refunded_total', '30.00');
        $this->postJson($this->url($order), ['shipping_amount' => '0.01', 'reason' => 'other', 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('shipping_amount');
        $this->postJson($this->url($order), ['reason' => 'other', 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('items');

        // One of two shirts: 45 of the 90 share.
        $this->postJson($this->url($order), ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 1]], 'reason' => 'damaged', 'method' => 'bank_transfer'])
            ->assertCreated()->assertJsonPath('data.refunds.1.amount', '45.00')->assertJsonPath('data.refunded_total', '75.00');
    }

    public function test_over_refunds_are_rejected_for_quantity_money_and_foreign_lines(): void
    {
        [$order, $shirt, $cap] = $this->delivered();
        [$other] = $this->delivered();
        $foreignLine = $other->items()->firstOrFail();
        $base = ['reason' => 'customer_request', 'method' => 'cash'];

        $this->postJson($this->url($order), $base + ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 3]]])->assertUnprocessable()->assertJsonValidationErrors('items');
        // Duplicate lines are aggregated before the quantity check.
        $this->postJson($this->url($order), $base + ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 2], ['store_order_item_id' => $shirt->id, 'quantity' => 1]]])->assertUnprocessable();
        $this->postJson($this->url($order), $base + ['items' => [['store_order_item_id' => $foreignLine->id, 'quantity' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson($this->url($order), $base + ['items' => [['store_order_item_id' => $cap->id, 'quantity' => 1]], 'amount' => '100.01'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson($this->url($order), array_merge($base, ['items' => [['store_order_item_id' => $cap->id, 'quantity' => 1]], 'method' => 'paypal']))->assertUnprocessable()->assertJsonValidationErrors('method');

        // A lower manual amount is allowed; the line's share is consumed, so the money left stays with the merchant's choice.
        $this->postJson($this->url($order), $base + ['items' => [['store_order_item_id' => $cap->id, 'quantity' => 1]], 'amount' => '60.00'])
            ->assertCreated()->assertJsonPath('data.refunded_total', '60.00')->assertJsonPath('data.items.1.refundable_quantity', 0);
        $this->postJson($this->url($order), $base + ['items' => [['store_order_item_id' => $cap->id, 'quantity' => 1]]])->assertUnprocessable();
        $this->assertDatabaseCount('store_order_refunds', 1);
        $this->assertSame('partially_refunded', $order->fresh()->payment_status);
    }

    public function test_restock_records_inventory_movement_and_returns_stock_only_for_shipped_lines(): void
    {
        [$order, $shirt, , $shirtProduct] = $this->delivered();
        $this->assertSame(8, $shirtProduct->fresh()->stock_quantity);
        $this->postJson($this->url($order), ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 2]], 'reason' => 'returned', 'method' => 'store_credit', 'restock' => true])
            ->assertCreated()->assertJsonPath('data.refunds.0.restocked', true)->assertJsonPath('data.refunds.0.items.0.restocked_quantity', 2);
        $this->assertSame(10, $shirtProduct->fresh()->stock_quantity);
        $this->assertSame(0, $shirtProduct->fresh()->reserved_quantity);
        $movement = StoreInventoryMovement::query()->where('reason', 'refund_restock')->firstOrFail();
        $this->assertSame(2, (int) $movement->stock_delta);
        $this->assertSame(10, (int) $movement->stock_after);
        $this->assertSame($shirt->id, (int) $movement->store_order_item_id);
        $this->assertSame($this->owner->id, (int) $movement->actor_id);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
        $this->getJson('/api/v1/my-store/catalog/inventory/'.$shirtProduct->id.'/history')->assertOk()->assertJsonPath('data.0.reason', 'refund_restock');

        // Unshipped orders still hold reservations: they are edited or cancelled, not refunded.
        $pending = StoreOrder::create(['store_id' => $this->store->id, 'order_number' => 'REFUND-PENDING', 'status' => 'processing', 'currency' => 'EGP',
            'customer_name' => 'Buyer', 'subtotal' => '50.00', 'grand_total' => '50.00', 'payment_method' => 'card', 'payment_status' => 'paid', 'placed_at' => now()]);
        [, $reserved] = $this->line($pending, 'Reserved', 50, 1);
        $this->postJson($this->url($pending), ['items' => [['store_order_item_id' => $reserved->id, 'quantity' => 1]], 'reason' => 'other', 'method' => 'cash', 'restock' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->assertSame(1, StoreInventoryMovement::query()->where('reason', 'refund_restock')->count());
    }

    public function test_full_refund_flips_payment_and_order_status_and_notifies_once(): void
    {
        [$order, $shirt, $cap] = $this->delivered();
        $this->postJson($this->url($order), ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 2]], 'reason' => 'returned', 'method' => 'original_payment'])->assertCreated();
        $this->assertSame(0, $this->refundedEmails($order));
        $this->postJson($this->url($order), ['items' => [['store_order_item_id' => $cap->id, 'quantity' => 1]], 'shipping_amount' => '50.00', 'reason' => 'returned', 'method' => 'original_payment', 'expected_refunded_total' => '100.00'])
            ->assertCreated()->assertJsonPath('data.refunded_total', '250.00')->assertJsonPath('data.refundable_total', '0.00')
            ->assertJsonPath('data.fully_refunded', true)->assertJsonPath('data.refundable', false)->assertJsonPath('data.order_status', 'refunded');

        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertSame('refunded', $order->payment_status);
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $order->id, 'from_status' => 'delivered', 'to_status' => 'refunded', 'source' => 'refund']);
        $this->assertSame(1, $this->refundedEmails($order));

        // Duplicate submit after the full refund: nothing left, nothing recorded, no second email.
        $this->postJson($this->url($order), ['shipping_amount' => '1.00', 'reason' => 'returned', 'method' => 'cash'])->assertUnprocessable();
        $this->postJson($this->url($order), ['items' => [['store_order_item_id' => $cap->id, 'quantity' => 1]], 'reason' => 'returned', 'method' => 'cash'])->assertUnprocessable();
        // A stale form (it saw 100.00 refunded) is told to reload instead of refunding again.
        $this->postJson($this->url($order), ['items' => [['store_order_item_id' => $cap->id, 'quantity' => 1]], 'reason' => 'returned', 'method' => 'cash', 'expected_refunded_total' => '100.00'])->assertConflict();
        $this->assertDatabaseCount('store_order_refunds', 2);
        $this->assertSame(1, $this->refundedEmails($order));
        $this->assertSame('250.00', $order->fresh()->refunded_total);
    }

    public function test_cancelled_paid_order_refund_keeps_status_and_notifies_once(): void
    {
        $order = StoreOrder::create(['store_id' => $this->store->id, 'order_number' => 'REFUND-CANCELLED', 'status' => 'confirmed', 'currency' => 'EGP',
            'customer_name' => 'Buyer', 'customer_email' => 'buyer@example.test', 'subtotal' => '100.00', 'grand_total' => '100.00', 'payment_method' => 'card', 'payment_status' => 'paid', 'placed_at' => now()]);
        [$product, $item] = $this->line($order, 'Lamp', 100, 1);
        app(StoreOrderService::class)->transition($order, 'cancelled');
        $this->postJson($this->url($order->refresh()), ['items' => [['store_order_item_id' => $item->id, 'quantity' => 1]], 'reason' => 'order_cancelled', 'method' => 'original_payment', 'restock' => true])
            ->assertCreated()->assertJsonPath('data.payment_status', 'refunded')->assertJsonPath('data.order_status', 'cancelled')
            ->assertJsonPath('data.refunds.0.restocked', false);
        $this->assertSame(10, $product->fresh()->stock_quantity, 'Released reservations are never restocked twice.');
        $this->assertSame(1, $this->refundedEmails($order));

        // Nothing was taken on an unpaid cancelled order, so there is nothing to refund.
        $unpaid = StoreOrder::create(['store_id' => $this->store->id, 'order_number' => 'REFUND-UNPAID', 'status' => 'cancelled', 'currency' => 'EGP',
            'customer_name' => 'Buyer', 'subtotal' => '100.00', 'grand_total' => '100.00', 'payment_method' => 'cod', 'payment_status' => 'pending', 'placed_at' => now()]);
        $line = $unpaid->items()->create(['store_id' => $this->store->id, 'name' => 'Lamp', 'unit_price' => 100, 'quantity' => 1, 'line_total' => 100, 'inventory_status' => 'released']);
        $this->getJson($this->url($unpaid))->assertOk()->assertJsonPath('data.refundable', false);
        $this->postJson($this->url($unpaid), ['items' => [['store_order_item_id' => $line->id, 'quantity' => 1]], 'reason' => 'order_cancelled', 'method' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors('order');
    }

    public function test_failed_cod_delivery_records_units_and_restock_without_money(): void
    {
        [$order, $shirt, $cap, $shirtProduct] = $this->delivered(['payment_method' => 'cod', 'payment_status' => 'pending'], 'returning_from_delivery');
        $this->getJson($this->url($order))->assertOk()->assertJsonPath('data.payment_collected', false)->assertJsonPath('data.refundable_total', '0.00');
        $lines = [['store_order_item_id' => $shirt->id, 'quantity' => 2], ['store_order_item_id' => $cap->id, 'quantity' => 1]];
        $this->postJson($this->url($order), ['items' => $lines, 'amount' => '1.00', 'reason' => 'not_delivered', 'method' => 'cash', 'restock' => true])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson($this->url($order), ['items' => $lines, 'reason' => 'not_delivered', 'method' => 'cash', 'restock' => true])
            ->assertCreated()->assertJsonPath('data.refunded_total', '0.00')->assertJsonPath('data.order_status', 'refunded')->assertJsonPath('data.payment_status', 'pending');
        $this->assertSame(10, $shirtProduct->fresh()->stock_quantity);
        $this->assertSame(0, $this->refundedEmails($order));
    }

    public function test_refunds_are_isolated_per_store_and_require_order_management(): void
    {
        [$order, $shirt] = $this->delivered();
        $intruder = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $intruder->assignRole('Merchant');
        $other = Store::create(['owner_user_id' => $intruder->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-refunds', 'status' => 'active', 'currency' => 'EGP']);
        $token = JwtTokenService::fromConfig()->issueAccessToken($intruder);
        $payload = ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 1]], 'reason' => 'other', 'method' => 'cash'];
        app(CurrentStore::class)->forget();

        $this->withToken($token)->postJson($this->url($order), $payload)->assertForbidden();
        $this->withToken($token)->getJson($this->url($order))->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/my-store/orders/'.$order->id.'/refunds', $payload)->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/stores/'.$other->id.'/orders/'.$order->id.'/refunds')->assertNotFound();
        $this->assertDatabaseCount('store_order_refunds', 0);
        $this->assertSame('paid', StoreOrder::forStore($this->store)->findOrFail($order->id)->payment_status);
    }
}
