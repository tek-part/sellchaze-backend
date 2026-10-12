<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreCheckoutLead;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StoreQuantityOffersTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private array $auth;

    private array $offers;

    private string $public = 'http://offers.sellchase.com/api/v1/storefront';

    private string $private;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Offers', 'slug' => 'offers', 'currency' => 'EGP', 'status' => 'active',
            'shipping_enabled' => true, 'shipping_flat_rate' => '35.00', 'shipping_free_over' => 10000]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'offers.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Organizer', 'slug' => 'organizer', 'price' => 750, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 20]);
        $this->private = '/api/v1/my-store/catalog/products/'.$this->product->id.'/quantity-offers';
        $this->offers = [['id' => (string) Str::uuid(), 'quantity' => 2, 'unit_price' => '700.00', 'free_shipping' => true, 'title' => ['ar' => 'قطعتان', 'en' => 'Two pieces']],
            ['id' => (string) Str::uuid(), 'quantity' => 3, 'unit_price' => '650.00', 'free_shipping' => false]];
    }

    private function saveOffers(): void
    {
        $this->putJson($this->private, ['version' => 1, 'offers' => $this->offers], $this->auth)->assertOk()->assertJsonPath('version', 2);
    }

    private function item(array $extra = []): array
    {
        return $extra + ['product_id' => $this->product->id, 'quantity' => 2, 'quantity_offer_id' => $this->offers[0]['id'], 'quantity_offer_version' => 2];
    }

    private function checkout(array $extra = []): array
    {
        return $extra + ['customer_name' => 'Offer reader', 'customer_email' => 'offer@example.test', 'customer_phone' => '01001234567',
            'shipping_address' => ['line1' => 'Review address', 'city' => 'Cairo', 'country' => 'EG'], 'items' => [$this->item()]];
    }

    public function test_private_configuration_is_versioned_validated_and_publishes_only_enabled_offers(): void
    {
        $this->getJson($this->private, $this->auth)->assertOk()->assertJsonPath('version', 1)->assertJsonCount(0, 'data');
        $this->offers[1]['enabled'] = false;
        $this->saveOffers();
        $this->getJson($this->public.'/products/organizer')->assertOk()->assertJsonCount(1, 'data.quantity_offers')
            ->assertJsonPath('data.quantity_offers.0.unit_price', '700.00')->assertJsonPath('data.quantity_offers.0.version', 2);
        $this->putJson($this->private, ['version' => 1, 'offers' => []], $this->auth)->assertConflict();
        $bad = $this->offers;
        $bad[0]['unit_price'] = '751.00';
        $this->putJson($this->private, ['version' => 2, 'offers' => $bad], $this->auth)->assertUnprocessable();
        $bad = $this->offers;
        $bad[1]['quantity'] = '2';
        $this->putJson($this->private, ['version' => 2, 'offers' => $bad], $this->auth)->assertUnprocessable();
        $this->assertSame(2, $this->product->fresh()->quantity_offers_version);
        $this->assertSame(20, $this->product->fresh()->stock_quantity);
    }

    public function test_quote_prices_complete_packages_on_the_server_and_regular_units_keep_their_price(): void
    {
        $this->saveOffers();
        $this->postJson($this->public.'/checkout/quote', ['items' => [$this->item(['unit_price' => 1, 'free_shipping' => false])]])->assertOk()
            ->assertJsonPath('data.items.0.unit_price', '700.00')->assertJsonPath('data.totals.subtotal', '1400.00')
            ->assertJsonPath('data.totals.shipping_total', '0.00')->assertJsonPath('data.offer_free_shipping', true);
        $this->postJson($this->public.'/checkout/quote', ['items' => [['product_id' => $this->product->id, 'quantity' => 2]]])->assertOk()
            ->assertJsonPath('data.totals.subtotal', '1500.00')->assertJsonPath('data.totals.shipping_total', '35.00');
        foreach ([['quantity' => 3], ['quantity_offer_version' => 1], ['quantity_offer_id' => (string) Str::uuid()], ['quantity_offer_version' => null]] as $bad) {
            $this->postJson($this->public.'/checkout/quote', ['items' => [$this->item($bad)]])->assertUnprocessable();
        }
        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertSame(0, $this->product->fresh()->reserved_quantity);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_offer_regular_and_other_package_lines_stay_separate_and_share_stock(): void
    {
        $this->saveOffers();
        app(CurrentStore::class)->set($this->store);
        $service = app(CartService::class);
        $cart = $service->create($this->store, null);
        $regular = $service->addItem($cart, $this->product->id, 1);
        $offer = $service->addItem($cart, $this->product->id, 2, null, [], $this->offers[0]['id'], 2);
        $service->addItem($cart, $this->product->id, 2, null, [], $this->offers[0]['id'], 2);
        $this->assertSame(2, $cart->items()->count());
        $this->assertSame(4, $offer->fresh()->quantity);
        $this->assertSame('750.00', $regular->fresh()->unit_price);
        $this->assertSame('3550.00', $cart->fresh('items')->subtotal());
        try {
            $service->updateItem($cart, $offer->fresh(), 3);
            $this->fail('Partial packages must fail');
        } catch (ValidationException) {
        }
        $this->product->update(['stock_quantity' => 5]);
        try {
            $service->addItem($cart, $this->product->id, 3, null, [], $this->offers[1]['id'], 2);
            $this->fail('Offers share the same stock');
        } catch (ValidationException) {
        }
        $this->assertSame(2, $cart->items()->count());
        $this->assertSame(4, $offer->fresh()->quantity);
        $this->assertSame(0, $this->product->fresh()->reserved_quantity);
    }

    public function test_order_snapshot_stock_and_replay_keep_the_selected_price_after_configuration_changes(): void
    {
        $this->saveOffers();
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $first = $this->postJson($this->public.'/checkout', $this->checkout(), $headers)->assertCreated();
        $order = StoreOrder::withoutGlobalScopes()->findOrFail($first->json('data.id'));
        $this->assertSame('1400.00', $order->subtotal);
        $this->assertSame('0.00', $order->shipping_total);
        $this->assertTrue($order->shipping_address['shipping_details']['quantity_offer_free_shipping']);
        $line = $order->items()->firstOrFail();
        $this->assertSame('700.00', $line->unit_price);
        $this->assertSame($this->offers[0]['id'], $line->quantity_offer['id']);
        $this->assertSame(2, $this->product->fresh()->reserved_quantity);
        $changed = $this->offers;
        $changed[0]['unit_price'] = 650;
        $this->putJson($this->private, ['version' => 2, 'offers' => $changed], $this->auth)->assertOk();
        $this->postJson($this->public.'/checkout', $this->checkout(), $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertSame(2, $this->product->fresh()->reserved_quantity);
        $this->assertSame('700.00', $line->fresh()->quantity_offer['unit_price']);
    }

    public function test_edited_or_deleted_offer_rejects_a_new_order_atomically_and_variants_cannot_raise_the_offer_price(): void
    {
        $this->saveOffers();
        $this->putJson($this->private, ['version' => 2, 'offers' => []], $this->auth)->assertOk();
        $this->postJson($this->public.'/checkout', $this->checkout(), ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('carts', 0);
        $this->putJson($this->private, ['version' => 3, 'offers' => $this->offers], $this->auth)->assertOk();
        $cheap = ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $this->product->id, 'name' => 'Cheap', 'sku' => 'CHEAP', 'price_override' => 600, 'is_active' => true, 'stock_quantity' => 10]);
        $this->postJson($this->public.'/checkout/quote', ['items' => [$this->item(['variant_id' => $cheap->id, 'quantity_offer_version' => 4])]])->assertUnprocessable();
        $cheap->update(['price_override' => 900]);
        $this->postJson($this->public.'/checkout/quote', ['items' => [$this->item(['variant_id' => $cheap->id, 'quantity_offer_version' => 4])]])->assertOk()->assertJsonPath('data.items.0.unit_price', '700.00');
    }

    public function test_contact_capture_snapshots_offer_prices_without_stock_or_order_side_effects(): void
    {
        $this->saveOffers();
        $this->postJson($this->public.'/checkout-leads', ['lead_token' => (string) Str::uuid(), 'sequence' => 1, 'source' => 'storefront',
            'customer_name' => 'Offer reader', 'customer_phone' => '01001234567', 'items' => [$this->item()]])->assertStatus(202);
        $lead = StoreCheckoutLead::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('1400.00', $lead->subtotal);
        $this->assertSame('700.00', $lead->items[0]['unit_price']);
        $this->assertSame(2, $lead->items[0]['quantity_offer']['version']);
        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertSame(0, $this->product->fresh()->reserved_quantity);
    }

    public function test_coupon_and_tax_previews_match_offer_checkout_including_legacy_cart_endpoints(): void
    {
        $this->saveOffers();
        $this->store->update(['tax_enabled' => true, 'tax_rate' => 10, 'tax_prices_include' => false]);
        app(CurrentStore::class)->set($this->store);
        $coupon = Coupon::create(['store_id' => $this->store->id, 'code' => 'OFFER10', 'type' => 'percentage', 'value' => 10, 'is_active' => true]);
        $added = $this->postJson($this->public.'/cart/items', ['store_product_id' => $this->product->id, 'quantity' => 2, 'quantity_offer_id' => $this->offers[0]['id'], 'quantity_offer_version' => 2])->assertCreated();
        $headers = ['X-Cart-Token' => $added->json('data.token')];
        $this->postJson($this->public.'/checkout/coupon/apply', ['code' => $coupon->code], $headers)->assertOk()->assertJsonPath('totals.grand_total', '1386.00');
        $this->postJson($this->public.'/checkout/quote', ['items' => [$this->item()], 'coupon_code' => $coupon->code])->assertOk()
            ->assertJsonPath('data.totals.tax_total', '126.00')->assertJsonPath('data.totals.grand_total', '1386.00');
        $created = $this->postJson($this->public.'/checkout', $this->checkout(['coupon_code' => $coupon->code]), $headers)->assertCreated();
        $order = StoreOrder::withoutGlobalScopes()->findOrFail($created->json('data.id'));
        $this->assertSame('1386.00', $order->grand_total);
        $this->assertSame(1, $coupon->usages()->count());
    }

    public function test_private_and_public_tenant_boundaries_and_populated_rollback_preserve_offers(): void
    {
        $this->saveOffers();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-offers', 'currency' => 'EGP', 'status' => 'active']);
        $foreign = Product::create(['store_id' => $other->id, 'name' => 'Other', 'price' => 1, 'is_active' => true]);
        $this->getJson('/api/v1/my-store/catalog/products/'.$foreign->id.'/quantity-offers', $this->auth)->assertNotFound();
        $this->putJson('/api/v1/stores/'.$other->id.'/catalog/products/'.$foreign->id.'/quantity-offers', ['version' => 1, 'offers' => []], $this->auth)->assertForbidden();
        $this->getJson($this->private)->assertUnauthorized();
        $this->postJson($this->public.'/checkout/quote', ['items' => [$this->item(['product_id' => $foreign->id])]])->assertUnprocessable();
        $migration = require database_path('migrations/2026_10_12_030000_add_product_quantity_offers.php');
        try {
            $migration->down();
            $this->fail('Configured offers must survive rollback');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Preserve', $error->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('products', 'quantity_offers'));
        $this->assertCount(2, $this->product->fresh()->quantity_offers);
    }

    public function test_digital_offer_does_not_waive_physical_delivery_and_free_offers_still_require_a_valid_region(): void
    {
        $this->saveOffers();
        $physical = Product::create(['store_id' => $this->store->id, 'name' => 'Physical companion', 'price' => 250, 'is_active' => true]);
        $items = [$this->item(), ['product_id' => $physical->id, 'quantity' => 1]];
        $this->postJson($this->public.'/checkout/quote', ['items' => $items])->assertOk()
            ->assertJsonPath('data.totals.subtotal', '1650.00')->assertJsonPath('data.totals.shipping_total', '0.00');
        $this->product->update(['digital_type' => 'link', 'digital_url' => 'https://example.test/review.pdf']);
        $this->postJson($this->public.'/checkout/quote', ['items' => $items])->assertOk()
            ->assertJsonPath('data.offer_free_shipping', false)->assertJsonPath('data.totals.shipping_total', '35.00');
        $this->product->update(['digital_type' => 'physical']);
        $regionId = (string) Str::uuid();
        $this->store->update(['shipping_configuration' => ['regions_enabled' => true, 'auto_select_region' => false,
            'regions' => [['id' => $regionId, 'name' => ['ar' => 'القاهرة', 'en' => 'Cairo'], 'country' => 'EG', 'rate' => '50.00', 'enabled' => true, 'position' => 0]], 'options' => []]]);
        $this->postJson($this->public.'/checkout/quote', ['items' => $items, 'shipping_region_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($this->public.'/checkout/quote', ['items' => $items, 'shipping_region_id' => $regionId])->assertOk()->assertJsonPath('data.totals.shipping_total', '0.00');
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertSame(0, $this->product->fresh()->reserved_quantity);
    }

    public function test_historical_order_offer_snapshot_prevents_rollback_after_all_live_offers_are_removed(): void
    {
        $this->saveOffers();
        $created = $this->postJson($this->public.'/checkout', $this->checkout())->assertCreated();
        $order = StoreOrder::withoutGlobalScopes()->findOrFail($created->json('data.id'));
        $this->putJson($this->private, ['version' => 2, 'offers' => []], $this->auth)->assertOk();
        // Isolate the historical guard: the converted cart is no longer the blocker.
        DB::table('cart_items')->update(['quantity_offer' => null]);
        $migration = require database_path('migrations/2026_10_12_030000_add_product_quantity_offers.php');
        try {
            $migration->down();
            $this->fail('Historical order snapshots must survive rollback');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Preserve', $error->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('store_order_items', 'quantity_offer'));
        $this->assertSame('700.00', $order->items()->firstOrFail()->quantity_offer['unit_price']);
    }
}
