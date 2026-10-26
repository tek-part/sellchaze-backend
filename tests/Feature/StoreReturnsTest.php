<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreOrderReturn;
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

class StoreReturnsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private string $public = 'http://returns.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Returns fixture', 'slug' => 'returns', 'status' => 'active', 'currency' => 'EGP']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'returns.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
    }

    /** @return array{0: array<string, string>, 1: StoreCustomer} */
    private function customer(string $email): array
    {
        $token = $this->postJson($this->public.'/auth/register', ['name' => 'Synthetic customer', 'email' => $email, 'password' => 'password123'])->assertCreated()->json('token');

        return [['Authorization' => 'Bearer '.$token], StoreCustomer::forStore($this->store)->where('email', $email)->firstOrFail()];
    }

    private function merchant(): array
    {
        return ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($this->owner)];
    }

    /** A paid two-line order (2 × 50 shirts, 1 × 100 cap, shipping 50) moved to `$until` through the lifecycle. */
    private function order(StoreCustomer $customer, string $until = 'delivered', bool $digital = false): StoreOrder
    {
        app(CurrentStore::class)->set($this->store);
        $order = StoreOrder::create(['store_id' => $this->store->id, 'store_customer_id' => $customer->id, 'order_number' => 'RET-'.Str::upper(Str::random(8)),
            'status' => 'processing', 'currency' => 'EGP', 'customer_name' => 'Synthetic customer', 'customer_email' => $customer->email,
            'subtotal' => '200.00', 'shipping_total' => '50.00', 'grand_total' => '250.00', 'payment_method' => 'card', 'payment_status' => 'paid', 'placed_at' => now()]);
        foreach ([['Shirt', 50, 2], ['Cap', 100, 1]] as [$name, $price, $quantity]) {
            $product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6),
                'price' => $price, 'track_inventory' => true, 'stock_quantity' => 10, 'reserved_quantity' => 0]);
            $item = $order->items()->create(['store_id' => $this->store->id, 'store_product_id' => $product->id, 'name' => $name, 'unit_price' => $price, 'quantity' => $quantity, 'line_total' => $price * $quantity]);
            DB::transaction(fn () => app(StoreInventory::class)->reserve($product, $item));
        }
        if ($digital) {
            $order->items()->create(['store_id' => $this->store->id, 'name' => 'E-book', 'unit_price' => 0, 'quantity' => 1, 'line_total' => 0, 'digital_delivery' => ['type' => 'codes', 'values' => ['SYNTHETIC']]]);
        }
        if ($until !== 'processing') {
            app(StoreOrderService::class)->transition($order, 'shipped');
            if ($until === 'delivered') {
                app(StoreOrderService::class)->transition($order, 'delivered');
            }
        }
        app(CurrentStore::class)->forget();

        return $order->refresh();
    }

    /** @return list<StoreOrderItem> */
    private function lines(StoreOrder $order): array
    {
        return StoreOrderItem::query()->withoutGlobalScopes()->where('store_order_id', $order->id)->orderBy('id')->get()->all();
    }

    public function test_customer_requests_a_return_within_the_window_once_and_sees_its_status(): void
    {
        [$auth, $customer] = $this->customer('ada@example.test');
        $order = $this->order($customer, 'delivered', true);
        [$shirt, $cap, $ebook] = $this->lines($order);
        $url = $this->public.'/orders/'.$order->order_number;

        $this->getJson($url, $auth)->assertOk()->assertJsonPath('data.return_eligibility.eligible', true)
            ->assertJsonPath('data.return_eligibility.window_days', 14)->assertJsonPath('data.return_eligibility.items.0.returnable_quantity', 2)
            ->assertJsonPath('data.return_eligibility.items.2.returnable_quantity', 0)->assertJsonPath('data.returns', []);

        $this->postJson($url.'/returns', ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 3]], 'reason' => 'size_fit'], $auth)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson($url.'/returns', ['items' => [['store_order_item_id' => $ebook->id, 'quantity' => 1]], 'reason' => 'size_fit'], $auth)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson($url.'/returns', ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 1]], 'reason' => 'bored'], $auth)->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->postJson($url.'/returns', ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 1], ['store_order_item_id' => $cap->id, 'quantity' => 1]], 'reason' => 'size_fit', 'note' => 'Too small'], $auth)
            ->assertCreated()->assertJsonPath('data.returns.0.status', 'requested')->assertJsonPath('data.returns.0.items.0.name', 'Shirt')
            ->assertJsonPath('data.returns.0.customer_note', 'Too small')->assertJsonMissingPath('data.returns.0.merchant_note')
            ->assertJsonPath('data.return_eligibility.eligible', false)->assertJsonPath('data.return_eligibility.reason', 'open_return');
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $order->id, 'source' => 'return_requested']);

        // One open return at a time.
        $this->postJson($url.'/returns', ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 1]], 'reason' => 'damaged'], $auth)->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->assertSame(1, StoreOrderReturn::query()->withoutGlobalScopes()->count());
    }

    public function test_returns_require_delivery_ownership_an_open_window_and_an_enabled_setting(): void
    {
        [$auth, $customer] = $this->customer('ada@example.test');
        [$otherAuth] = $this->customer('eve@example.test');
        $shipped = $this->order($customer, 'shipped');
        $delivered = $this->order($customer);
        [$line] = $this->lines($delivered);
        [$shippedLine] = $this->lines($shipped);
        $payload = fn (StoreOrderItem $item) => ['items' => [['store_order_item_id' => $item->id, 'quantity' => 1]], 'reason' => 'damaged'];

        $this->postJson($this->public.'/orders/'.$shipped->order_number.'/returns', $payload($shippedLine), $auth)->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->getJson($this->public.'/orders/'.$shipped->order_number, $auth)->assertOk()->assertJsonPath('data.return_eligibility.reason', 'not_delivered');
        $this->postJson($this->public.'/orders/'.$delivered->order_number.'/returns', $payload($line), $otherAuth)->assertNotFound();
        $this->postJson($this->public.'/orders/'.$delivered->order_number.'/returns', $payload($line))->assertUnauthorized();

        // Store setting: 0 days turns online returns off; the settings API exposes and saves it.
        $settings = '/api/v1/stores/'.$this->store->id.'/order-limits';
        $this->getJson($settings, $this->merchant())->assertOk()->assertJsonPath('data.return_window_days', 14);
        $this->putJson($settings, ['max_product_quantity' => 0, 'max_orders_per_phone_24h' => 0, 'phone_country' => 'EG', 'return_window_days' => 0], $this->merchant())
            ->assertOk()->assertJsonPath('data.return_window_days', 0);
        $this->postJson($this->public.'/orders/'.$delivered->order_number.'/returns', $payload($line), $auth)->assertUnprocessable();
        $this->getJson($this->public.'/orders/'.$delivered->order_number, $auth)->assertJsonPath('data.return_eligibility.reason', 'disabled');
        $this->putJson($settings, ['max_product_quantity' => 0, 'max_orders_per_phone_24h' => 0, 'phone_country' => 'EG', 'return_window_days' => 3], $this->merchant())->assertOk();
        // Saving other limits without the field keeps the window.
        $this->putJson($settings, ['max_product_quantity' => 5, 'max_orders_per_phone_24h' => 0, 'phone_country' => 'EG'], $this->merchant())->assertOk()->assertJsonPath('data.return_window_days', 3);
        $this->putJson($settings, ['max_product_quantity' => 5, 'max_orders_per_phone_24h' => 0, 'phone_country' => 'EG', 'return_window_days' => 400], $this->merchant())->assertUnprocessable();

        $this->travel(4)->days();
        $this->postJson($this->public.'/orders/'.$delivered->order_number.'/returns', $payload($line), $auth)->assertUnprocessable();
        $this->getJson($this->public.'/orders/'.$delivered->order_number, $auth)->assertJsonPath('data.return_eligibility.reason', 'window_expired');
        $this->assertSame(0, StoreOrderReturn::query()->withoutGlobalScopes()->count());
    }

    public function test_merchant_workflow_moves_through_valid_transitions_and_refunds_received_items(): void
    {
        [$auth, $customer] = $this->customer('ada@example.test');
        $order = $this->order($customer);
        [$shirt] = $this->lines($order);
        $this->postJson($this->public.'/orders/'.$order->order_number.'/returns', ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 2]], 'reason' => 'damaged'], $auth)->assertCreated();
        $return = StoreOrderReturn::query()->withoutGlobalScopes()->firstOrFail();
        $base = '/api/v1/stores/'.$this->store->id;
        $merchant = $this->merchant();

        $this->getJson($base.'/orders/'.$order->id.'/returns', $merchant)->assertOk()->assertJsonPath('data.0.status', 'requested')->assertJsonPath('data.0.next_statuses', ['approved', 'rejected']);
        $this->getJson($base.'/returns?status=requested&search='.$order->order_number, $merchant)->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.order.order_number', $order->order_number)->assertJsonPath('counts.requested', 1);
        $this->getJson($base.'/returns?search=nobody', $merchant)->assertOk()->assertJsonPath('meta.total', 0);

        $this->patchJson($base.'/returns/'.$return->id, ['status' => 'received'], $merchant)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson($base.'/returns/'.$return->id, ['status' => 'requested', 'refund' => ['method' => 'cash']], $merchant)->assertUnprocessable()->assertJsonValidationErrors('refund');
        $this->patchJson($base.'/returns/'.$return->id, ['status' => 'approved', 'merchant_note' => 'Send it back with the label'], $merchant)
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.merchant_note', 'Send it back with the label');
        $this->patchJson($base.'/returns/'.$return->id, ['status' => 'received', 'refund' => ['method' => 'original_payment', 'restock' => true]], $merchant)
            ->assertOk()->assertJsonPath('data.status', 'received')->assertJsonPath('data.resolution', 'refund');

        $return->refresh();
        $this->assertNotNull($return->store_order_refund_id);
        $this->assertSame('100.00', $order->fresh()->refunded_total);
        $this->assertSame('partially_refunded', $order->fresh()->payment_status);
        $this->assertSame(10, Product::forStore($this->store)->findOrFail($shirt->store_product_id)->stock_quantity);
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $order->id, 'source' => 'return_received']);
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $order->id, 'source' => 'return_refund']);

        // A refund is recorded once per return; the customer can see the status but not internal notes.
        $this->patchJson($base.'/returns/'.$return->id, ['status' => 'closed', 'refund' => ['method' => 'cash']], $merchant)->assertUnprocessable()->assertJsonValidationErrors('refund');
        $this->patchJson($base.'/returns/'.$return->id, ['status' => 'closed'], $merchant)->assertOk()->assertJsonPath('data.next_statuses', []);
        $this->patchJson($base.'/returns/'.$return->id, ['status' => 'approved'], $merchant)->assertUnprocessable();
        $this->getJson($this->public.'/orders/'.$order->order_number, $auth)->assertOk()->assertJsonPath('data.returns.0.status', 'closed')
            ->assertJsonPath('data.returns.0.resolution', 'refund')->assertJsonMissingPath('data.returns.0.merchant_note')
            ->assertJsonPath('data.return_eligibility.items.0.returnable_quantity', 0)->assertJsonPath('data.refunded_total', '100.00');
        $this->assertDatabaseCount('store_order_refunds', 1);
    }

    public function test_rejected_returns_free_the_items_and_other_stores_cannot_touch_returns(): void
    {
        [$auth, $customer] = $this->customer('ada@example.test');
        $order = $this->order($customer);
        [$shirt] = $this->lines($order);
        $url = $this->public.'/orders/'.$order->order_number.'/returns';
        $this->postJson($url, ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 2]], 'reason' => 'changed_mind'], $auth)->assertCreated();
        $return = StoreOrderReturn::query()->withoutGlobalScopes()->firstOrFail();

        $intruder = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $intruder->assignRole('Merchant');
        Store::create(['owner_user_id' => $intruder->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-returns', 'status' => 'active', 'currency' => 'EGP']);
        $foreign = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($intruder)];
        $this->patchJson('/api/v1/my-store/returns/'.$return->id, ['status' => 'approved'], $foreign)->assertNotFound();
        $this->getJson('/api/v1/my-store/returns', $foreign)->assertOk()->assertJsonPath('meta.total', 0);
        $this->patchJson('/api/v1/stores/'.$this->store->id.'/returns/'.$return->id, ['status' => 'approved'], $foreign)->assertForbidden();
        $this->assertSame('requested', $return->fresh()->status);

        $this->patchJson('/api/v1/my-store/returns/'.$return->id, ['status' => 'rejected', 'merchant_note' => 'Worn item'], $this->merchant())->assertOk()->assertJsonPath('data.status', 'rejected');
        // Rejected requests close, so the customer can ask again for the same units.
        $this->postJson($url, ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 2]], 'reason' => 'damaged'], $auth)->assertCreated()
            ->assertJsonPath('data.returns.0.status', 'rejected')->assertJsonPath('data.returns.1.status', 'requested');
    }
}
