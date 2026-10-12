<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReferralLinksTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private array $auth;

    private string $base = 'http://referral.sellchase.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Notification::fake();
        config(['sellchase.storefront.base_domain' => 'sellchase.com', 'sellchase.storefront.canonical_scheme' => 'https']);
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Referral shop', 'slug' => 'referral', 'status' => 'active', 'currency' => 'EGP']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'referral.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true, 'test_mode' => false, 'sort_order' => 0, 'credentials' => []]);
        $this->product = $this->product($this->store, 'Bag_100%', 'referral-bag');
    }

    private function product(Store $store, string $name, string $slug, bool $active = true): Product
    {
        return Product::create(['store_id' => $store->id, 'name' => $name, 'slug' => $slug, 'price' => 275, 'cost' => 125, 'is_active' => $active, 'track_inventory' => true, 'stock_quantity' => 20]);
    }

    private function body(array $extra = []): array
    {
        return $extra + ['cart_mode' => 'direct', 'payment_method' => 'cod', 'customer_name' => 'Named referral test buyer', 'customer_phone' => '+201001234567', 'customer_email' => 'referral-test@example.test',
            'shipping_address' => ['line1' => 'Named local test address', 'city' => 'Cairo', 'country' => 'EG'],
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'analytics_session' => (string) Str::uuid()];
    }

    private function otherStore(): Store
    {
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');

        return Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Other store', 'slug' => 'other', 'status' => 'active']);
    }

    public function test_merchant_choices_are_bounded_minimal_literal_search_and_tenant_scoped(): void
    {
        $this->product($this->store, 'BagX1000', 'other-bag');
        $this->product($this->store, 'Draft', 'draft-bag', false);
        $other = $this->otherStore();
        $foreign = $this->product($other, 'Bag_100%', 'foreign-bag');
        $response = $this->getJson('/api/v1/my-store/referral-products?'.http_build_query(['q' => 'Bag_100%']), $this->auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->product->id);
        $response->assertJsonPath('storefront_url', 'https://referral.sellchase.com/')->assertJsonMissingPath('data.0.cost')->assertJsonMissingPath('data.0.digital_url');
        $this->getJson('/api/v1/my-store/referral-products', $this->auth)->assertOk()->assertJsonCount(2, 'data')->assertJsonMissing(['id' => $foreign->id]);
        $this->getJson('/api/v1/my-store/referral-products')->assertUnauthorized();
        $this->getJson('/api/v1/stores/'.$other->id.'/referral-products', $this->auth)->assertForbidden();
        for ($i = 0; $i < 20; $i++) {
            $this->product($this->store, 'Page '.$i, 'page-'.$i);
        }
        $this->getJson('/api/v1/my-store/referral-products', $this->auth)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 2);
        Http::assertNothingSent();
    }

    public function test_public_lookup_and_redirect_only_resolve_active_products_in_the_host_store(): void
    {
        $this->getJson($this->base.'/api/v1/storefront/referral-products/'.$this->product->id)->assertOk()->assertExactJson(['data' => ['id' => $this->product->id, 'slug' => 'referral-bag']]);
        $code = 'مسوق_1';
        $redirect = $this->get($this->base.'/products/'.$this->product->id.'/ref/'.rawurlencode($code).'?variant=12&utm_source=meta&utm_campaign=summer')->assertRedirect();
        $target = parse_url($redirect->headers->get('Location'));
        parse_str($target['query'], $query);
        $this->assertSame('/products/referral-bag', $target['path']);
        $this->assertSame(['variant' => '12', 'utm_source' => 'meta', 'utm_campaign' => 'summer', 'ref' => $code], $query);
        $this->assertStringContainsString('no-store', $redirect->headers->get('Cache-Control'));
        $this->get($this->base.'/products/'.$this->product->id.'/ref/'.rawurlencode('<script>'))->assertNotFound();
        $draft = $this->product($this->store, 'Draft', 'draft-bag', false);
        $other = $this->otherStore();
        $foreign = $this->product($other, 'Foreign', 'foreign-bag');
        foreach ([$draft->id, $foreign->id, 999999] as $id) {
            $this->getJson($this->base.'/api/v1/storefront/referral-products/'.$id)->assertNotFound();
            $this->get($this->base.'/products/'.$id.'/ref/ref_1')->assertNotFound();
        }
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_checkout_keeps_private_immutable_referral_and_campaign_snapshots_without_changing_price(): void
    {
        $tags = ['code' => 'مسوق_1', 'product_id' => $this->product->id];
        $body = $this->body(['referral_attribution' => $tags, 'marketing_attribution' => ['source' => 'meta', 'campaign' => 'summer']]);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $response = $this->postJson($this->base.'/api/v1/storefront/checkout', $body, $headers)->assertCreated()->assertJsonMissingPath('data.referral_attribution');
        $id = $response->json('data.id');
        $order = StoreOrder::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame($tags, $order->referral_attribution);
        $this->assertSame('275.00', $order->grand_total);
        $this->assertSame(['source' => 'meta', 'campaign' => 'summer'], $order->marketing_attribution);
        $this->assertArrayNotHasKey('referral_attribution', $order->toArray());
        $this->getJson('/api/v1/my-store/orders/'.$id, $this->auth)->assertOk()->assertJsonPath('data.referral_attribution', $tags);
        $this->postJson($this->base.'/api/v1/storefront/checkout/receipt', ['token' => $response->json('receipt.token')])->assertOk()->assertJsonMissingPath('data.referral_attribution');
        $this->postJson($this->base.'/api/v1/storefront/checkout', $body, $headers)->assertCreated()->assertJsonPath('data.id', $id);
        $body['referral_attribution']['code'] = 'different';
        $this->postJson($this->base.'/api/v1/storefront/checkout', $body, $headers)->assertConflict();
        $this->assertSame($tags, $order->fresh()->referral_attribution);
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseCount('storefront_events', 0);
        Http::assertNothingSent();
    }

    public function test_invalid_codes_foreign_draft_and_unknown_contact_fields_reject_before_order_or_stock_changes(): void
    {
        $other = $this->otherStore();
        $foreign = $this->product($other, 'Foreign', 'foreign-bag');
        $draft = $this->product($this->store, 'Draft', 'draft-bag', false);
        foreach ([['code' => '<script>', 'product_id' => $this->product->id], ['code' => str_repeat('a', 101), 'product_id' => $this->product->id], ['code' => 'ref/other', 'product_id' => $this->product->id], ['code' => 'ref_1', 'product_id' => $foreign->id], ['code' => 'ref_1', 'product_id' => $draft->id], ['code' => 'ref_1', 'product_id' => $this->product->id, 'contact' => 'private']] as $tags) {
            $this->postJson($this->base.'/api/v1/storefront/checkout', $this->body(['referral_attribution' => $tags]), ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->postJson($this->base.'/api/v1/storefront/checkout', $this->body(['referral_attribution' => ['code' => 'ref_1', 'product_id' => $this->product->id], 'analytics_session' => null]), ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertSame(20, $this->product->fresh()->stock_quantity);
        $response = $this->postJson($this->base.'/api/v1/storefront/checkout', $this->body(), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertNull(StoreOrder::withoutGlobalScopes()->findOrFail($response->json('data.id'))->referral_attribution);
    }

    public function test_populated_referral_snapshots_prevent_rollback(): void
    {
        $this->postJson($this->base.'/api/v1/storefront/checkout', $this->body(['referral_attribution' => ['code' => 'ref_1', 'product_id' => $this->product->id]]), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $migration = require database_path('migrations/2026_10_12_080000_add_order_referral_attribution.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
