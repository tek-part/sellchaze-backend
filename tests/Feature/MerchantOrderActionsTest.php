<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderWebhook;
use App\Models\StoreOrderWebhookDelivery;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantOrderActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private StoreOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Actions fixture', 'slug' => 'actions-fixture', 'status' => 'active', 'currency' => 'USD']);
        $this->order = StoreOrder::create(['store_id' => $this->store->id, 'order_number' => 'ACT-1', 'status' => 'pending', 'currency' => 'USD', 'customer_name' => 'Fixture customer', 'customer_phone' => '+201000000001', 'customer_email' => 'customer@example.test', 'subtotal' => '25.00', 'grand_total' => '25.00', 'payment_method' => 'cod', 'payment_status' => 'pending']);
    }

    private function url(string $action): string
    {
        return '/api/v1/stores/'.$this->store->id.'/orders/'.$this->order->id.'/'.$action;
    }

    public function test_review_is_private_versioned_and_does_not_mutate_payment_inventory_or_order_lifecycle(): void
    {
        $before = $this->order->fresh()->getAttributes();
        $this->postJson($this->url('review'), ['classification' => 'high_risk', 'version' => 0, 'note' => 'Private fixture review'])->assertOk()->assertJsonPath('data.review.version', 1)->assertJsonPath('data.status', 'pending');
        $this->postJson($this->url('review'), ['classification' => 'spam', 'version' => 0])->assertStatus(409);
        $this->getJson($this->url('detail'))->assertOk()->assertJsonPath('data.review.classification', 'high_risk');
        $this->postJson($this->url('review'), ['classification' => 'spam', 'version' => 1])->assertOk()->assertJsonPath('data.review.version', 2);
        $this->postJson($this->url('review'), ['classification' => null, 'version' => 2])->assertOk()->assertJsonPath('data.review.classification', null);
        $this->assertSame($before, $this->order->fresh()->getAttributes());
        $this->assertDatabaseHas('store_order_status_changes', ['actor_id' => $this->owner->id, 'source' => 'merchant_review_high_risk', 'notes' => 'Private fixture review']);
        $this->assertDatabaseCount('store_inventory_movements', 0);
        $this->assertDatabaseCount('store_payment_transactions', 0);
        $this->assertDatabaseCount('platform_wallet_entries', 0);
    }

    public function test_archive_keeps_records_and_restores_without_requeue_or_billing(): void
    {
        $expected = $this->order->updated_at->toIso8601String();
        $this->postJson($this->url('archive'), ['archived' => true, 'expected_updated_at' => $expected])->assertUnprocessable();
        $this->order->update(['status' => 'cancelled']);
        $expected = $this->order->fresh()->updated_at->toIso8601String();
        $this->postJson($this->url('archive'), ['archived' => true, 'expected_updated_at' => '2000-01-01T00:00:00Z'])->assertStatus(409);
        $response = $this->postJson($this->url('archive'), ['archived' => true, 'expected_updated_at' => $expected])->assertOk();
        $this->assertNotNull($response->json('data.archived_at'));
        $this->getJson('/api/v1/stores/'.$this->store->id.'/orders/'.$this->order->id)->assertNotFound();
        $this->getJson($this->url('detail'))->assertOk();
        $this->postJson($this->url('archive'), ['archived' => false, 'expected_updated_at' => $response->json('data.updated_at')])->assertOk()->assertJsonPath('data.archived_at', null)->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseCount('store_order_status_changes', 2);
        $this->assertDatabaseCount('platform_wallet_entries', 0);
    }

    public function test_resend_uses_current_hook_and_immutable_snapshots_and_replays_once_even_with_case_changes(): void
    {
        $key = (string) Str::uuid();
        $this->postJson($this->url('resend-webhook'), ['request_key' => $key])->assertUnprocessable();
        $hook = StoreOrderWebhook::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'request_key' => Str::uuid(), 'url' => 'https://hooks.example.test/orders', 'event_type' => 'orders', 'secret' => 'test-only-secret', 'enabled' => true, 'version' => 1]);
        $this->postJson($this->url('resend-webhook'), ['request_key' => $key])->assertStatus(202)->assertJsonPath('deliveries', 1);
        $this->postJson($this->url('resend-webhook'), ['request_key' => strtoupper($key)])->assertStatus(202)->assertJsonPath('deliveries', 1);
        $this->assertDatabaseCount('store_order_webhook_deliveries', 1);
        $row = StoreOrderWebhookDelivery::firstOrFail();
        $this->assertSame($hook->id, $row->webhook_id);
        $this->assertSame('Fixture customer', $row->payload['full_name']);
        $this->assertSame('queued', $row->status);
        $this->assertDatabaseCount('store_order_status_changes', 1);
        $this->order->update(['status' => 'cancelled']);
        $this->postJson($this->url('archive'), ['archived' => true, 'expected_updated_at' => $this->order->fresh()->updated_at->toIso8601String()])->assertOk();
        $this->assertSame('skipped', $row->fresh()->status);
        $this->assertNull($row->fresh()->payload);
        $this->postJson($this->url('resend-webhook'), ['request_key' => Str::uuid()])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_phone_block_is_store_scoped_and_idempotent_and_rejects_unblock_overwrite(): void
    {
        $this->postJson($this->url('block-phone'), ['note' => 'Fixture reason'])->assertOk();
        $this->postJson($this->url('block-phone'), ['note' => 'Repeated'])->assertOk();
        $this->assertDatabaseCount('store_phone_blocks', 1);
        $this->assertDatabaseCount('store_phone_block_events', 1);
        $this->assertDatabaseCount('store_order_status_changes', 1);
        DB::table('store_phone_blocks')->update(['active' => false]);
        $this->postJson($this->url('block-phone'), [])->assertStatus(409);
        $this->assertSame('pending', $this->order->fresh()->status);
    }

    public function test_foreign_store_and_inactive_actor_cannot_read_or_mutate_actions(): void
    {
        $foreign = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Foreign', 'slug' => 'actions-foreign', 'status' => 'active']);
        $this->order->update(['store_id' => $foreign->id]);
        $this->getJson($this->url('detail'))->assertNotFound();
        $this->postJson($this->url('review'), ['classification' => 'spam', 'version' => 0])->assertNotFound();
        $this->assertDatabaseCount('store_order_reviews', 0);
        $this->owner->update(['is_active' => false]);
        $this->getJson($this->url('detail'))->assertForbidden();
    }
}
