<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreDraftOrder;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StoreDraftOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private User $otherOwner;

    private Store $other;

    private Product $shirt;  // 100.00, tracked stock 5

    private Product $mug;    // 20.00, untracked

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        [$this->owner, $this->store] = $this->makeStore('alpha');
        [$this->otherOwner, $this->other] = $this->makeStore('beta');
        $this->store->update(['shipping_enabled' => true, 'shipping_flat_rate' => 15, 'tax_enabled' => true, 'tax_rate' => 10, 'tax_prices_include' => false]);
        $this->shirt = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Shirt', 'slug' => 'shirt', 'sku' => 'SH-1', 'price' => '100.00',
            'is_active' => true, 'digital_type' => 'physical', 'track_inventory' => true, 'stock_quantity' => 5, 'reserved_quantity' => 0]);
        $this->mug = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Mug', 'slug' => 'mug', 'price' => '20.00', 'is_active' => true, 'digital_type' => 'physical']);
    }

    /** @return array{0: User, 1: Store} */
    private function makeStore(string $slug): array
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $store = Store::create(['owner_user_id' => $user->id, 'owner_type' => 'merchant', 'name' => ucfirst($slug).' Shop', 'slug' => $slug, 'currency' => 'USD', 'status' => 'active']);
        StoreDomain::create(['store_id' => $store->id, 'host' => "{$slug}.sellchase.com", 'type' => 'subdomain', 'status' => StoreDomain::STATUS_VERIFIED, 'is_primary' => true]);

        return [$user, $store];
    }

    private function api(string $path = '', ?Store $store = null): string
    {
        return '/api/v1/stores/'.($store ?? $this->store)->id.'/draft-orders'.$path;
    }

    private function auth(?User $user = null): array
    {
        return ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($user ?? $this->owner)];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'customer' => ['name' => 'Laila Hassan', 'email' => 'laila@example.test', 'phone' => '+201001112233'],
            'shipping_address' => ['line1' => '12 Nile St', 'city' => 'Cairo', 'country' => 'EG'],
            'line_items' => [
                ['type' => 'product', 'product_id' => $this->shirt->id, 'quantity' => 2],
                ['type' => 'custom', 'title' => 'Gift wrapping', 'unit_price' => '5.00', 'quantity' => 1, 'requires_shipping' => false],
            ],
            'shipping_line' => ['type' => 'rate'],
            'note' => 'Call before delivery',
            'tags' => ['vip', 'whatsapp'],
        ], $overrides);
    }

    private function create(array $overrides = []): TestResponse
    {
        return $this->postJson($this->api(), $this->payload($overrides), $this->auth());
    }

    /** @return array{id:int, url:string, token:string} */
    private function invoice(int $id): array
    {
        $url = $this->postJson($this->api("/{$id}/invoice"), [], $this->auth())->assertOk()->json('invoice.url');

        return ['id' => $id, 'url' => $url, 'token' => basename(parse_url($url, PHP_URL_PATH))];
    }

    private function storefront(string $path, string $host = 'alpha.sellchase.com'): string
    {
        return "http://{$host}/api/v1/storefront/invoices/{$path}";
    }

    public function test_crud_numbering_and_totals_snapshot(): void
    {
        $first = $this->create()->assertCreated()
            ->assertJsonPath('data.number', 'D-1001')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.line_items.0.title', 'Shirt')
            ->assertJsonPath('data.line_items.0.unit_price', '100.00')
            ->assertJsonPath('data.line_items.1.type', 'custom')
            // 200 + 5 = 205; tax 10% = 20.50; flat shipping 15 => 240.50
            ->assertJsonPath('data.totals.subtotal', '205.00')
            ->assertJsonPath('data.totals.tax_total', '20.50')
            ->assertJsonPath('data.totals.shipping_total', '15.00')
            ->assertJsonPath('data.grand_total', '240.50')
            ->assertJsonPath('data.tags', ['vip', 'whatsapp']);
        $id = $first->json('data.id');
        $this->create()->assertCreated()->assertJsonPath('data.number', 'D-1002');
        // Numbering is per store.
        $this->postJson($this->api('', $this->other), ['line_items' => [['type' => 'custom', 'title' => 'Consulting', 'unit_price' => '50.00', 'quantity' => 1]]], $this->auth($this->otherOwner))
            ->assertCreated()->assertJsonPath('data.number', 'D-1001');

        $this->getJson($this->api(), $this->auth())->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.counts.open', 2);
        $this->getJson($this->api('?search=Laila'), $this->auth())->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($this->api('?search=D-1002'), $this->auth())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->api("/{$id}"), $this->auth())->assertOk()->assertJsonPath('data.note', 'Call before delivery');

        $this->putJson($this->api("/{$id}"), $this->payload(['line_items' => [['type' => 'product', 'product_id' => $this->mug->id, 'quantity' => 3]], 'shipping_line' => null]), $this->auth())
            ->assertOk()->assertJsonPath('data.totals.subtotal', '60.00')->assertJsonPath('data.totals.shipping_total', '0.00')->assertJsonPath('data.grand_total', '66.00');

        $this->deleteJson($this->api("/{$id}"), [], $this->auth())->assertOk();
        $this->assertDatabaseMissing('store_draft_orders', ['id' => $id]);
        // Calculation never reserves stock.
        $this->assertSame(0, $this->shirt->fresh()->reserved_quantity);
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_calculate_applies_code_and_manual_discounts_shipping_rates_and_tax(): void
    {
        Coupon::create(['store_id' => $this->store->id, 'method' => 'code', 'code' => 'SAVE10', 'type' => 'percentage', 'value' => '10.00', 'is_active' => true]);
        $this->store->update(['shipping_configuration' => ['regions_enabled' => false, 'auto_select_region' => true, 'regions' => [],
            'options' => [['id' => '7d3c0d38-7a3b-4c0e-9a4f-0c2a3c8b2a11', 'enabled' => true, 'priority' => 1, 'rate' => '25.00', 'name' => ['en' => 'Express', 'ar' => 'سريع'], 'description' => ['en' => '', 'ar' => '']]]]]);
        $base = $this->payload(['shipping_line' => ['type' => 'rate', 'shipping_option_id' => '7d3c0d38-7a3b-4c0e-9a4f-0c2a3c8b2a11']]);

        // Unsaved editor state: code discount, store shipping option and tax (exclusive).
        $this->postJson($this->api('/calculate'), $base + ['applied_discount' => ['type' => 'code', 'code' => 'save10']], $this->auth())->assertOk()
            ->assertJsonPath('data.discount_total', '20.50')
            ->assertJsonPath('data.discounts.0.code', 'SAVE10')
            ->assertJsonPath('data.shipping_total', '25.00')
            ->assertJsonPath('data.shipping.title', 'Express')
            ->assertJsonPath('data.tax_total', '18.45')
            ->assertJsonPath('data.grand_total', '227.95');
        $this->getJson($this->api('/options'), $this->auth())->assertOk()->assertJsonPath('data.shipping.options.0.name.en', 'Express')->assertJsonPath('data.tax.rate', '10.000');
        $this->postJson($this->api('/calculate'), $base + ['applied_discount' => ['type' => 'code', 'code' => 'NOPE']], $this->auth())
            ->assertUnprocessable()->assertJsonValidationErrors('applied_discount.code');
        // A manual discount with a reason and a custom shipping line.
        $id = $this->create(['applied_discount' => ['type' => 'manual', 'value_type' => 'fixed', 'value' => '30.00', 'reason' => 'Loyal customer'],
            'shipping_line' => ['type' => 'custom', 'title' => 'Courier', 'price' => '12.00']])->assertCreated()->json('data.id');
        $this->postJson($this->api("/{$id}/calculate"), [], $this->auth())->assertOk()
            ->assertJsonPath('data.discount_total', '30.00')->assertJsonPath('data.discounts.0.title', 'Loyal customer')
            ->assertJsonPath('data.shipping_total', '12.00')->assertJsonPath('data.tax_total', '17.50')->assertJsonPath('data.grand_total', '204.50');
        // Stock is validated but not reserved.
        $this->postJson($this->api('/calculate'), $this->payload(['line_items' => [['type' => 'product', 'product_id' => $this->shirt->id, 'quantity' => 6]]]), $this->auth())
            ->assertUnprocessable()->assertJsonValidationErrors('line_items.0.quantity');
        $this->assertSame(0, $this->shirt->fresh()->reserved_quantity);
        // Percentage over 100 and foreign products are refused.
        $foreign = Product::create(['store_id' => $this->other->id, 'name' => 'Foreign', 'slug' => 'foreign', 'price' => 5, 'is_active' => true]);
        $this->postJson($this->api('/calculate'), $this->payload(['applied_discount' => ['type' => 'manual', 'value_type' => 'percentage', 'value' => '150']]), $this->auth())->assertUnprocessable();
        $this->postJson($this->api('/calculate'), $this->payload(['line_items' => [['type' => 'product', 'product_id' => $foreign->id, 'quantity' => 1]]]), $this->auth())
            ->assertUnprocessable()->assertJsonValidationErrors('line_items.0.product_id');
    }

    public function test_variants_are_priced_and_custom_lines_need_a_title_and_price(): void
    {
        $variant = ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $this->mug->id, 'name' => 'Large', 'options' => ['Size' => 'L'], 'sku' => 'MUG-L', 'price_override' => 26, 'is_active' => true]);
        $this->postJson($this->api('/calculate'), $this->payload(['line_items' => [['type' => 'product', 'product_id' => $this->mug->id, 'variant_id' => $variant->id, 'quantity' => 2]]]), $this->auth())
            ->assertOk()->assertJsonPath('data.line_items.0.title', 'Mug — Large')->assertJsonPath('data.line_items.0.sku', 'MUG-L')->assertJsonPath('data.subtotal', '52.00');
        $this->postJson($this->api('/calculate'), $this->payload(['line_items' => [['type' => 'product', 'product_id' => $this->mug->id, 'quantity' => 1]]]), $this->auth())->assertUnprocessable();
        $this->postJson($this->api('/calculate'), $this->payload(['line_items' => [['type' => 'custom', 'quantity' => 1]]]), $this->auth())
            ->assertUnprocessable()->assertJsonValidationErrors(['line_items.0.title', 'line_items.0.unit_price']);
    }

    public function test_invoice_link_uses_the_store_public_host_and_rotates(): void
    {
        $id = $this->create()->json('data.id');
        $response = $this->postJson($this->api("/{$id}/invoice"), ['locale' => 'ar'], $this->auth())->assertOk()
            ->assertJsonPath('data.status', 'invoice_sent');
        $url = $response->json('invoice.url');
        $this->assertMatchesRegularExpression('#^https://alpha\.sellchase\.com/invoices/[a-f0-9]{48}$#', $url);
        $this->assertStringContainsString($url, $response->json('invoice.message'));
        $this->assertStringContainsString('D-1001', $response->json('invoice.messages.en'));
        $this->assertStringStartsWith('https://wa.me/201001112233?text=', $response->json('invoice.whatsapp_url'));
        $this->assertNotNull($response->json('data.expires_at'));
        // Only the hash is stored.
        $token = basename($url);
        $this->assertDatabaseMissing('store_draft_orders', ['invoice_token_hash' => $token]);
        $this->assertDatabaseHas('store_draft_orders', ['id' => $id, 'invoice_token_hash' => StoreDraftOrder::hashToken($token)]);
        $this->getJson($this->storefront($token))->assertOk();
        $this->invoice($id);
        $this->getJson($this->storefront($token))->assertNotFound();
        // Nothing is ever sent by the platform.
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_complete_creates_exactly_one_order_with_reserved_stock_and_correct_totals(): void
    {
        Coupon::create(['store_id' => $this->store->id, 'method' => 'code', 'code' => 'TEN', 'type' => 'fixed', 'value' => '10.00', 'is_active' => true]);
        $id = $this->create(['applied_discount' => ['type' => 'code', 'code' => 'TEN']])->json('data.id');
        $first = $this->postJson($this->api("/{$id}/complete"), ['payment_status' => 'paid'], $this->auth())->assertCreated()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('order.payment_status', 'paid')->assertJsonPath('created', true);
        $orderId = $first->json('order.id');
        $this->postJson($this->api("/{$id}/complete"), ['payment_status' => 'paid'], $this->auth())->assertOk()
            ->assertJsonPath('order.id', $orderId)->assertJsonPath('created', false);

        $this->assertDatabaseCount('store_orders', 1);
        $order = StoreOrder::forStore($this->store)->with('items')->findOrFail($orderId);
        // 205 - 10 = 195; tax 19.50; shipping 15 => 229.50
        $this->assertSame('205.00', $order->subtotal);
        $this->assertSame('10.00', $order->discount_total);
        $this->assertSame('19.50', $order->tax_total);
        $this->assertSame('15.00', $order->shipping_total);
        $this->assertSame('229.50', $order->grand_total);
        $this->assertSame('TEN', $order->coupon_code);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('cod', $order->payment_method);
        $this->assertSame('12 Nile St', $order->shipping_address['line1']);
        $this->assertCount(2, $order->items);
        $this->assertNull($order->items->firstWhere('name', 'Gift wrapping')->store_product_id);
        $this->assertSame(2, $this->shirt->fresh()->reserved_quantity);
        $this->assertDatabaseCount('store_inventory_movements', 1);
        $this->assertDatabaseCount('coupon_usages', 1);
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $orderId, 'source' => 'draft_order']);
        $this->assertSame(1, DB::table('outbox_messages')->where('event_type', 'StorefrontOrderPlaced')->where('aggregate_id', (string) $orderId)->count());
        $this->assertSame(1, $this->owner->notifications()->where('type', 'App\\Notifications\\StoreOrderActivity')->count());
        $this->assertDatabaseHas('store_draft_orders', ['id' => $id, 'status' => 'completed', 'completed_order_id' => $orderId, 'completed_by_id' => $this->owner->id]);
    }

    public function test_pending_payment_completion_and_stock_is_rechecked(): void
    {
        $id = $this->create(['line_items' => [['type' => 'product', 'product_id' => $this->shirt->id, 'quantity' => 4]]])->json('data.id');
        $other = $this->create(['line_items' => [['type' => 'product', 'product_id' => $this->shirt->id, 'quantity' => 3]]])->json('data.id');
        $this->postJson($this->api("/{$id}/complete"), ['payment_status' => 'pending'], $this->auth())->assertCreated()->assertJsonPath('order.payment_status', 'pending');
        // Only 1 left: the second draft cannot be converted and nothing is written.
        $this->postJson($this->api("/{$other}/complete"), ['payment_status' => 'pending'], $this->auth())->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertSame(4, $this->shirt->fresh()->reserved_quantity);
        $this->assertDatabaseHas('store_draft_orders', ['id' => $other, 'status' => 'open']);
        $this->postJson($this->api("/{$id}/complete"), [], $this->auth())->assertUnprocessable()->assertJsonValidationErrors('payment_status');
    }

    public function test_cancelled_and_completed_drafts_cannot_be_edited(): void
    {
        $cancelled = $this->create()->json('data.id');
        $this->postJson($this->api("/{$cancelled}/cancel"), [], $this->auth())->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->putJson($this->api("/{$cancelled}"), $this->payload(), $this->auth())->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson($this->api("/{$cancelled}/invoice"), [], $this->auth())->assertUnprocessable();
        $this->postJson($this->api("/{$cancelled}/complete"), ['payment_status' => 'paid'], $this->auth())->assertUnprocessable();

        $completed = $this->create()->json('data.id');
        $this->postJson($this->api("/{$completed}/complete"), ['payment_status' => 'pending'], $this->auth())->assertCreated();
        $this->putJson($this->api("/{$completed}"), $this->payload(), $this->auth())->assertUnprocessable();
        $this->postJson($this->api("/{$completed}/cancel"), [], $this->auth())->assertUnprocessable();
        $this->deleteJson($this->api("/{$completed}"), [], $this->auth())->assertUnprocessable();
        $this->assertDatabaseHas('store_draft_orders', ['id' => $completed]);

        // Duplicate makes a fresh open draft with a new number.
        $this->postJson($this->api("/{$completed}/duplicate"), [], $this->auth())->assertCreated()
            ->assertJsonPath('data.status', 'open')->assertJsonPath('data.number', 'D-1003')->assertJsonPath('data.completed_order_id', null)
            ->assertJsonPath('data.line_items.0.product_id', $this->shirt->id);
    }

    public function test_public_invoice_view_and_cash_on_delivery_checkout_create_the_order(): void
    {
        $invoice = $this->invoice($this->create()->json('data.id'));
        $this->getJson($this->storefront($invoice['token']))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.number', 'D-1001')
            ->assertJsonPath('data.store.name', 'Alpha Shop')
            ->assertJsonPath('data.line_items.0.title', 'Shirt')
            ->assertJsonPath('data.totals.grand_total', '240.50')
            ->assertJsonPath('data.payment_methods.0.slug', 'cod')
            ->assertJsonMissingPath('data.note')
            ->assertJsonMissingPath('data.tags');

        $body = ['customer_name' => 'Laila H.', 'customer_phone' => '+201001112233', 'customer_email' => 'laila@example.test',
            'shipping_address' => ['line1' => '99 Corniche', 'city' => 'Alexandria', 'country' => 'EG'], 'notes' => 'Ring twice', 'payment_method' => 'cod'];
        $response = $this->postJson($this->storefront($invoice['token'].'/checkout'), $body)->assertCreated()
            ->assertJsonPath('data.grand_total', '240.50')->assertJsonPath('data.payment_method', 'cod')->assertJsonPath('data.customer_name', 'Laila H.');
        $this->assertNotEmpty($response->json('receipt.token'));
        $orderId = $response->json('data.id');
        $this->assertDatabaseHas('store_orders', ['id' => $orderId, 'notes' => 'Ring twice', 'payment_status' => 'pending']);
        $this->assertSame('99 Corniche', StoreOrder::forStore($this->store)->findOrFail($orderId)->shipping_address['line1']);
        $this->assertDatabaseHas('store_payment_transactions', ['store_order_id' => $orderId, 'gateway' => 'cod', 'status' => 'pending']);
        $this->assertDatabaseHas('store_draft_orders', ['id' => $invoice['id'], 'status' => 'completed', 'completed_order_id' => $orderId]);
        $this->assertSame(2, $this->shirt->fresh()->reserved_quantity);

        // A double-submit returns the same order; viewing a completed invoice is refused.
        $this->postJson($this->storefront($invoice['token'].'/checkout'), $body)->assertOk()->assertJsonPath('data.id', $orderId);
        $this->assertDatabaseCount('store_orders', 1);
        $this->getJson($this->storefront($invoice['token']))->assertStatus(409);
    }

    public function test_public_checkout_validates_contact_and_payment_method(): void
    {
        $invoice = $this->invoice($this->create()->json('data.id'));
        $this->postJson($this->storefront($invoice['token'].'/checkout'), ['payment_method' => 'cod'])->assertUnprocessable()->assertJsonValidationErrors('customer_name');
        $this->postJson($this->storefront($invoice['token'].'/checkout'), ['payment_method' => 'stripe', 'customer_name' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_expired_foreign_cancelled_and_unknown_tokens_are_not_found(): void
    {
        $invoice = $this->invoice($this->create()->json('data.id'));
        // Another store's host never resolves this store's token.
        $this->getJson($this->storefront($invoice['token'], 'beta.sellchase.com'))->assertNotFound();
        $this->postJson($this->storefront($invoice['token'].'/checkout', 'beta.sellchase.com'), ['payment_method' => 'cod', 'customer_name' => 'X'])->assertNotFound();
        $this->getJson($this->storefront(str_repeat('a', 48)))->assertNotFound();
        $this->getJson($this->storefront('short'))->assertNotFound();

        StoreDraftOrder::forStore($this->store)->whereKey($invoice['id'])->update(['expires_at' => now()->subMinute()]);
        $this->getJson($this->storefront($invoice['token']))->assertNotFound();
        $this->postJson($this->storefront($invoice['token'].'/checkout'), ['payment_method' => 'cod', 'customer_name' => 'X', 'customer_phone' => '+201001112233',
            'shipping_address' => ['line1' => 'x', 'city' => 'y']])->assertNotFound();

        $cancelled = $this->invoice($this->create()->json('data.id'));
        $this->postJson($this->api("/{$cancelled['id']}/cancel"), [], $this->auth())->assertOk();
        $this->getJson($this->storefront($cancelled['token']))->assertNotFound();
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_stores_are_isolated_and_staff_need_the_orders_permission(): void
    {
        $id = $this->create()->json('data.id');
        $this->getJson($this->api("/{$id}", $this->other), $this->auth($this->otherOwner))->assertNotFound();
        $this->putJson($this->api("/{$id}", $this->other), $this->payload(), $this->auth($this->otherOwner))->assertNotFound();
        $this->postJson($this->api("/{$id}/complete", $this->other), ['payment_status' => 'paid'], $this->auth($this->otherOwner))->assertNotFound();
        $this->getJson($this->api('', $this->other), $this->auth($this->otherOwner))->assertOk()->assertJsonCount(0, 'data');
        // Another merchant cannot reach this store's drafts at all.
        $this->getJson($this->api("/{$id}"), $this->auth($this->otherOwner))->assertForbidden();
        $this->assertDatabaseCount('store_orders', 0);
    }
}
