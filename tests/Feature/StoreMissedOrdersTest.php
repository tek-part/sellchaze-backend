<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCheckoutLead;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreMissedOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private array $auth;

    private string $token;

    private string $public = 'http://leads.sellchase.com/api/v1/storefront';

    private string $private = '/api/v1/my-store/missed-orders';

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
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Leads', 'slug' => 'leads', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'leads.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 4]);
        $this->token = (string) Str::uuid();
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['lead_token' => $this->token, 'sequence' => 1, 'source' => 'storefront', 'source_path' => '/checkout',
            'customer_name' => 'Reader', 'customer_phone' => '01001234567', 'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 1]],
            'shipping_address' => ['line1' => 'Private address'], 'notes' => 'Private note'];
    }

    private function capture(array $extra = []): StoreCheckoutLead
    {
        $this->postJson($this->public.'/checkout-leads', $this->payload($extra))->assertStatus(202)->assertExactJson(['message' => 'Saved.']);

        return StoreCheckoutLead::withoutGlobalScopes()->where('store_id', $this->store->id)->orderByDesc('id')->firstOrFail();
    }

    public function test_capture_has_server_prices_and_no_order_inventory_coupon_or_outbound_side_effects(): void
    {
        $lead = $this->capture();
        $this->assertSame('200.00', $lead->subtotal);
        $this->assertSame('100.00', $lead->items[0]['unit_price']);
        $this->assertSame('+201001234567', $lead->phone_normalized);
        $this->assertStringNotContainsString($this->token, $lead->key_hash);
        $raw = DB::table('store_checkout_leads')->where('id', $lead->id)->first();
        $this->assertStringNotContainsString('Private address', $raw->shipping_address);
        $this->assertStringNotContainsString('Private note', $raw->notes);
        foreach (['carts', 'cart_items', 'store_orders', 'store_inventory_movements', 'store_payment_transactions', 'coupon_usages', 'notifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(4, $this->product->fresh()->stock_quantity);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->getJson($this->private, $this->auth)->assertOk()->assertJsonPath('meta.total', 0);
        $this->travel(6)->minutes();
        $this->getJson($this->private, $this->auth)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.subtotal', '200.00')
            ->assertJsonPath('data.0.shipping_address.line1', 'Private address')->assertJsonMissingPath('data.0.key_hash')->assertJsonMissingPath('data.0.capture_hash')->assertJsonMissingPath('data.0.capture_sequence');
    }

    public function test_canonical_updates_and_stale_or_duplicate_captures_are_idempotent(): void
    {
        $first = $this->capture();
        $this->travel(1)->minutes();
        $this->capture(['customer_name' => 'Stale duplicate']);
        $this->assertSame('Reader', $first->fresh()->customer_name);
        $this->assertTrue($first->last_activity_at->equalTo($first->fresh()->last_activity_at));
        $this->capture(['sequence' => 2, 'customer_name' => 'Latest', 'customer_phone' => '+20 100 123 4567']);
        $this->capture(['sequence' => 1, 'customer_name' => 'Old']);
        $this->assertSame('Latest', $first->fresh()->customer_name);
        $this->assertSame('+201001234567', $first->fresh()->phone_normalized);
        $this->assertSame(2, $first->fresh()->edit_version);
        $this->assertDatabaseCount('store_checkout_leads', 1);
    }

    public function test_clearing_contact_fences_older_requests_including_a_delayed_first_capture(): void
    {
        $clear = ['lead_token' => $this->token, 'sequence' => 2, 'source' => 'storefront', 'clear' => true];
        $this->postJson($this->public.'/checkout-leads', $clear)->assertStatus(202);
        $this->capture();
        $this->travel(6)->minutes();
        $this->getJson($this->private, $this->auth)->assertOk()->assertJsonPath('meta.total', 0);
        $this->capture(['sequence' => 3]);
        $this->postJson($this->public.'/checkout-leads', $clear)->assertStatus(202);
        $this->assertSame('Reader', StoreCheckoutLead::withoutGlobalScopes()->first()->customer_name);
        $this->postJson($this->public.'/checkout-leads', array_merge($clear, ['sequence' => 4]))->assertStatus(202);
        $this->capture(['sequence' => 3]);
        $this->assertNull(StoreCheckoutLead::withoutGlobalScopes()->first()->customer_phone);
        $this->assertNull(StoreCheckoutLead::withoutGlobalScopes()->first()->shipping_address);
    }

    public function test_successful_checkout_fences_captures_even_when_the_first_capture_arrives_after_order_creation(): void
    {
        $orderPayload = ['lead_token' => $this->token, 'customer_name' => 'Buyer', 'customer_email' => 'buyer@example.test', 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $first = $this->postJson($this->public.'/checkout', $orderPayload, $headers)->assertCreated();
        $this->capture(['sequence' => 99]);
        $this->postJson($this->public.'/checkout', $orderPayload, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->travel(6)->minutes();
        $this->getJson($this->private, $this->auth)->assertOk()->assertJsonPath('meta.total', 0);
        $lead = StoreCheckoutLead::withoutGlobalScopes()->firstOrFail();
        $this->assertNotNull($lead->converted_at);
        $this->assertSame($first->json('data.id'), $lead->store_order_id);
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertSame(4, $this->product->fresh()->stock_quantity);
        $this->assertSame(1, $this->product->fresh()->reserved_quantity);
    }

    public function test_existing_capture_is_hidden_atomically_on_order_creation_and_invalid_checkout_does_not_convert_it(): void
    {
        $lead = $this->capture();
        $payload = ['lead_token' => $this->token, 'customer_name' => 'Buyer', 'customer_email' => 'buyer@example.test', 'items' => [['product_id' => $this->product->id, 'quantity' => 999]]];
        $this->postJson($this->public.'/checkout', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertNull($lead->fresh()->converted_at);
        $payload['items'][0]['quantity'] = 1;
        $this->postJson($this->public.'/checkout', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertNotNull($lead->fresh()->converted_at);
        $this->travel(6)->minutes();
        $this->getJson($this->private, $this->auth)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_private_listing_is_tenant_scoped_and_public_capture_cannot_look_up_contacts(): void
    {
        $this->capture();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-leads', 'currency' => 'EGP', 'status' => 'active']);
        $otherProduct = Product::create(['store_id' => $other->id, 'name' => 'Other', 'slug' => 'other', 'price' => 1, 'is_active' => true]);
        $this->postJson($this->public.'/checkout-leads', $this->payload(['sequence' => 2, 'items' => [['product_id' => $otherProduct->id, 'quantity' => 1]], 'store_id' => $other->id]))->assertUnprocessable();
        StoreCheckoutLead::withoutGlobalScopes()->create(['store_id' => $other->id, 'key_hash' => hash('sha256', 'other'), 'customer_name' => 'Foreign secret', 'customer_email' => 'secret@example.test', 'last_activity_at' => now()->subMinutes(6), 'currency' => 'EGP']);
        $this->travel(6)->minutes();
        $response = $this->getJson($this->private, $this->auth)->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertStringNotContainsString('Foreign secret', $response->getContent());
        $this->getJson('/api/v1/stores/'.$other->id.'/missed-orders', $this->auth)->assertForbidden();
        $this->getJson($this->private)->assertUnauthorized();
        $this->getJson($this->public.'/checkout-leads')->assertNotFound();
    }

    public function test_review_status_updates_are_atomic_versioned_and_never_create_orders(): void
    {
        $first = $this->capture();
        $second = $this->capture(['lead_token' => (string) Str::uuid(), 'customer_name' => 'Second']);
        $this->travel(6)->minutes();
        $this->postJson($this->private.'/bulk-status', ['status' => 'completed', 'leads' => [['id' => $first->id, 'version' => 1], ['id' => $second->id, 'version' => 99]]], $this->auth)->assertConflict();
        $this->assertSame('under_review', $first->fresh()->review_status);
        $this->postJson($this->private.'/bulk-status', ['status' => 'completed', 'leads' => [['id' => $first->id, 'version' => '1'], ['id' => $second->id, 'version' => 1]]], $this->auth)->assertOk();
        $this->getJson($this->private.'?status=completed', $this->auth)->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame(2, $first->fresh()->edit_version);
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_filters_pagination_validation_and_populated_migration_rollback_guard(): void
    {
        $this->capture(['customer_name' => 'Reader %_!', 'source' => 'funnel', 'source_path' => '/funnels/bag']);
        $this->capture(['lead_token' => (string) Str::uuid(), 'customer_name' => 'Plain']);
        $this->travel(6)->minutes();
        $this->getJson($this->private.'?search=%25_!&source=funnel', $this->auth)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->private.'?per_page=1&page=2', $this->auth)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->getJson($this->private.'?date_to='.now()->format('Y-m-d'), $this->auth)->assertOk();
        $this->getJson($this->private.'?date_from=2026-10-12&date_to=2026-10-01', $this->auth)->assertUnprocessable();
        $this->postJson($this->public.'/checkout-leads', $this->payload(['sequence' => 2, 'source_path' => 'https://other.test/', 'shipping_address' => ['card' => 'never capture']]))->assertUnprocessable();
        $migration = require database_path('migrations/2026_10_12_020000_create_store_checkout_leads.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve populated leads');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Preserve', $error->getMessage());
        }
        $this->assertDatabaseCount('store_checkout_leads', 2);
    }
}
