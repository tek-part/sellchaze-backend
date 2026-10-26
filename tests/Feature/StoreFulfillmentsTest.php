<?php

namespace Tests\Feature;

use App\Mail\StoreNotificationMail;
use App\Models\OutboxMessage;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCarrierConnection;
use App\Models\StoreCustomer;
use App\Models\StoreDomain;
use App\Models\StoreInventoryMovement;
use App\Models\StoreOrder;
use App\Models\StoreOrderFulfillment;
use App\Models\StoreOrderItem;
use App\Models\StoreOrderWebhook;
use App\Models\StoreOrderWebhookDelivery;
use App\Models\StoreShipment;
use App\Models\User;
use App\Services\Commerce\StoreInventory;
use App\Services\Commerce\StoreOrderService;
use App\Services\JwtTokenService;
use App\Services\Notifications\StoreNotificationSettings;
use App\Services\Shipping\StoreShipmentEvents;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StoreFulfillmentsTest extends TestCase
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
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Fulfil fixture', 'slug' => 'fulfil', 'status' => 'active', 'currency' => 'EGP']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'fulfil.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
    }

    private function url(StoreOrder|int $order, string $tail = '/fulfillments'): string
    {
        return '/api/v1/stores/'.$this->store->id.'/orders/'.($order instanceof StoreOrder ? $order->id : $order).$tail;
    }

    private function fulfillmentUrl(int $id, string $tail = ''): string
    {
        return '/api/v1/stores/'.$this->store->id.'/fulfillments/'.$id.$tail;
    }

    /**
     * A paid processing order: 2 × Shirt (50) and 1 × Cap (100), both tracked with 10 on the shelf and reserved.
     *
     * @return array{0: StoreOrder, 1: StoreOrderItem, 2: StoreOrderItem, 3: Product, 4: Product}
     */
    private function order(array $attributes = []): array
    {
        $order = StoreOrder::create(array_merge(['store_id' => $this->store->id, 'order_number' => 'FUL-'.Str::upper(Str::random(8)),
            'status' => 'processing', 'currency' => 'EGP', 'customer_name' => 'Synthetic buyer', 'customer_email' => 'buyer@example.test',
            'subtotal' => '200.00', 'shipping_total' => '50.00', 'discount_total' => '0.00', 'grand_total' => '250.00',
            'payment_method' => 'card', 'payment_status' => 'paid', 'placed_at' => now()], $attributes));
        [$shirt, $first] = $this->line($order, 'Shirt', 50, 2);
        [$cap, $second] = $this->line($order, 'Cap', 100, 1);

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

        return [$product, $item->refresh()];
    }

    /** @param array<int, array{0: StoreOrderItem, 1: int}> $lines */
    private function fulfil(StoreOrder $order, array $lines, array $extra = []): TestResponse
    {
        return $this->postJson($this->url($order), array_merge([
            'items' => array_map(fn (array $line) => ['store_order_item_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
            'carrier' => 'aramex', 'notify_customer' => true,
        ], $extra));
    }

    private function stock(Product $product): array
    {
        $product->refresh();

        return [$product->stock_quantity, $product->reserved_quantity];
    }

    /** @return Collection<int, OutboxMessage> */
    private function shippingEmails(StoreOrder $order)
    {
        return OutboxMessage::query()->where('event_type', 'StoreNotificationEmail')->where('aggregate_id', (string) $order->id)
            ->where('payload->event', 'shipping_confirmation')->orderBy('id')->get();
    }

    public function test_partial_fulfilment_commits_only_shipped_units_with_movements(): void
    {
        [$order, $shirt, $cap, $shirtProduct, $capProduct] = $this->order();
        $this->getJson($this->url($order))->assertOk()->assertJsonPath('data.fulfillment_status', 'unfulfilled')
            ->assertJsonPath('data.can_fulfill', true)->assertJsonPath('data.items.0.unfulfilled_quantity', 2)->assertJsonPath('data.unfulfilled_quantity', 3);

        $response = $this->fulfil($order, [[$shirt, 1]], ['tracking_number' => 'AR 123/45'])->assertCreated()
            ->assertJsonPath('data.fulfillment_status', 'partial')->assertJsonPath('data.order_status', 'shipped')
            ->assertJsonPath('data.items.0.fulfilled_quantity', 1)->assertJsonPath('data.items.0.unfulfilled_quantity', 1)
            ->assertJsonPath('data.items.1.unfulfilled_quantity', 1)
            ->assertJsonPath('data.fulfillments.0.status', 'in_transit')->assertJsonPath('data.fulfillments.0.carrier_name', 'Aramex')
            ->assertJsonPath('data.fulfillments.0.items.0.name', 'Shirt')->assertJsonPath('data.fulfillments.0.items.0.quantity', 1);
        $this->assertSame('https://www.aramex.com/track/results?ShipmentNumber=AR%20123%2F45', $response->json('data.fulfillments.0.tracking_url'));

        $this->assertSame([9, 1], $this->stock($shirtProduct), 'One shirt left the shelf, the other is still held.');
        $this->assertSame([10, 1], $this->stock($capProduct));
        $movement = StoreInventoryMovement::query()->where('store_order_item_id', $shirt->id)->where('reason', 'shipped')->sole();
        $this->assertSame(-1, (int) $movement->stock_delta);
        $this->assertSame(-1, (int) $movement->reserved_delta);
        $this->assertSame($this->owner->id, (int) $movement->actor_id);
        $this->assertSame(0, StoreInventoryMovement::query()->where('store_order_item_id', $cap->id)->where('reason', 'shipped')->count());
        $this->assertSame('reserved', $shirt->fresh()->inventory_status);
        $this->assertSame(1, $shirt->fresh()->fulfilled_quantity);

        $order->refresh();
        $this->assertSame('shipped', $order->status);
        $this->assertSame('partial', $order->fulfillment_status);
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $order->id, 'from_status' => 'processing', 'to_status' => 'shipped', 'source' => 'fulfillment']);
        $this->getJson('/api/v1/stores/'.$this->store->id.'/orders?fulfillment_status=partial')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.fulfillment_status', 'partial')->assertJsonPath('fulfillment_counts.partial', 1);
        $this->getJson('/api/v1/stores/'.$this->store->id.'/orders?fulfillment_status=fulfilled')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/stores/'.$this->store->id.'/orders/'.$order->id)->assertOk()->assertJsonPath('data.fulfillment_status', 'partial')
            ->assertJsonPath('data.items.0.fulfilled_quantity', 1);
    }

    public function test_over_fulfilment_and_foreign_or_digital_lines_are_rejected(): void
    {
        [$order, $shirt, $cap, $shirtProduct] = $this->order();
        [$other] = $this->order();
        $foreign = $other->items()->firstOrFail();
        $ebook = $order->items()->create(['store_id' => $this->store->id, 'name' => 'E-book', 'unit_price' => 0, 'quantity' => 1, 'line_total' => 0, 'digital_delivery' => ['type' => 'codes', 'values' => ['SYNTHETIC']]]);

        $this->fulfil($order, [[$shirt, 3]])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->fulfil($order, [[$shirt, 2], [$shirt, 1]])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->fulfil($order, [[$foreign, 1]])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->fulfil($order, [[$ebook, 1]])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->fulfil($order, [[$shirt, 1]], ['carrier' => 'pigeon'])->assertUnprocessable()->assertJsonValidationErrors('carrier');
        $this->fulfil($order, [[$shirt, 1]], ['tracking_url' => 'javascript:alert(1)'])->assertUnprocessable()->assertJsonValidationErrors('tracking_url');
        $this->assertSame(0, StoreOrderFulfillment::query()->count());

        $this->fulfil($order, [[$shirt, 2]])->assertCreated();
        $this->fulfil($order, [[$shirt, 1]])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame([8, 0], $this->stock($shirtProduct));

        $pending = $this->order(['status' => 'pending'])[0];
        $this->fulfil($pending, [[$pending->items()->firstOrFail(), 1]])->assertUnprocessable()->assertJsonValidationErrors('order');
    }

    public function test_second_fulfilment_completes_the_order_and_delivery_syncs_when_every_parcel_arrives(): void
    {
        [$order, $shirt, $cap, $shirtProduct, $capProduct] = $this->order(['status' => 'confirmed']);
        $first = $this->fulfil($order, [[$shirt, 1]], ['tracking_number' => 'A1'])->assertCreated()->json('data.fulfillments.0.id');
        $this->assertSame('shipped', $order->fresh()->status, 'Confirmed orders step through processing to shipped.');
        $second = $this->fulfil($order, [[$shirt, 1], [$cap, 1]], ['carrier' => 'other', 'carrier_name' => 'Local courier', 'tracking_url' => 'https://courier.example.test/t/B2'])
            ->assertCreated()->assertJsonPath('data.fulfillment_status', 'fulfilled')->assertJsonPath('data.can_fulfill', false)
            ->assertJsonPath('data.fulfillments.1.carrier_name', 'Local courier')->assertJsonPath('data.fulfillments.1.tracking_url', 'https://courier.example.test/t/B2')
            ->json('data.fulfillments.1.id');
        $this->assertSame([8, 0], $this->stock($shirtProduct));
        $this->assertSame([9, 0], $this->stock($capProduct));
        $this->assertSame('committed', $shirt->fresh()->inventory_status);
        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertSame('fulfilled', $order->fresh()->fulfillment_status);

        $this->patchJson($this->fulfillmentUrl($first), ['status' => 'delivered'])->assertOk()->assertJsonPath('data.fulfillments.0.status', 'delivered')
            ->assertJsonPath('data.order_status', 'shipped');
        $this->patchJson($this->fulfillmentUrl($second), ['status' => 'failed'])->assertOk()->assertJsonPath('data.order_status', 'shipped');
        $this->patchJson($this->fulfillmentUrl($second), ['status' => 'delivered'])->assertOk()->assertJsonPath('data.order_status', 'delivered');
        $this->patchJson($this->fulfillmentUrl($second), ['status' => 'in_transit'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $order->id, 'from_status' => 'shipped', 'to_status' => 'delivered', 'source' => 'fulfillment']);
        $this->assertSame(1, OutboxMessage::query()->where('event_type', 'StoreNotificationEmail')->where('aggregate_id', (string) $order->id)->where('payload->event', 'order_delivered')->count());
        $this->assertNotNull(StoreOrderFulfillment::query()->findOrFail($second)->delivered_at);
    }

    public function test_cancelling_a_fulfilment_restores_units_and_inventory_and_unships_the_order(): void
    {
        [$order, $shirt, $cap, $shirtProduct, $capProduct] = $this->order();
        $first = $this->fulfil($order, [[$shirt, 1]])->assertCreated()->json('data.fulfillments.0.id');
        $second = $this->fulfil($order, [[$shirt, 1], [$cap, 1]])->assertCreated()->json('data.fulfillments.1.id');
        $this->assertSame([8, 0], $this->stock($shirtProduct));

        $this->postJson($this->fulfillmentUrl($second, '/cancel'))->assertOk()->assertJsonPath('data.fulfillment_status', 'partial')
            ->assertJsonPath('data.order_status', 'shipped')->assertJsonPath('data.fulfillments.1.status', 'cancelled')
            ->assertJsonPath('data.items.0.unfulfilled_quantity', 1)->assertJsonPath('data.items.1.unfulfilled_quantity', 1);
        $this->assertSame([9, 1], $this->stock($shirtProduct));
        $this->assertSame([10, 1], $this->stock($capProduct));
        $this->assertSame('reserved', $shirt->fresh()->inventory_status);
        $this->assertSame(1, StoreInventoryMovement::query()->where('store_order_item_id', $cap->id)->where('reason', 'fulfillment_cancelled')->count());
        $this->postJson($this->fulfillmentUrl($second, '/cancel'))->assertUnprocessable();
        $this->patchJson($this->fulfillmentUrl($second), ['tracking_number' => 'X1'])->assertUnprocessable();

        $this->postJson($this->fulfillmentUrl($first, '/cancel'))->assertOk()->assertJsonPath('data.fulfillment_status', 'unfulfilled')
            ->assertJsonPath('data.order_status', 'processing');
        $this->assertSame([10, 2], $this->stock($shirtProduct));
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $order->id, 'from_status' => 'shipped', 'to_status' => 'processing', 'source' => 'fulfillment']);

        // Back in processing, the order can still be cancelled and every hold released exactly once.
        app(StoreOrderService::class)->transition($order->refresh(), 'cancelled');
        $this->assertSame([10, 0], $this->stock($shirtProduct));
        $this->assertSame([10, 0], $this->stock($capProduct));
    }

    public function test_legacy_mark_shipped_fulfils_everything_without_tracking_and_bulk_status_still_works(): void
    {
        [$order, $shirt, $cap, $shirtProduct] = $this->order();
        $this->patchJson('/api/v1/stores/'.$this->store->id.'/orders/'.$order->id.'/status', ['status' => 'shipped'])->assertOk()
            ->assertJsonPath('data.status', 'shipped')->assertJsonPath('data.fulfillment_status', 'fulfilled');
        $fulfillment = StoreOrderFulfillment::query()->where('store_order_id', $order->id)->sole();
        $this->assertSame('other', $fulfillment->carrier);
        $this->assertNull($fulfillment->tracking_number);
        $this->assertSame('status', $fulfillment->source);
        $this->assertEqualsCanonicalizing([['store_order_item_id' => $shirt->id, 'quantity' => 2], ['store_order_item_id' => $cap->id, 'quantity' => 1]], $fulfillment->items);
        $this->assertSame([8, 0], $this->stock($shirtProduct));
        $this->assertSame(1, $this->shippingEmails($order)->count(), 'One shipping confirmation, as before.');

        // Bulk "shipped" on two processing orders, one of them already partially fulfilled.
        [$a, $aShirt, , $aProduct] = $this->order();
        [$b] = $this->order();
        $this->fulfil($a, [[$aShirt, 1]])->assertCreated();
        $this->postJson('/api/v1/stores/'.$this->store->id.'/orders/bulk-status', ['status' => 'shipped', 'eligible_only' => true,
            'orders' => [['id' => $a->id, 'status' => 'shipped'], ['id' => $b->id, 'status' => 'processing']]])->assertOk()
            ->assertJsonPath('updated', 1)->assertJsonPath('unchanged', 1);
        $this->assertSame('fulfilled', $b->fresh()->fulfillment_status);
        $this->assertSame('partial', $a->fresh()->fulfillment_status, 'An order that already shipped keeps its own fulfillments.');

        // Marking a partially shipped order delivered ships the rest only with an explicit confirmation, and never in bulk.
        $this->postJson('/api/v1/stores/'.$this->store->id.'/orders/bulk-status', ['status' => 'delivered', 'eligible_only' => true,
            'orders' => [['id' => $a->id, 'status' => 'shipped']]])->assertOk()->assertJsonPath('skipped', 1)->assertJsonPath('updated', 0);
        $this->postJson('/api/v1/stores/'.$this->store->id.'/orders/bulk-status', ['status' => 'delivered',
            'orders' => [['id' => $a->id, 'status' => 'shipped']]])->assertUnprocessable();
        $this->patchJson('/api/v1/stores/'.$this->store->id.'/orders/'.$a->id.'/status', ['status' => 'delivered'])->assertUnprocessable()
            ->assertJsonValidationErrors('fulfil_remaining');
        $this->assertSame('shipped', $a->fresh()->status);
        $emails = $this->shippingEmails($a)->count();
        $this->patchJson('/api/v1/stores/'.$this->store->id.'/orders/'.$a->id.'/status', ['status' => 'delivered', 'fulfil_remaining' => true])->assertOk()
            ->assertJsonPath('data.fulfillment_status', 'fulfilled');
        $this->assertSame($emails, $this->shippingEmails($a)->count(), 'No shipping confirmation at delivery time.');
        $this->assertFalse(StoreOrderFulfillment::query()->where('store_order_id', $a->id)->orderByDesc('id')->firstOrFail()->notify_customer);
        $this->assertSame([8, 0], $this->stock($aProduct));
        $this->assertSame(['delivered', 'delivered'], StoreOrderFulfillment::query()->where('store_order_id', $a->id)->orderBy('id')->pluck('status')->all());
    }

    public function test_customer_is_notified_once_per_fulfilment_with_that_parcels_tracking(): void
    {
        [$order, $shirt, $cap] = $this->order();
        $first = $this->fulfil($order, [[$shirt, 1]], ['tracking_number' => 'AR1'])->assertCreated()->json('data.fulfillments.0.id');
        $emails = $this->shippingEmails($order);
        $this->assertCount(1, $emails);
        $this->assertSame($first, $emails[0]->payload['fulfillment_id']);
        $this->assertSame('AR1', $emails[0]->payload['tracking_number']);

        // Saving the same tracking number again does not resend; a corrected number does.
        $this->patchJson($this->fulfillmentUrl($first), ['tracking_number' => 'AR1', 'note' => 'Packed by Sam'])->assertOk();
        $this->assertCount(1, $this->shippingEmails($order));
        $this->patchJson($this->fulfillmentUrl($first), ['tracking_number' => 'AR2'])->assertOk();
        $this->assertCount(2, $this->shippingEmails($order));

        // A silent fulfillment sends nothing.
        $silent = $this->fulfil($order, [[$shirt, 1]], ['carrier' => 'dhl', 'tracking_number' => 'DH9', 'notify_customer' => false])->assertCreated()->json('data.fulfillments.1.id');
        $this->assertCount(2, $this->shippingEmails($order));
        $third = $this->fulfil($order, [[$cap, 1]], ['carrier' => 'smsa', 'tracking_number' => 'SM7'])->assertCreated()->json('data.fulfillments.2.id');
        $this->assertCount(3, $this->shippingEmails($order));
        $this->assertNotSame($silent, $third);

        // The email for a parcel carries that parcel's tracking and items.
        $fulfillment = StoreOrderFulfillment::query()->findOrFail($third);
        $settings = app(StoreNotificationSettings::class)->configured($this->store)['events']['shipping_confirmation'];
        $html = (new StoreNotificationMail($this->store, $order->fresh(), 'shipping_confirmation', ['subject' => $settings['email_subject'], 'body' => $settings['email_body']], null, false, $fulfillment))->render();
        $this->assertStringContainsString('SM7', $html);
        $this->assertStringContainsString('SMSA Express', $html);
        $this->assertStringContainsString('https://www.smsaexpress.com/trackingdetails?tracknumbers=SM7', $html);
        $variables = app(StoreNotificationSettings::class)->variables($this->store, $order->fresh(), false, $fulfillment);
        $this->assertSame('• Cap × 1', $variables['items']);
    }

    public function test_refunds_after_partial_fulfilment_release_unshipped_units_and_restock_only_shipped_ones(): void
    {
        [$order, $shirt, $cap, $shirtProduct, $capProduct] = $this->order();
        $this->fulfil($order, [[$shirt, 1]])->assertCreated();
        $this->assertSame([9, 1], $this->stock($shirtProduct));
        $refunds = '/api/v1/stores/'.$this->store->id.'/orders/'.$order->id.'/refunds';
        $this->getJson($refunds)->assertOk()->assertJsonPath('data.items.0.restockable', true)->assertJsonPath('data.items.0.fulfilled_quantity', 1)
            ->assertJsonPath('data.items.1.restockable', false);

        // Both shirts refunded with restock: the unshipped one is released, the shipped one goes back on the shelf.
        $this->postJson($refunds, ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 2]], 'reason' => 'customer_request', 'method' => 'cash', 'restock' => true])
            ->assertCreated()->assertJsonPath('data.refunds.0.items.0.restocked_quantity', 1);
        $this->assertSame([10, 0], $this->stock($shirtProduct));
        $this->assertSame(1, StoreInventoryMovement::query()->where('store_order_item_id', $shirt->id)->where('reason', 'refund_released')->count());
        $this->assertSame(1, StoreInventoryMovement::query()->where('store_order_item_id', $shirt->id)->where('reason', 'refund_restock')->count());
        $this->getJson($this->url($order))->assertOk()->assertJsonPath('data.items.0.unfulfilled_quantity', 0)->assertJsonPath('data.fulfillment_status', 'partial');
        $this->fulfil($order, [[$shirt, 1]])->assertUnprocessable()->assertJsonValidationErrors('items');

        // A refunded line blocks cancelling its shipment (record a return instead).
        $fulfillment = StoreOrderFulfillment::query()->where('store_order_id', $order->id)->firstOrFail();
        $this->postJson($this->fulfillmentUrl($fulfillment->id, '/cancel'))->assertUnprocessable()->assertJsonValidationErrors('fulfillment');

        // A refund without restock on the unshipped cap only releases its hold.
        $this->postJson($refunds, ['items' => [['store_order_item_id' => $cap->id, 'quantity' => 1]], 'reason' => 'other', 'method' => 'cash'])->assertCreated();
        $this->assertSame([10, 0], $this->stock($capProduct));
        $this->assertSame('released', $cap->fresh()->inventory_status);
        $this->assertSame('fulfilled', $order->fresh()->fulfillment_status);
        $this->assertSame('shipped', $order->fresh()->status, 'Shipping was not refunded, so the order stays open.');
    }

    public function test_fully_shipped_refund_restock_never_exceeds_units_that_left_the_shelf(): void
    {
        [$order, $shirt, , $shirtProduct] = $this->order();
        $this->fulfil($order, [[$shirt, 1]])->assertCreated();
        app(StoreOrderService::class)->transition($order->refresh(), 'returning_from_delivery');
        $this->assertSame([9, 0], $this->stock($shirtProduct), 'Ending the order releases the unshipped shirt.');
        $this->assertSame('fulfilled', $order->fresh()->fulfillment_status);
        $refunds = '/api/v1/stores/'.$this->store->id.'/orders/'.$order->id.'/refunds';
        $this->postJson($refunds, ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 1]], 'reason' => 'returned', 'method' => 'cash', 'restock' => true])->assertCreated();
        $this->postJson($refunds, ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 1]], 'reason' => 'returned', 'method' => 'cash', 'restock' => true])->assertCreated()
            ->assertJsonPath('data.refunds.1.items.0.restocked_quantity', 0);
        $this->assertSame([10, 0], $this->stock($shirtProduct));
    }

    public function test_storefront_order_page_lists_active_shipments(): void
    {
        $public = 'http://fulfil.sellchase.com/api/v1/storefront';
        $this->flushHeaders();
        $token = $this->postJson($public.'/auth/register', ['name' => 'Synthetic customer', 'email' => 'ada@example.test', 'password' => 'password123'])->assertCreated()->json('token');
        app(CurrentStore::class)->set($this->store);
        $customer = StoreCustomer::forStore($this->store)->where('email', 'ada@example.test')->firstOrFail();
        [$order, $shirt, $cap] = $this->order(['store_customer_id' => $customer->id]);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
        $this->fulfil($order, [[$shirt, 2]], ['carrier' => 'bosta', 'tracking_number' => '123456', 'note' => 'Fragile, internal'])->assertCreated();
        $cancelled = $this->fulfil($order, [[$cap, 1]], ['carrier' => 'dhl', 'tracking_number' => 'DHL-1'])->json('data.fulfillments.1.id');
        $this->postJson($this->fulfillmentUrl($cancelled, '/cancel'))->assertOk();
        app(CurrentStore::class)->forget();

        $this->withToken($token)->getJson($public.'/orders/'.$order->order_number)->assertOk()
            ->assertJsonPath('data.fulfillment_status', 'partial')->assertJsonCount(1, 'data.fulfillments')
            ->assertJsonPath('data.fulfillments.0.carrier_name', 'Bosta')->assertJsonPath('data.fulfillments.0.tracking_number', '123456')
            ->assertJsonPath('data.fulfillments.0.tracking_url', 'https://bosta.co/tracking-shipments?shipment-number=123456')
            ->assertJsonPath('data.fulfillments.0.items.0.name', 'Shirt')->assertJsonPath('data.fulfillments.0.items.0.quantity', 2)
            ->assertJsonMissingPath('data.fulfillments.0.note')->assertDontSee('Fragile, internal');
    }

    public function test_fulfilment_events_reach_order_fulfillment_webhooks_once_per_version(): void
    {
        StoreOrderWebhook::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'url' => 'https://hooks.example.test/fulfil',
            'event_type' => 'order-fulfillment', 'secret' => 'fixture-secret', 'enabled' => true, 'version' => 1, 'request_key' => (string) Str::uuid()]);
        [$order, $shirt] = $this->order();
        $id = $this->fulfil($order, [[$shirt, 1]], ['tracking_number' => 'W1'])->json('data.fulfillments.0.id');
        $this->patchJson($this->fulfillmentUrl($id), ['tracking_number' => 'W2'])->assertOk();
        $this->patchJson($this->fulfillmentUrl($id), ['tracking_number' => 'W2'])->assertOk();
        $this->postJson($this->fulfillmentUrl($id, '/cancel'))->assertOk();
        $keys = StoreOrderWebhookDelivery::query()->orderBy('id')->pluck('event_key')->all();
        $this->assertSame(["fulfillment:{$id}:v1", "fulfillment:{$id}:v2", "fulfillment:{$id}:v3"], $keys);
        $payload = StoreOrderWebhookDelivery::query()->where('event_key', "fulfillment:{$id}:v3")->sole()->payload;
        $this->assertSame('cancelled', $payload['action']);
        $this->assertSame('W2', $payload['fulfillment']['tracking_number']);
    }

    public function test_fulfillments_are_isolated_per_store_and_require_order_management(): void
    {
        [$order, $shirt] = $this->order();
        $id = $this->fulfil($order, [[$shirt, 1]])->assertCreated()->json('data.fulfillments.0.id');
        $intruder = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $intruder->assignRole('Merchant');
        $other = Store::create(['owner_user_id' => $intruder->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-fulfil', 'status' => 'active', 'currency' => 'EGP']);
        $token = JwtTokenService::fromConfig()->issueAccessToken($intruder);
        $payload = ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 1]], 'carrier' => 'other'];
        app(CurrentStore::class)->forget();

        $this->withToken($token)->getJson($this->url($order))->assertForbidden();
        $this->withToken($token)->postJson($this->url($order), $payload)->assertForbidden();
        $this->withToken($token)->patchJson($this->fulfillmentUrl($id), ['status' => 'delivered'])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/my-store/orders/'.$order->id.'/fulfillments', $payload)->assertNotFound();
        $this->withToken($token)->patchJson('/api/v1/my-store/fulfillments/'.$id, ['status' => 'delivered'])->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/stores/'.$other->id.'/fulfillments/'.$id.'/cancel')->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/stores/'.$other->id.'/orders/'.$order->id.'/fulfillments')->assertNotFound();
        $this->assertSame(1, StoreOrderFulfillment::query()->withoutGlobalScopes()->count());
        $this->assertSame('in_transit', StoreOrderFulfillment::query()->withoutGlobalScopes()->findOrFail($id)->status);
        $this->assertSame(1, StoreOrderItem::query()->withoutGlobalScopes()->findOrFail($shirt->id)->fulfilled_quantity);
    }

    public function test_carrier_delivery_of_a_partial_order_ships_the_rest_silently(): void
    {
        [$order, $shirt] = $this->order();
        $this->fulfil($order, [[$shirt, 1]], ['tracking_number' => 'AR1'])->assertCreated();
        $this->assertCount(1, $this->shippingEmails($order));
        app(StoreOrderService::class)->transition($order->refresh(), 'delivered', null, 'Bosta: Delivered', 'carrier');
        $this->assertSame('fulfilled', $order->fresh()->fulfillment_status);
        $this->assertCount(1, $this->shippingEmails($order), 'The leftover parcel is not announced at delivery time.');
        $this->assertSame(['delivered', 'delivered'], StoreOrderFulfillment::query()->where('store_order_id', $order->id)->orderBy('id')->pluck('status')->all());
    }

    public function test_refund_can_take_shipped_units_and_the_summary_shows_each_bucket(): void
    {
        [$order, $shirt, , $shirtProduct] = $this->order();
        $this->fulfil($order, [[$shirt, 1]])->assertCreated();
        $refunds = '/api/v1/stores/'.$this->store->id.'/orders/'.$order->id.'/refunds';
        $this->getJson($refunds)->assertOk()->assertJsonPath('data.items.0.shipped_refundable_quantity', 1)
            ->assertJsonPath('data.items.0.unshipped_refundable_quantity', 1)->assertJsonPath('data.items.0.restockable', true)
            ->assertJsonPath('data.items.1.shipped_refundable_quantity', 0)->assertJsonPath('data.items.1.restockable', false);

        // The customer returned the shipped shirt: it goes back on the shelf, the other one still ships.
        $this->postJson($refunds, ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 1, 'units' => 'shipped']], 'reason' => 'returned', 'method' => 'cash', 'restock' => true])
            ->assertCreated()->assertJsonPath('data.refunds.0.items.0.returned_quantity', 1)->assertJsonPath('data.refunds.0.items.0.released_quantity', 0)
            ->assertJsonPath('data.refunds.0.items.0.restocked_quantity', 1)->assertJsonPath('data.refunds.0.items.0.units', 'shipped')
            ->assertJsonPath('data.items.0.shipped_refundable_quantity', 0)->assertJsonPath('data.items.0.unshipped_refundable_quantity', 1);
        $this->assertSame([10, 1], $this->stock($shirtProduct));
        $this->getJson($this->url($order))->assertOk()->assertJsonPath('data.items.0.unfulfilled_quantity', 1);
        $this->postJson($refunds, ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 1, 'units' => 'sideways']], 'reason' => 'other', 'method' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.units');

        // Without a choice the remaining unit is the unshipped one: its hold is released.
        $this->postJson($refunds, ['items' => [['store_order_item_id' => $shirt->id, 'quantity' => 1]], 'reason' => 'other', 'method' => 'cash', 'restock' => true])
            ->assertCreated()->assertJsonPath('data.refunds.1.items.0.released_quantity', 1)->assertJsonPath('data.refunds.1.items.0.restocked_quantity', 0);
        $this->assertSame([10, 0], $this->stock($shirtProduct));
    }

    public function test_refunding_the_unshipped_rest_delivers_an_order_whose_parcels_arrived(): void
    {
        [$order, $shirt, $cap] = $this->order();
        $id = $this->fulfil($order, [[$shirt, 2]])->assertCreated()->json('data.fulfillments.0.id');
        $this->patchJson($this->fulfillmentUrl($id), ['status' => 'delivered'])->assertOk()->assertJsonPath('data.order_status', 'shipped');
        $this->postJson('/api/v1/stores/'.$this->store->id.'/orders/'.$order->id.'/refunds', ['items' => [['store_order_item_id' => $cap->id, 'quantity' => 1]], 'reason' => 'other', 'method' => 'cash'])
            ->assertCreated()->assertJsonPath('data.order_status', 'delivered');
        $this->assertSame('fulfilled', $order->fresh()->fulfillment_status);
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $order->id, 'from_status' => 'shipped', 'to_status' => 'delivered', 'source' => 'fulfillment']);
        $this->assertSame(1, OutboxMessage::query()->where('aggregate_id', (string) $order->id)->where('payload->event', 'order_delivered')->where('event_type', 'StoreNotificationEmail')->count());
    }

    public function test_cancelling_a_parcel_puts_stock_back_even_when_tracking_was_turned_off(): void
    {
        [$order, $shirt, , $shirtProduct] = $this->order();
        $id = $this->fulfil($order, [[$shirt, 2]])->assertCreated()->json('data.fulfillments.0.id');
        $this->assertSame([8, 0], $this->stock($shirtProduct));
        $shirtProduct->forceFill(['track_inventory' => false])->save();
        $this->postJson($this->fulfillmentUrl($id, '/cancel'))->assertOk()->assertJsonPath('data.items.0.unfulfilled_quantity', 2);
        $this->assertSame([10, 0], $this->stock($shirtProduct), 'Units return to the shelf without a new hold on an untracked row.');
        $this->assertNull($shirt->fresh()->inventory_status);
        $movement = StoreInventoryMovement::query()->where('store_order_item_id', $shirt->id)->where('reason', 'fulfillment_cancelled')->sole();
        $this->assertSame([2, 0], [(int) $movement->stock_delta, (int) $movement->reserved_delta]);
        // Untracked now: fulfilling again only moves the counters.
        $this->fulfil($order, [[$shirt, 2]])->assertCreated();
        $this->assertSame([10, 0], $this->stock($shirtProduct));
    }

    public function test_late_bosta_tracking_updates_the_linked_parcel_like_an_edit(): void
    {
        StoreOrderWebhook::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'url' => 'https://hooks.example.test/fulfil',
            'event_type' => 'order-fulfillment', 'secret' => 'fixture-secret', 'enabled' => true, 'version' => 1, 'request_key' => (string) Str::uuid()]);
        [$order] = $this->order();
        $connection = StoreCarrierConnection::create(['store_id' => $this->store->id, 'carrier' => 'bosta', 'api_key' => 'synthetic-secret', 'enabled' => true, 'pickup_location_id' => 'pickup-1', 'verified_at' => now()]);
        $shipment = StoreShipment::create(['store_id' => $this->store->id, 'store_order_id' => $order->id, 'store_carrier_connection_id' => $connection->id,
            'carrier' => 'bosta', 'status' => 'submitting', 'business_reference' => 'sc-'.Str::uuid(), 'request_snapshot' => ['synthetic' => true], 'carrier_revision' => 0]);
        $this->patchJson('/api/v1/stores/'.$this->store->id.'/orders/'.$order->id.'/status', ['status' => 'shipped'])->assertOk();
        $fulfillment = StoreOrderFulfillment::query()->where('store_order_id', $order->id)->sole();
        $this->assertSame([$shipment->id, 'bosta', null], [$fulfillment->store_shipment_id, $fulfillment->carrier, $fulfillment->tracking_number]);
        $payload = StoreOrderWebhookDelivery::query()->where('event_key', "fulfillment:{$fulfillment->id}:v1")->sole()->payload;
        $this->assertSame('shipped', $payload['order_status'], 'The leftover parcel is announced with the new order status.');
        $this->assertCount(1, $this->shippingEmails($order));

        app(StoreShipmentEvents::class)->apply($shipment, 'bosta-external-1', '445566', 30, now()->getTimestampMs(), 'webhook');
        $fulfillment->refresh();
        $this->assertSame(['445566', 2], [$fulfillment->tracking_number, $fulfillment->version]);
        $this->assertSame('updated', StoreOrderWebhookDelivery::query()->where('event_key', "fulfillment:{$fulfillment->id}:v2")->sole()->payload['action']);
        $emails = $this->shippingEmails($order);
        $this->assertCount(2, $emails);
        $this->assertSame('445566', $emails[1]->payload['tracking_number']);
    }
}
