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

class CampaignAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private array $auth;

    private string $base = 'http://campaign.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Notification::fake();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Campaign shop', 'slug' => 'campaign', 'status' => 'active', 'currency' => 'EGP']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'campaign.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true, 'test_mode' => false, 'sort_order' => 0, 'credentials' => []]);
        $this->product = Product::create(['store_id' => $this->store->id, 'name' => 'Campaign bag', 'slug' => 'campaign-bag', 'price' => 275, 'cost' => 125, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 10]);
    }

    private function body(array $extra = []): array
    {
        return $extra + ['cart_mode' => 'direct', 'payment_method' => 'cod', 'customer_name' => 'Named campaign test buyer', 'customer_phone' => '+201001234567', 'customer_email' => 'campaign-test@example.test',
            'shipping_address' => ['line1' => 'Named local test address', 'city' => 'Cairo', 'country' => 'EG'],
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'analytics_session' => (string) Str::uuid()];
    }

    public function test_tagged_checkout_keeps_an_immutable_private_order_snapshot_without_fabricated_visits(): void
    {
        $body = $this->body(['marketing_attribution' => ['source' => '  meta   social ', 'campaign' => 'عرض  الصيف']]);
        $key = (string) Str::uuid();
        $headers = ['Idempotency-Key' => $key];
        $response = $this->postJson($this->base.'/checkout', $body, $headers)->assertCreated();
        $id = $response->json('data.id');
        $order = StoreOrder::withoutGlobalScopes()->findOrFail($id);
        $expected = ['source' => 'meta social', 'campaign' => 'عرض الصيف'];
        $this->assertSame($expected, $order->marketing_attribution);
        $this->assertSame('275.00', $order->grand_total);
        $this->assertNull($order->analytics_session_hash);
        $this->assertArrayNotHasKey('marketing_attribution', $order->toArray());
        $response->assertJsonMissingPath('data.marketing_attribution');
        $this->getJson('/api/v1/my-store/orders/'.$id, $this->auth)->assertOk()->assertJsonPath('data.marketing_attribution', $expected);
        $this->postJson($this->base.'/checkout', $body, $headers)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseCount('storefront_events', 0);
        $this->assertSame($expected, $order->fresh()->marketing_attribution);
        $body['marketing_attribution']['campaign'] = 'Different campaign';
        $this->postJson($this->base.'/checkout', $body, $headers)->assertConflict();
        $this->assertSame($expected, $order->fresh()->marketing_attribution);
        Http::assertNothingSent();
    }

    public function test_unattributed_orders_remain_null_and_missing_session_does_not_create_an_attribution(): void
    {
        $this->postJson($this->base.'/checkout', $this->body(), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertNull(StoreOrder::withoutGlobalScopes()->firstOrFail()->marketing_attribution);
        $this->postJson($this->base.'/checkout', $this->body(['analytics_session' => null, 'marketing_attribution' => ['source' => 'meta', 'campaign' => 'test']]), ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 1);
    }

    public function test_validation_rejects_unknown_url_contact_fields_markup_controls_and_oversize_labels_before_placement(): void
    {
        foreach ([['source' => 'meta', 'campaign' => 'test', 'url' => 'https://private.example.com'], ['source' => '<script>', 'campaign' => 'test'], ['source' => 'meta', 'campaign' => "test\nprivate"], ['source' => str_repeat('a', 101), 'campaign' => 'test'], ['source' => 'meta', 'campaign' => str_repeat('a', 201)], ['source' => 'meta']] as $tags) {
            $this->postJson($this->base.'/checkout', $this->body(['marketing_attribution' => $tags]), ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertSame(10, $this->product->fresh()->stock_quantity);
        Http::assertNothingSent();
    }

    public function test_campaign_details_are_owner_scoped_and_are_not_returned_to_the_public_receipt(): void
    {
        $response = $this->postJson($this->base.'/checkout', $this->body(['marketing_attribution' => ['source' => 'meta', 'campaign' => 'test']]), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $id = $response->json('data.id');
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        Store::create(['owner_user_id' => $other->id, 'owner_type' => 'merchant', 'name' => 'Other shop', 'slug' => 'other', 'status' => 'active']);
        $auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($other)];
        $this->getJson('/api/v1/my-store/orders/'.$id, $auth)->assertNotFound();
        $this->getJson('/api/v1/stores/'.$this->store->id.'/orders/'.$id, $auth)->assertForbidden();
        $this->postJson($this->base.'/checkout/receipt', ['token' => $response->json('receipt.token')])->assertOk()->assertJsonMissingPath('data.marketing_attribution');
        Http::assertNothingSent();
    }

    public function test_populated_order_attribution_prevents_destructive_rollback(): void
    {
        $this->postJson($this->base.'/checkout', $this->body(['marketing_attribution' => ['source' => 'meta', 'campaign' => 'test']]), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $migration = require database_path('migrations/2026_10_12_070000_add_order_marketing_attribution.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
